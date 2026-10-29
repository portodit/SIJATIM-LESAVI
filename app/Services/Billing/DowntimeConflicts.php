<?php

namespace App\Services\Billing;

use App\Models\InvoicePeriod;
use App\Models\OutageEvent;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A service may have only one downtime source per period. These helpers find
 * competing records and let the operator keep one (the others become "excluded",
 * never deleted, so the choice stays auditable and can be undone).
 */
class DowntimeConflicts
{
    /** Prefix of the import-row error that marks a conflict (the preview page looks for it). */
    public const ERROR_PREFIX = 'Bentrok:';

    /**
     * Records that count toward SLA: not excluded and not ticket evidence.
     */
    public static function query(InvoicePeriod $period): HasMany
    {
        return $period->outages()->where('review_status', '!=', 'excluded')->where('kind', '!=', 'evidence');
    }

    public static function active(InvoicePeriod $period, string $sid): Collection
    {
        return self::query($period)->where('sid', $sid)->orderBy('id')->get();
    }

    /**
     * @param  array<int, string>  $batchNames  import batch id => file name
     * @return array{id: int, value: string, source: string}
     */
    public static function describe(OutageEvent $outage, array $batchNames = []): array
    {
        $origin = $outage->source['origin'] ?? null;
        $source = match (true) {
            $outage->import_batch_id !== null => 'dari file '.($batchNames[$outage->import_batch_id] ?? '#'.$outage->import_batch_id),
            $origin === 'operator_manual' => 'diisi manual',
            $origin === 'operator_bulk_zero' => 'ditandai tanpa downtime',
            default => 'sumber lain',
        };
        $value = $outage->kind === 'aggregate'
            ? number_format((int) $outage->duration_seconds, 0, ',', '.').' detik'
            : 'interval '.$outage->opened_at?->format('d/m H:i').' – '.$outage->closed_at?->format('d/m H:i');
        if ($outage->review_status === 'pending') {
            $value .= ' (belum direview)';
        }

        return ['id' => $outage->id, 'value' => $value, 'source' => $source.($outage->created_at ? ' · '.$outage->created_at->format('d/m/Y H:i') : '')];
    }

    /**
     * The calculator rejects an aggregate whose coverage reaches outside the billable window,
     * which happens when a PSB/Cabut narrows the window after the downtime was entered.
     *
     * @param  array{start: string, end: string}|null  $window
     */
    public static function outsideWindow(OutageEvent $outage, ?array $window): bool
    {
        if (! $window || $outage->kind !== 'aggregate' || ! $outage->coverage_start || ! $outage->coverage_end) {
            return false;
        }

        return $outage->coverage_start->lt(Carbon::parse($window['start'])->startOfDay())
            || $outage->coverage_end->gt(Carbon::parse($window['end'])->addDay()->startOfDay());
    }

    /**
     * After a PSB/Cabut changes the billable window, a confirmed "0 detik" is still true for the
     * shorter window, so its coverage follows the window. A downtime that has seconds is left
     * alone: the operator has to re-enter it because the outage may fall outside the new window.
     */
    public static function realign(InvoicePeriod $period, string $sid): bool
    {
        $rows = self::active($period, $sid);
        $outage = $rows->count() === 1 ? $rows->first() : null;
        if (! $outage || $outage->kind !== 'aggregate' || $outage->review_status !== 'eligible' || (int) $outage->duration_seconds !== 0) {
            return false;
        }
        $line = PeriodInventory::findLine($period, $sid);
        if (! $line) {
            return false;
        }
        $window = ActiveWindow::for($period, $line->service_snapshot ?? InvoiceCalculator::serviceSnapshot($line), $period->serviceEvents()->where('sid', $sid)->orderBy('effective_date')->get());
        if (! self::outsideWindow($outage, $window)) {
            return false;
        }
        $outage->update([
            'coverage_start' => $window['start'].' 00:00:00',
            'coverage_end' => Carbon::parse($window['end'])->addDay()->startOfDay(),
            'source' => array_merge($outage->source ?? [], ['realigned' => [
                'from' => [$outage->coverage_start?->toDateString(), $outage->coverage_end?->toDateString()],
                'to' => [$window['start'], $window['end']], 'at' => now()->toIso8601String(),
            ]]),
        ]);

        return true;
    }

    /**
     * Mark records as not counted. $by names what replaced them (batch_id, or origin 'operator').
     */
    public static function supersede(Collection $rows, string $reason, array $by): void
    {
        foreach ($rows as $row) {
            $row->update([
                'review_status' => 'excluded', 'reason' => $reason, 'reviewed_by' => auth()->id(),
                'source' => array_merge($row->source ?? [], ['superseded' => $by + ['previous_review_status' => $row->review_status, 'at' => now()->toIso8601String()]]),
            ]);
        }
    }

    /**
     * Undo supersede() for records replaced by an import batch that is being withdrawn.
     */
    public static function restoreSupersededBy(InvoicePeriod $period, int $batchId): int
    {
        $restored = 0;
        foreach ($period->outages()->where('review_status', 'excluded')->get() as $row) {
            $superseded = $row->source['superseded'] ?? null;
            if (($superseded['batch_id'] ?? null) !== $batchId) {
                continue;
            }
            $source = $row->source;
            unset($source['superseded']);
            $row->update(['review_status' => $superseded['previous_review_status'] ?? 'eligible', 'reason' => 'Dipulihkan karena file pengganti ditarik.', 'source' => $source]);
            $restored++;
        }

        return $restored;
    }
}
