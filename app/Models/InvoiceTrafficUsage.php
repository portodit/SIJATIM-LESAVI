<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTrafficUsage extends Model
{
    use HasFactory;
    use ProtectsInvoiceInputs;

    protected $table = 'invoice_traffic_usages';

    protected $fillable = [
        'invoice_line_id',
        'usage_avg_existing_kbps',
        'usage_avg_upgrade_kbps',
        'traffic_total_existing_kbps',
        'traffic_total_upgrade_kbps',
        'inbound_kbps', 'outbound_kbps', 'coverage_start', 'coverage_end', 'import_batch_id', 'source',
    ];

    protected $casts = [
        'usage_avg_existing_kbps' => 'decimal:12',
        'usage_avg_upgrade_kbps' => 'decimal:12',
        'traffic_total_existing_kbps' => 'decimal:6',
        'traffic_total_upgrade_kbps' => 'decimal:6',
        'inbound_kbps' => 'decimal:12', 'outbound_kbps' => 'decimal:12',
        'coverage_start' => 'date', 'coverage_end' => 'date', 'source' => 'array',
    ];

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Legacy accessor for the volume projected by InvoiceCalculator.
     * Coverage and average values are reviewed by the period calculator.
     */
    public function calculateTrafficTotal(string $usageAvgKbps): ?float
    {
        return $this->traffic_total_existing_kbps === null ? null : (float) $this->traffic_total_existing_kbps;
    }
}
