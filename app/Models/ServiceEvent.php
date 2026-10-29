<?php
namespace App\Models;
use App\Services\Billing\DowntimeConflicts;
use Illuminate\Database\Eloquent\Model;
class ServiceEvent extends Model
{
    use \App\Models\Concerns\ProtectsInvoiceInputs;
    protected $guarded = [];
    protected $casts = ['attributes' => 'array', 'source' => 'array', 'effective_date' => 'date'];

    protected static function booted(): void
    {
        // A PSB/Cabut changes the billable window, so a confirmed 0-second downtime must follow it.
        $realign = fn (self $event) => ($period = InvoicePeriod::find($event->invoice_period_id)) && DowntimeConflicts::realign($period, $event->sid);
        static::created($realign);
        static::deleted($realign);
    }
}
