<?php

namespace App\Services\Billing;

use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\TypeOrder;

/**
 * Adds master services to a period and finds period lines by SID.
 */
class PeriodInventory
{
    /**
     * Add a master service to the period as Existing. Returns null when the
     * service's billing dates do not overlap the usage period.
     */
    public static function add(InvoicePeriod $period, BankJatimLink $service): ?InvoiceLine
    {
        if (($service->billing_start_date && $service->billing_start_date->gt($period->end_date)) || ($service->billing_end_date && $service->billing_end_date->lt($period->start_date))) {
            return null;
        }
        $line = $period->lines()->firstOrCreate(['bank_jatim_link_id' => $service->id], [
            'line_number' => InvoiceLine::generateLineNumber($period->id),
            'type_order_id' => TypeOrder::where('type_order_code', 'existing')->value('id'),
            'existing_bandwidth_option_id' => $service->current_bandwidth_option_id,
            'address_snapshot' => $service->address,
        ]);
        if (! $line->service_snapshot) {
            $line->update(['service_snapshot' => InvoiceCalculator::serviceSnapshot($line)]);
        }

        return $line;
    }

    public static function findLine(InvoicePeriod $period, string $sid): ?InvoiceLine
    {
        return $period->lines()->with('bankJatimLink')->get()->first(fn (InvoiceLine $line) => $line->billingSid() === $sid);
    }
}
