<?php

namespace App\Services\Billing;

use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\ScheduledServiceChange;
use App\Models\ServiceEvent;

/**
 * Turns scheduled changes that fall inside a period's usage month into service events.
 */
class ScheduledChangeApplier
{
    /**
     * @return array{applied: int, waiting: list<string>} waiting lists SIDs that are not in the period yet
     */
    public function apply(InvoicePeriod $period): array
    {
        if ($period->isLocked()) {
            return ['applied' => 0, 'waiting' => []];
        }
        $due = ScheduledServiceChange::where('status', ScheduledServiceChange::STATUS_SCHEDULED)
            ->whereDate('effective_date', '>=', $period->start_date->toDateString())
            ->whereDate('effective_date', '<=', $period->end_date->toDateString())
            ->orderBy('effective_date')->orderBy('id')->get();
        if ($due->isEmpty()) {
            return ['applied' => 0, 'waiting' => []];
        }
        $lines = $period->lines()->with('bankJatimLink')->get()->keyBy(fn ($line) => $line->billingSid());
        $applied = [];
        $waiting = [];
        foreach ($due as $change) {
            $line = $lines->get($change->sid);
            if (! $line && $change->event_type === 'pasang_baru' && $master = BankJatimLink::where('ssid', $change->sid)->first()) {
                $line = PeriodInventory::add($period, $master);
                $line && $lines->put($change->sid, $line);
            }
            if (! $line) {
                $waiting[] = $change->sid;

                continue;
            }
            $event = ServiceEvent::create([
                'invoice_period_id' => $period->id, 'sid' => $change->sid, 'event_type' => $change->event_type,
                'effective_date' => $change->effective_date->toDateString(), 'attributes' => $change->getAttribute('attributes') ?? [],
                'source' => ['origin' => 'scheduled', 'scheduled_change_id' => $change->id, 'user_id' => $change->created_by, 'recorded_at' => now()->toIso8601String()],
            ]);
            $change->update(['status' => ScheduledServiceChange::STATUS_APPLIED, 'applied_period_id' => $period->id, 'applied_event_id' => $event->id]);
            $applied[] = ['scheduled_change_id' => $change->id, 'sid' => $change->sid, 'event_type' => $change->event_type];
        }
        if ($applied) {
            InvoiceLine::regroup($period);
            $period->markInputsChanged('scheduled_changes_applied', ['changes' => $applied]);
        }

        return ['applied' => count($applied), 'waiting' => array_values(array_unique($waiting))];
    }
}
