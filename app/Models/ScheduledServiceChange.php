<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A service change recorded ahead of time. It becomes a ServiceEvent when the
 * invoice period whose usage month contains effective_date is prepared.
 */
class ScheduledServiceChange extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = ['attributes' => 'array', 'effective_date' => 'date'];

    /**
     * Invoice month/year billing the usage month of the given date (usage July → invoice August).
     *
     * @return array{month: int, year: int}
     */
    public static function invoiceMonthFor(Carbon|string $date): array
    {
        $invoice = Carbon::parse($date)->startOfMonth()->addMonthNoOverflow();

        return ['month' => $invoice->month, 'year' => $invoice->year];
    }

    public function targetPeriod(): ?InvoicePeriod
    {
        $target = self::invoiceMonthFor($this->effective_date);

        return InvoicePeriod::where('month', $target['month'])->where('year', $target['year'])->first();
    }

    public function targetLabel(): string
    {
        $target = self::invoiceMonthFor($this->effective_date);

        return InvoicePeriod::MONTH_NAMES[$target['month']].' '.$target['year'];
    }
}
