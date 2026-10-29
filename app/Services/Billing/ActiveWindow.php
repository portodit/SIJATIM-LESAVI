<?php

namespace App\Services\Billing;

use App\Models\InvoicePeriod;
use Illuminate\Support\Carbon;

/**
 * Billable window of one service inside a usage period: master billing dates
 * narrowed by PSB (start) and Cabut (end) events. Mirrors InvoiceCalculator::line().
 * Real calendar days for SLA/downtime; billed days (30-day month, PSB from H+1) are in BillingMath.
 */
class ActiveWindow
{
    /**
     * @param  iterable<\App\Models\ServiceEvent|array>  $events
     * @return array{start: string, end: string, seconds: int}|null null when the service has no active day
     */
    public static function for(InvoicePeriod $period, array $snapshot, iterable $events): ?array
    {
        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();
        if (! empty($snapshot['billing_start_date'])) {
            $start = max($start, $snapshot['billing_start_date']);
        }
        if (! empty($snapshot['billing_end_date'])) {
            $end = min($end, $snapshot['billing_end_date']);
        }
        foreach ($events as $event) {
            $date = Carbon::parse(data_get($event, 'effective_date'))->toDateString();
            if (data_get($event, 'event_type') === 'pasang_baru') {
                $start = max($start, $date);
            }
            if (data_get($event, 'event_type') === 'pemutusan') {
                $end = min($end, $date);
            }
        }
        if ($start > $end) {
            return null;
        }

        return ['start' => $start, 'end' => $end, 'seconds' => ((int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1) * 86400];
    }
}
