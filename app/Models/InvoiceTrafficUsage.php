<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTrafficUsage extends Model
{
    use HasFactory;

    protected $table = 'invoice_traffic_usages';

    protected $fillable = [
        'invoice_line_id',
        'usage_avg_existing_kbps',
        'usage_avg_upgrade_kbps',
        'traffic_total_existing_kbps',
        'traffic_total_upgrade_kbps',
    ];

    protected $casts = [
        'usage_avg_existing_kbps' => 'decimal:4',
        'usage_avg_upgrade_kbps' => 'decimal:4',
        'traffic_total_existing_kbps' => 'decimal:4',
        'traffic_total_upgrade_kbps' => 'decimal:4',
    ];

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Hitung traffic total dari usage average
     * Formula Excel: =(Usage*24*3600*30/8)/1000000
     */
    public function calculateTrafficTotal(string $usageAvgKbps): float
    {
        return ($usageAvgKbps * 24 * 3600 * 30 / 8) / 1000000;
    }
}
