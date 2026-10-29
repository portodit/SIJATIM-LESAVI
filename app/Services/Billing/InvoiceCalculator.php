<?php

namespace App\Services\Billing;

use App\Models\BandwidthOption;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\InvoicePrice;
use App\Models\InvoiceRestitution;
use App\Models\InvoiceSla;
use App\Models\InvoiceSummary;
use App\Models\LocationCategory;
use App\Models\PriceMaster;
use App\Models\Service;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceCalculator
{
    public const COMPONENTS = ['existing', 'upgrade', 'downgrade', 'termination', 'installation', 'relocation'];

    /** Names looked up for issue details, loaded once per calculation. */
    private array $names = [];

    public function calculate(InvoicePeriod $period, bool $persist = true): array
    {
        return DB::transaction(function () use ($period, $persist) {
            $period = InvoicePeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($persist) {
                abort_if($period->isLocked(), 423, 'Invoice sudah terkunci.');
                // Every line sits at the end of its own branch, also lines added before grouping existed or reopened by a revision.
                InvoiceLine::regroup($period);
            }
            $settings = $period->billingProfile?->settings;
            $issues = [];
            if (! $settings) {
                $issues[] = $this->issue('missing_profile', null, 'Profil billing belum dipilih.');
            }
            $settings ??= ['sla_target' => '0.99', 'proration_divisor' => 30, 'tax_rate' => TaxRate::DEFAULT];
            $settings['sla_target'] = BillingMath::SLA_TARGET;
            $resolver = new TariffResolver;
            $bandwidths = BandwidthOption::all()->keyBy('id');
            $events = $period->serviceEvents()->orderBy('effective_date')->orderBy('id')->get()->groupBy('sid');
            $outages = $period->outages()->get()->groupBy('sid');
            $adjustments = $period->adjustments()->get()->groupBy('sid');
            $lines = [];
            $models = $period->lines()->with(['bankJatimLink.officeType', 'bankJatimLink.locationCategory', 'bankJatimLink.service', 'existingBandwidth', 'typeOrder', 'trafficUsage'])->orderBy('line_number')->get();
            if ($models->isEmpty()) {
                $issues[] = $this->issue('empty_inventory', null, 'Belum ada layanan untuk periode ini.');
            }
            $seen = [];
            foreach ($models as $model) {
                $s = $model->service_snapshot ?? self::serviceSnapshot($model);
                $sid = $s['sid'] ?? $model->ssid_temp;
                if (! $sid || ! $model->bank_jatim_link_id) {
                    $issues[] = $this->issue('unknown_sid', $sid, 'Identitas layanan belum dicocokkan dengan master.', $model->id);
                }
                if (isset($seen[$sid])) {
                    $issues[] = $this->issue('duplicate_sid', $sid, 'SID muncul lebih dari sekali dalam periode.', $model->id);
                }
                $seen[$sid] = true;
                $lineEvents = ($events[$sid] ?? collect())->toArray();
                $lineAdjustments = ($adjustments[$sid] ?? collect())->toArray();
                $line = $this->line($model, $s, $period, $settings, $lineEvents, ($outages[$sid] ?? collect())->toArray(), $lineAdjustments, $resolver, $bandwidths);
                array_push($issues, ...$line['issues']);
                $lines[] = $line;
                if ($persist) {
                    $this->projectLine($model, $line);
                }
            }
            array_push($issues, ...$period->pendingSourceIssues());
            $totals = $this->totals($lines, $settings);
            $sourceRows = ImportRow::whereIn('import_batch_id', $period->importBatches()->whereNotNull('committed_at')->pluck('id'))->where('status', 'valid')->whereIn('kind', ['notes', 'reference'])->get();
            foreach ($sourceRows->where('kind', 'reference') as $claim) {
                $key = $claim->normalized['total_key'] ?? null;
                $value = $claim->normalized['amount'] ?? null;
                if ($key && $value !== null && ($totals[$key] ?? null) !== null && ! BigDecimal::of($value)->isEqualTo($totals[$key])) {
                    $issues[] = $this->issue('source_discrepancy', null, $claim->sheet.' baris '.$claim->row_number.': total sumber '.$value.' berbeda dengan '.$key.' '.$totals[$key].'.', null, 'comparison');
                }
            }
            $previous = $period->previousApprovedRevision();
            $previousLines = collect($previous?->snapshot['lines'] ?? [])->keyBy('sid');
            $changes = [];
            foreach ($lines as $row) {
                $old = $previousLines->get($row['sid']);
                if (! $old || $old['net'] !== $row['net'] || $old['bandwidth'] !== $row['bandwidth'] || $old['address'] !== $row['address'] || $row['events']) {
                    $changes[] = ['sid' => $row['sid'], 'type' => $old ? 'changed' : 'added', 'previous_net' => $old['net'] ?? null, 'current_net' => $row['net'], 'previous_bandwidth' => $old['bandwidth'] ?? null, 'current_bandwidth' => $row['bandwidth'], 'events' => $row['events']];
                }
                $previousLines->forget($row['sid']);
            }
            foreach ($previousLines as $old) {
                $changes[] = ['sid' => $old['sid'], 'type' => 'removed', 'previous_net' => $old['net'], 'current_net' => null, 'previous_bandwidth' => $old['bandwidth'], 'current_bandwidth' => null, 'events' => []];
            }
            $result = [
                'schema_version' => 1, 'input_version' => $period->input_version, 'calculated_at' => now()->toIso8601String(),
                'period' => ['id' => $period->id, 'code' => $period->period_code, 'name' => $period->period_name, 'month' => $period->month, 'year' => $period->year, 'usage_start' => $period->start_date->toDateString(), 'usage_end' => $period->end_date->toDateString(), 'seconds' => $period->billing_days * 86400, 'billing_month_days' => BillingMath::monthDays($period->start_date)],
                'profile' => ['id' => $period->billing_profile_id, 'version' => $period->billingProfile?->version, 'settings' => $settings],
                'metadata' => array_merge($settings, $period->invoice_metadata ?? []), 'lines' => $lines, 'totals' => $totals, 'issues' => $issues,
                'changes' => $previous ? $changes : [], 'previous_revision_id' => $previous?->id,
                'previous_total' => $previous?->snapshot['totals']['grand_total'] ?? null,
                'variance' => $previous && $totals['grand_total'] !== null ? BillingMath::money(BigDecimal::of($totals['grand_total'])->minus($previous->snapshot['totals']['grand_total'])) : null,
                'imports' => $period->importBatches()->whereNotNull('committed_at')->get(['id', 'import_type', 'original_filename', 'file_hash', 'metadata'])->toArray(),
                'outages' => $period->outages()->get()->toArray(), 'adjustments' => $period->adjustments()->get()->toArray(),
                'sla_coverage' => $period->sla_coverage, 'tariffs' => PriceMaster::with('contract')->where('is_active', true)->get()->toArray(),
                'source_notes' => $sourceRows->where('kind', 'notes')->map(fn ($r) => ['sheet' => $r->sheet, 'row' => $r->row_number, 'note' => $r->normalized['note'] ?? ''])->values()->toArray(),
                'reference_comparisons' => $sourceRows->where('kind', 'reference')->toArray(),
            ];
            if ($persist) {
                $period->update(['calculation' => $result, 'calculated_input_version' => $period->input_version, 'calculated_at' => now(), 'status' => 'validation']);
                $this->projectSummary($period, $result);
            }

            return $result;
        });
    }

    public static function serviceSnapshot(InvoiceLine $line): array
    {
        $s = $line->bankJatimLink;

        return [
            'sid' => $s?->ssid ?? $line->ssid_temp, 'branch' => $s?->officeType?->office_type_name ?? '-',
            'address' => $line->address_snapshot ?? $s?->address, 'category' => $s?->locationCategory?->location_category_name,
            'service' => $s?->service?->service_name, 'location_category_id' => $s?->location_category_id,
            'service_id' => $s?->service_id, 'bandwidth_option_id' => $line->existing_bandwidth_option_id ?? $s?->current_bandwidth_option_id,
            'ip' => $s?->ip_host_telkom, 'activation_date' => $s?->service_start_date?->toDateString(),
            'billing_start_date' => $s?->billing_start_date?->toDateString(), 'billing_end_date' => $s?->billing_end_date?->toDateString(),
            'physical_router_status' => $s?->physical_router_status, 'router_mode' => $s?->billable_router_status ?? 'none',
            'contract_id' => $s?->addendum_contract_id,
            'router_billing_from' => $s?->router_billing_from?->toDateString(),
        ];
    }

    private function line(InvoiceLine $model, array $s, InvoicePeriod $p, array $settings, array $events, array $outages, array $adjustments, TariffResolver $resolver, $bandwidths): array
    {
        // Keep the period's starting attributes stable across repeated calculations.
        $initialService = $s;
        $sid = $s['sid'] ?? $model->ssid_temp;
        $issues = [];
        $charges = array_fill_keys(self::COMPONENTS, '0.00');
        $rates = [];
        $start = $p->start_date->copy()->startOfDay();
        $end = $p->end_date->copy()->startOfDay();
        $activeStart = ! empty($s['billing_start_date']) ? max($start, Carbon::parse($s['billing_start_date'])->startOfDay()) : $start->copy();
        $activeEnd = ! empty($s['billing_end_date']) ? min($end, Carbon::parse($s['billing_end_date'])->startOfDay()) : $end->copy();
        // Billing days use a fixed 30-day month (February 28); SLA below keeps real calendar seconds.
        $monthDays = BillingMath::monthDays($start);
        $psbDate = null;
        $dates = [];
        $boundaries = [$start->toDateString(), $end->copy()->addDay()->toDateString()];
        foreach ($events as $event) {
            $date = Carbon::parse($event['effective_date'])->startOfDay();
            if ($date->lt($start) || $date->gt($end)) {
                $issues[] = $this->issue('event_period', $sid, 'Tanggal perubahan berada di luar periode pemakaian.', $model->id);

                continue;
            }
            $dates[$date->toDateString()][] = $event;
            if ($event['event_type'] === 'pasang_baru') {
                $activeStart = max($activeStart, $date);
                $psbDate = max($psbDate ?? $date, $date);
            }
            if ($event['event_type'] === 'pemutusan') {
                $activeEnd = min($activeEnd, $date);
            }
            $boundaries[] = $date->toDateString();
            if ($event['event_type'] === 'pemutusan') {
                $boundaries[] = $date->copy()->addDay()->toDateString();
            }
        }
        if ($model->typeOrder && ! in_array($model->typeOrder->type_order_code, ['existing', 'multiple_changes']) && ! $events) {
            $issues[] = $this->issue('missing_event', $sid, 'Type order membutuhkan sumber perubahan dan tanggal efektif.', $model->id);
        }
        foreach ($dates as $on => $sameDay) {
            if (count($sameDay) > 1) {
                $issues[] = $this->issue('event_conflict', $sid, 'Beberapa perubahan pada '.$on.' harus direkonsiliasi.', $model->id);
            }
        }
        if ($activeStart->gt($activeEnd)) {
            $issues[] = $this->issue('not_billable', $sid, 'Layanan tidak aktif dalam periode. Periksa daftar layanan.', $model->id);
        }
        $boundaries[] = $activeStart->toDateString();
        $boundaries[] = $activeEnd->copy()->addDay()->toDateString();
        array_push($boundaries, ...$resolver->boundaries($start, $end, $s));
        if (! empty($s['router_billing_from'])) {
            $boundaries[] = Carbon::parse($s['router_billing_from'])->toDateString();
        }
        $boundaries = array_values(array_unique(array_filter($boundaries, fn ($d) => $d >= $start->toDateString() && $d <= $end->copy()->addDay()->toDateString())));
        sort($boundaries);
        $bw = $s['bandwidth_option_id'] ?? null;
        $component = 'existing';
        $changedBw = null;
        $priceKnown = true;
        $overrides = array_values(array_filter($adjustments, fn ($a) => $a['kind'] === 'tariff_override'));
        $termination = count(array_filter($events, fn ($e) => $e['event_type'] === 'pemutusan')) > 0;
        $feeEvents = [];
        $segments = [];
        foreach ($boundaries as $i => $on) {
            foreach ($dates[$on] ?? [] as $event) {
                if (in_array($event['event_type'], ['upgrade', 'downgrade'])) {
                    $bw = $event['attributes']['bandwidth_option_id'] ?? null;
                    $changedBw = $bw;
                    $component = $event['event_type'];
                    if (! $bw) {
                        $issues[] = $this->issue('missing_bandwidth', $sid, 'Bandwidth perubahan belum dipilih.', $model->id);
                    }
                }
                if ($event['event_type'] === 'relokasi' && ! empty($event['attributes']['new_address'])) {
                    $s['address'] = $event['attributes']['new_address'];
                }
                if (in_array($event['event_type'], ['relokasi', 'pasang_baru']) && ! empty($event['attributes']['bandwidth_option_id'])) {
                    $bw = $event['attributes']['bandwidth_option_id'];
                }
                foreach (['service_id', 'location_category_id', 'router_mode', 'contract_id'] as $attribute) {
                    if (array_key_exists($attribute, $event['attributes'])) {
                        $s[$attribute] = $event['attributes'][$attribute];
                    }
                }
                if (in_array($event['event_type'], ['pasang_baru', 'relokasi'])) {
                    $event['billing_bandwidth'] = $bw;
                    $event['billing_service'] = $s;
                    $feeEvents[] = $event;
                }
            }
            if (! isset($boundaries[$i + 1])) {
                continue;
            }
            $segmentStart = max(Carbon::parse($on), $activeStart);
            $segmentEnd = min(Carbon::parse($boundaries[$i + 1])->subDay(), $activeEnd);
            if ($segmentStart->gt($segmentEnd)) {
                continue;
            }
            $router = ! empty($s['router_billing_from']) && $segmentStart->lt(Carbon::parse($s['router_billing_from'])) ? 'none' : ($s['router_mode'] ?? 'none');
            $resolved = $resolver->resolve($s, $bw, $router, $segmentStart, $overrides);
            if (isset($resolved['error'])) {
                $issues[] = $this->issue('tariff', $sid, $resolved['error'], $model->id, 'blocking', null, $this->missingTariffDetail($s, $bw, $router, $segmentStart, $bandwidths));
                $priceKnown = false;

                continue;
            }
            $rate = $resolved['rate'];
            // PSB is billed from H+1; a master billing start date counts from its own day.
            $psb = $psbDate !== null && $activeStart->eq($psbDate) && $segmentStart->eq($psbDate);
            $rates[] = ['start' => $segmentStart->toDateString(), 'end' => $segmentEnd->toDateString(), 'billing_days' => BillingMath::billingDays($segmentStart, $segmentEnd, $psb), 'month_days' => $monthDays, 'rate' => $rate, 'bandwidth_option_id' => $bw];
            $key = $termination && $component === 'existing' ? 'termination' : $component;
            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last]['component'] === $key && BigDecimal::of($segments[$last]['rate'])->isEqualTo($rate['monthly_price']) && $segments[$last]['end']->copy()->addDay()->eq($segmentStart)) {
                $segments[$last]['end'] = $segmentEnd;
            } else {
                $segments[] = ['start' => $segmentStart, 'end' => $segmentEnd, 'component' => $key, 'rate' => $rate['monthly_price'], 'psb' => $psb];
            }
        }
        foreach ($segments as $segment) {
            $amount = BillingMath::segmentAmount((string) $segment['rate'], BillingMath::billingDays($segment['start'], $segment['end'], $segment['psb']), $monthDays);
            $key = $segment['component'];
            $charges[$key] = BillingMath::sum([$charges[$key], $amount]);
        }
        foreach ($feeEvents as $event) {
            $kind = $event['event_type'] === 'pasang_baru' ? 'installation' : 'relocation';
            $feeService = $event['billing_service'];
            $feeRate = $resolver->resolve($feeService, $event['billing_bandwidth'], $feeService['router_mode'] ?? 'none', Carbon::parse($event['effective_date']), $overrides);
            $fee = $event['attributes']['fee'] ?? ($feeRate['rate'][$kind === 'installation' ? 'installation_price' : 'relocation_price'] ?? null);
            if ($fee === null) {
                $issues[] = $this->issue('missing_fee', $sid, 'Biaya '.$kind.' belum ditetapkan (nol harus eksplisit).', $model->id);
                $priceKnown = false;
            } else {
                $charges[$kind] = BillingMath::sum([$charges[$kind], $fee]);
            }
        }
        $gross = $priceKnown ? BillingMath::sum($charges) : null;
        if ($gross !== null && isset($s['reference_price']) && $s['reference_price'] !== null && count($events) === 0 && ! BigDecimal::of($s['reference_price'])->isEqualTo($gross) && ! $overrides) {
            $issues[] = $this->issue('tariff_discrepancy', $sid, 'Tarif sumber '.$s['reference_price'].' berbeda dengan register '.$gross.'. Catat override atau rekonsiliasi sumber.', $model->id, 'blocking', null, $this->priceDetail($s, $bw, $rates, (string) $s['reference_price'], $gross, $start, $resolver, $bandwidths, $model->bankJatimLink?->current_bandwidth_option_id));
        }
        if ($gross !== null && collect($events)->contains('event_type', 'pasang_baru') && ($s['reference_price'] ?? null) !== null && $rates && ! BigDecimal::of($s['reference_price'])->isEqualTo($rates[0]['rate']['monthly_price']) && ! $overrides) {
            $issues[] = $this->issue('tariff_discrepancy', $sid, 'Tarif instalasi pada sumber berbeda dengan register. Catat penyelesaian atau override periode.', $model->id, 'blocking', null, $this->priceDetail($s, $bw, $rates, (string) $s['reference_price'], (string) $rates[0]['rate']['monthly_price'], $start, $resolver, $bandwidths, $model->bankJatimLink?->current_bandwidth_option_id));
        }
        if (! empty($s['reference_missing']) && ! $overrides) {
            $issues[] = $this->issue('reference_missing', $sid, 'Harga eksisting di file Telkom kosong; harga sistem '.($gross ?? '-').' dipakai tanpa pembanding.', $model->id, 'comparison');
        }
        $down = self::eligibleDowntime($outages, $activeStart, $activeEnd->copy()->addDay(), $p->sla_coverage ?? [], $sid);
        if ($down['error']) {
            $issues[] = $this->issue('sla_coverage', $sid, $down['error'], $model->id);
        }
        $seconds = $activeStart->gt($activeEnd) ? 0 : ((int) $activeStart->diffInDays($activeEnd) + 1) * 86400;
        if ($down['seconds'] !== null && $down['seconds'] > $seconds) {
            $issues[] = $this->issue('invalid_downtime', $sid, 'Durasi downtime melebihi jendela layanan aktif.', $model->id);
            $down['seconds'] = null;
        }
        $sla = $down['seconds'] === null || $seconds === 0 ? null : BillingMath::sla($down['seconds'], $seconds);
        $slaCredit = $sla !== null && $gross !== null ? BillingMath::slaCredit($gross, $down['seconds'], $seconds, (string) $settings['sla_target']) : null;
        $manual = BillingMath::sum(array_column(array_filter($adjustments, fn ($a) => $a['kind'] === 'manual_credit'), 'amount'));
        $net = $gross !== null && $slaCredit !== null ? BillingMath::money(BigDecimal::of($gross)->minus($slaCredit)->minus($manual)) : null;
        if ($net !== null && BigDecimal::of($net)->isLessThan(0)) {
            $issues[] = $this->issue('excess_credit', $sid, 'Kredit melebihi tagihan. Periksa penyesuaian.', $model->id);
        }
        $traffic = $model->trafficUsage;
        $average = null;
        $volume = null;
        $fullTrafficCoverage = $traffic && $traffic->coverage_start?->lte($start) && $traffic->coverage_end?->gte($end);
        if ($traffic && $traffic->inbound_kbps !== null && $traffic->outbound_kbps !== null) {
            $average = (string) BigDecimal::of($traffic->inbound_kbps)->plus($traffic->outbound_kbps);
            $volume = $fullTrafficCoverage ? BillingMath::volume($average, $seconds) : null;
        }
        if ($average === null) {
            $issues[] = $this->issue('traffic_missing', $sid, 'Average MRTG periode ini belum tersedia; volume GB tidak ditampilkan.', $model->id, 'comparison', $model->traffic_exception_reason);
        } elseif (! $fullTrafficCoverage) {
            $issues[] = $this->issue('traffic_coverage', $sid, 'Cakupan MRTG belum lengkap; volume GB tidak tersedia.', $model->id, $model->traffic_exception_reason ? 'acknowledged' : 'comparison', $model->traffic_exception_reason);
        }
        $types = array_unique(array_column($events, 'event_type'));
        $resolvedService = $s;
        $resolvedService['bandwidth_option_id'] = $bw;
        foreach ($events as $event) {
            if ($event['event_type'] === 'pasang_baru') {
                $resolvedService['billing_start_date'] = substr($event['effective_date'], 0, 10);
            }
            if ($event['event_type'] === 'pemutusan') {
                $resolvedService['billing_end_date'] = substr($event['effective_date'], 0, 10);
            }
        }

        return [
            'line_id' => $model->id, 'number' => $model->line_number, 'sid' => $sid,
            'service_snapshot' => $initialService, 'resolved_service_snapshot' => $resolvedService, 'branch' => $s['branch'] ?? '-', 'address' => $s['address'] ?? '', 'category' => $s['category'] ?? '', 'service' => $s['service'] ?? '',
            'bandwidth' => $bandwidths->get($s['bandwidth_option_id'] ?? 0)?->bandwidth_name ?? '-',
            // Bandwidth after the month's upgrade/downgrade; null when the service kept its bandwidth.
            'bandwidth_change' => $changedBw ? ($bandwidths->get($changedBw)?->bandwidth_name ?? '-') : null,
            'order' => count($types) > 1 || count($events) > 1 ? 'MULTIPLE' : strtoupper($types[0] ?? 'existing'),
            'events' => $events, 'rates' => $rates, 'charges' => $priceKnown ? $charges : array_fill_keys(self::COMPONENTS, null), 'gross' => $gross,
            'downtime_seconds' => $down['seconds'], 'billing_seconds' => $seconds, 'sla' => $sla, 'sla_target' => (string) $settings['sla_target'],
            'sla_credit' => $slaCredit, 'manual_credit' => $manual, 'net' => $net,
            'traffic' => ['inbound_kbps' => $traffic?->inbound_kbps, 'outbound_kbps' => $traffic?->outbound_kbps, 'average_kbps' => $average, 'volume_gb' => $volume, 'coverage_start' => $traffic?->coverage_start?->toDateString(), 'coverage_end' => $traffic?->coverage_end?->toDateString(), 'covered_seconds' => $fullTrafficCoverage ? $seconds : null, 'units' => 'Kbps', 'volume_units' => 'decimal GB', 'source' => $traffic?->source, 'exception_reason' => $model->traffic_exception_reason],
            'issues' => $issues,
        ];
    }

    public static function eligibleDowntime(array $events, Carbon $start, Carbon $endExclusive, array $coverage, ?string $sid): array
    {
        $events = array_values(array_filter($events, fn ($e) => $e['kind'] !== 'evidence'));
        if (count(array_filter($events, fn ($e) => $e['review_status'] === 'pending'))) {
            return ['seconds' => null, 'error' => 'Masih ada downtime yang belum direview.'];
        }
        $eligible = array_filter($events, fn ($e) => $e['review_status'] === 'eligible');
        $aggregates = array_values(array_filter($eligible, fn ($e) => $e['kind'] === 'aggregate'));
        $intervals = array_values(array_filter($eligible, fn ($e) => $e['kind'] === 'interval'));
        if (count($aggregates) > 1 || ($aggregates && $intervals)) {
            return ['seconds' => null, 'error' => 'Pilih satu aggregate atau interval downtime, bukan keduanya.'];
        }
        if ($aggregates) {
            $aggregate = $aggregates[0];
            if (! empty($aggregate['coverage_start']) && ! empty($aggregate['coverage_end']) && (Carbon::parse($aggregate['coverage_start'])->lt($start) || Carbon::parse($aggregate['coverage_end'])->gt($endExclusive))) {
                return ['seconds' => null, 'error' => 'Downtime ini tercatat untuk masa aktif yang berbeda dari masa tagih sekarang (ada PSB/Cabut). Isi ulang downtime SSID ini di langkah 1.'];
            }

            return ['seconds' => (int) $aggregate['duration_seconds'], 'error' => null];
        }
        if (! in_array($sid, $coverage['sids'] ?? [], true)) {
            return ['seconds' => null, 'error' => 'Cakupan sumber SLA belum dikonfirmasi.'];
        }
        $ranges = [];
        foreach ($intervals as $e) {
            if (! $e['opened_at'] || ! $e['closed_at']) {
                return ['seconds' => null, 'error' => 'Waktu mulai/akhir downtime belum lengkap.'];
            }
            $a = max(Carbon::parse($e['opened_at'])->timestamp, $start->timestamp);
            $b = min(Carbon::parse($e['closed_at'])->timestamp, $endExclusive->timestamp);
            if ($b > $a) {
                $ranges[] = [$a, $b];
            }
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $seconds = 0;
        $last = null;
        foreach ($ranges as [$a, $b]) {
            if ($last && $a <= $last[1]) {
                $last[1] = max($last[1], $b);
            } else {
                if ($last) {
                    $seconds += $last[1] - $last[0];
                } $last = [$a, $b];
            }
        }
        if ($last) {
            $seconds += $last[1] - $last[0];
        }

        return ['seconds' => $seconds, 'error' => null];
    }

    private function issue(string $code, ?string $sid, string $message, ?int $line = null, string $severity = 'blocking', ?string $reason = null, ?array $detail = null): array
    {
        return ['code' => $code, 'sid' => $sid, 'line_id' => $line, 'message' => $message, 'severity' => $severity, 'reason' => $reason] + ($detail ? ['detail' => $detail] : []);
    }

    /**
     * Why a source (Telkom) price differs from the tariff the system used, in plain words, with the
     * tariffs whose price equals the source price (a different bandwidth or router usually explains it).
     */
    private function priceDetail(array $s, $bw, array $rates, string $reference, string $system, Carbon $date, TariffResolver $resolver, $bandwidths, ?int $masterBw = null): array
    {
        $rate = $rates[0]['rate'] ?? [];
        $router = $rate['router_mode'] ?? ($s['router_mode'] ?? 'none');
        $bandwidth = $bandwidths->get($bw)?->bandwidth_name ?? '-';
        $matches = array_map(fn ($m) => [
            'bandwidth' => $bandwidths->get($m['bandwidth_option_id'])?->bandwidth_name ?? 'Tidak berlaku', 'bandwidth_option_id' => $m['bandwidth_option_id'],
            'router' => $m['router_mode'], 'category' => $m['location_category_id'] ? $this->name('category', $m['location_category_id']) : 'Semua kategori',
        ], $resolver->withPrice($s, $reference, $date));
        $sameBandwidth = array_filter($matches, fn ($m) => (int) $m['bandwidth_option_id'] === (int) $bw);
        $money = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $tariff = $bandwidth.' '.self::routerLabel($router).' ('.$money($rate['monthly_price'] ?? $system).')';
        if ($sameBandwidth && ! array_filter($sameBandwidth, fn ($m) => $m['router'] === $router)) {
            $cause = 'router';
            $explanation = 'Harga Telkom '.$money($reference).' sama dengan tarif '.$bandwidth.' '.self::routerLabel(reset($sameBandwidth)['router']).'. Sistem memakai tarif '.$tariff.'. Periksa apakah SSID ini memakai router.';
        } elseif ($matches) {
            $cause = 'bandwidth';
            $explanation = 'Harga Telkom '.$money($reference).' sama dengan tarif '.implode(' / ', array_unique(array_map(fn ($m) => $m['bandwidth'].' '.self::routerLabel($m['router']), $matches))).'. Sistem memakai tarif '.$tariff.'. Kemungkinan bandwidth di file Telkom atau di sistem berbeda.';
        } else {
            $cause = 'no_tariff';
            $explanation = 'Tidak ada tarif sebesar '.$money($reference).' untuk '.($this->name('service', $s['service_id'] ?? null) ?? '-').' · '.($this->name('category', $s['location_category_id'] ?? null) ?? '-').' di Master Harga. Sistem memakai tarif '.$tariff.'.';
        }
        // Today's master bandwidth often settles it (a month's workbook can carry a stale bandwidth).
        $master = $masterBw && (int) $masterBw !== (int) $bw ? $bandwidths->get($masterBw)?->bandwidth_name : null;
        $masterMatches = $master !== null && array_filter($matches, fn ($m) => (int) $m['bandwidth_option_id'] === (int) $masterBw) !== [];
        if ($master !== null) {
            $explanation .= ' Di Master saat ini SSID ini '.$master.($masterMatches ? ', cocok dengan harga Telkom.' : '.');
        }
        $source = $s['source'] ?? [];

        return [
            'reference' => BillingMath::money($reference), 'system' => BillingMath::money($system), 'difference' => BillingMath::money(BigDecimal::of($reference)->minus($system)),
            'monthly' => isset($rate['monthly_price']) ? BillingMath::money($rate['monthly_price']) : null, 'tariff_id' => $rate['id'] ?? null,
            'bandwidth' => $bandwidth, 'category' => $this->name('category', $s['location_category_id'] ?? null), 'service' => $this->name('service', $s['service_id'] ?? null), 'router' => $router,
            'matches' => $matches, 'cause' => $cause, 'explanation' => $explanation,
            'master_bandwidth' => $master, 'master_bandwidth_option_id' => $master !== null ? (int) $masterBw : null, 'master_matches' => $masterMatches,
            'source' => array_filter(['file' => $this->batchName($source['batch_id'] ?? null), 'sheet' => $source['sheet'] ?? null, 'row' => $source['row'] ?? null]),
        ];
    }

    /** The combination for which no tariff exists, so the operator knows exactly what to add in Master Harga. */
    private function missingTariffDetail(array $s, $bw, string $router, Carbon $date, $bandwidths): array
    {
        $parts = [$this->name('service', $s['service_id'] ?? null) ?? 'layanan belum dipilih', $this->name('category', $s['location_category_id'] ?? null) ?? 'kategori belum dipilih', $bandwidths->get($bw)?->bandwidth_name ?? 'bandwidth belum dipilih', self::routerLabel($router)];

        return [
            'service' => $parts[0], 'category' => $parts[1], 'bandwidth' => $parts[2], 'router' => $router, 'date' => $date->toDateString(),
            'explanation' => 'Belum ada tarif untuk '.implode(' · ', $parts).' yang berlaku pada '.$date->format('d/m/Y').'. Tambahkan di Master Harga › Tarif.',
        ];
    }

    public static function routerLabel(?string $router): string
    {
        return $router === 'managed' ? 'dengan managed router' : 'tanpa router';
    }

    private function name(string $kind, $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $this->names[$kind] ??= $kind === 'category' ? LocationCategory::pluck('location_category_name', 'id')->all() : Service::pluck('service_name', 'id')->all();

        return $this->names[$kind][(int) $id] ?? null;
    }

    private function batchName($id): ?string
    {
        return $id ? ($this->names['batch'][$id] ??= ImportBatch::whereKey($id)->value('original_filename')) : null;
    }

    private function totals(array $lines, array $settings): array
    {
        $components = [];
        foreach (self::COMPONENTS as $k) {
            $components[$k] = BillingMath::sum(array_map(fn ($l) => $l['charges'][$k], $lines));
        }
        $known = count(array_filter($lines, fn ($l) => $l['net'] === null)) === 0;
        $grossKnown = count(array_filter($lines, fn ($l) => $l['gross'] === null)) === 0;
        if (! $grossKnown) {
            $components = array_fill_keys(self::COMPONENTS, null);
        }
        $exact = $known ? BillingMath::sum(array_column($lines, 'net')) : null;
        $subtotal = $exact !== null ? BillingMath::round($exact) : null;
        $tax = $subtotal !== null ? BillingMath::round((string) BigDecimal::of($subtotal)->multipliedBy(TaxRate::multiplier($settings['tax_rate'] ?? null))) : null;
        $branches = [];
        $orders = [];
        $below = 0;
        foreach ($lines as $l) {
            $branches[$l['branch']][] = $l;
            $types = $l['events'] ? array_unique(array_column($l['events'], 'event_type')) : ['existing'];
            foreach ($types as $type) {
                $orders[$type] = ($orders[$type] ?? 0) + 1;
            }
            if ($l['sla'] !== null && BigDecimal::of($l['sla'])->isLessThan($settings['sla_target'])) {
                $below++;
            }
        }
        $branchTotals = [];
        foreach ($branches as $branch => $rows) {
            $branchTotals[$branch] = ['count' => count($rows), 'gross' => count(array_filter($rows, fn ($l) => $l['gross'] === null)) ? null : BillingMath::sum(array_column($rows, 'gross')), 'net' => count(array_filter($rows, fn ($l) => $l['net'] === null)) ? null : BillingMath::sum(array_column($rows, 'net'))];
        }

        return ['service_count' => count($lines), 'components' => $components, 'gross' => $grossKnown ? BillingMath::sum($components) : null,
            'sla_credit' => $known ? BillingMath::sum(array_column($lines, 'sla_credit')) : null, 'manual_credit' => BillingMath::sum(array_column($lines, 'manual_credit')),
            'net_exact' => $exact, 'rounding' => $exact !== null ? BillingMath::money(BigDecimal::of($subtotal)->minus($exact)) : null,
            'subtotal' => $subtotal, 'tax' => $tax, 'grand_total' => $subtotal !== null ? BillingMath::sum([$subtotal, $tax]) : null,
            'branch_totals' => $branchTotals, 'order_counts' => $orders, 'below_sla_count' => $below];
    }

    private function projectLine(InvoiceLine $m, array $l): void
    {
        $map = ['existing' => 'price_existing', 'upgrade' => 'price_upgrade', 'downgrade' => 'price_downgrade', 'termination' => 'price_prorate', 'installation' => 'price_psb', 'relocation' => 'price_relocation'];
        $data = ['service_snapshot' => $l['service_snapshot'], 'price_total' => $l['gross'], 'price_after_restitution' => $l['net'], 'sla_actual' => $l['sla'], 'sla_target' => $l['sla_target'], 'restitution_manual' => $l['manual_credit'], 'restitution_over_sla' => $l['sla_credit'] ?? 0, 'restitution_total' => BillingMath::sum([$l['sla_credit'], $l['manual_credit']]), 'usage_avg_existing' => $l['traffic']['average_kbps'], 'traffic_total_existing' => $l['traffic']['volume_gb'], 'downtime_seconds' => $l['downtime_seconds'], 'availability_hours' => $l['downtime_seconds'] === null ? null : ($l['billing_seconds'] / 3600 - $l['downtime_seconds'] / 3600)];
        foreach ($map as $key => $field) {
            $data[$field] = $l['gross'] === null ? null : $l['charges'][$key];
        }
        $m->update($data);
        if ($m->trafficUsage) {
            $m->trafficUsage->update(['usage_avg_existing_kbps' => $l['traffic']['average_kbps'], 'traffic_total_existing_kbps' => $l['traffic']['volume_gb']]);
        }
        InvoicePrice::updateOrCreate(['invoice_line_id' => $m->id], ['harga_eksisting' => $data['price_existing'], 'harga_upgrade' => $data['price_upgrade'], 'harga_downgrade' => $data['price_downgrade'], 'harga_cabut_prorate' => $data['price_prorate'], 'harga_psb' => $data['price_psb'], 'harga_relokasi' => $data['price_relocation'], 'total' => $l['gross']]);
        InvoiceRestitution::updateOrCreate(['invoice_line_id' => $m->id], ['restitusi_tagihan' => $l['manual_credit'], 'restitusi_over_sla' => $l['sla_credit'], 'total_after_restitusi' => $l['net']]);
        InvoiceSla::updateOrCreate(['invoice_line_id' => $m->id], ['downtime_hours' => $l['downtime_seconds'] === null ? null : $l['downtime_seconds'] / 3600, 'sla_pks' => $l['sla_target'], 'availability_hours' => $l['billing_seconds'] / 3600, 'sla_actual' => $l['sla']]);
    }

    private function projectSummary(InvoicePeriod $p, array $result): void
    {
        $t = $result['totals'];
        if ($t['grand_total'] === null) {
            InvoiceSummary::where('invoice_period_id', $p->id)->delete();

            return;
        }
        $d = ['total_locations' => $t['service_count'], 'below_sla_count' => $t['below_sla_count'], 'restitution_manual_total' => $t['manual_credit'], 'restitution_over_sla_total' => $t['sla_credit'], 'restitution_grand_total' => BillingMath::sum([$t['manual_credit'], $t['sla_credit']]), 'subtotal_before_restitution' => $t['gross'], 'subtotal_after_restitution' => $t['subtotal'], 'ppn_amount' => $t['tax'], 'grand_total' => $t['grand_total']];
        foreach (['existing' => 'existing', 'psb' => 'pasang_baru', 'relocation' => 'relokasi', 'upgrade' => 'upgrade', 'downgrade' => 'downgrade', 'termination' => 'pemutusan'] as $field => $type) {
            $d[$field.'_count'] = $t['order_counts'][$type] ?? 0;
            $d[$field.'_total'] = $t['components'][$field === 'psb' ? 'installation' : $field];
        }
        InvoiceSummary::updateOrCreate(['invoice_period_id' => $p->id], $d);
    }
}
