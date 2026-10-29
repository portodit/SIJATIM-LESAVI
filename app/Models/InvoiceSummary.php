<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSummary extends Model
{
    use HasFactory;

    protected $table = 'invoice_summaries';

    protected $fillable = [
        'invoice_period_id',
        'total_locations',
        'existing_count',
        'existing_total',
        'psb_count',
        'psb_total',
        'relocation_count',
        'relocation_total',
        'upgrade_count',
        'upgrade_total',
        'downgrade_count',
        'downgrade_total',
        'termination_count',
        'termination_total',
        'below_sla_count',
        'high_downtime_count',
        'restitution_manual_total',
        'restitution_over_sla_total',
        'restitution_grand_total',
        'subtotal_before_restitution',
        'subtotal_after_restitution',
        'ppn_amount',
        'grand_total',
        'notes',
    ];

    protected $casts = [
        'total_locations' => 'integer',
        'existing_count' => 'integer',
        'existing_total' => 'decimal:2',
        'psb_count' => 'integer',
        'psb_total' => 'decimal:2',
        'relocation_count' => 'integer',
        'relocation_total' => 'decimal:2',
        'upgrade_count' => 'integer',
        'upgrade_total' => 'decimal:2',
        'downgrade_count' => 'integer',
        'downgrade_total' => 'decimal:2',
        'termination_count' => 'integer',
        'termination_total' => 'decimal:2',
        'below_sla_count' => 'integer',
        'high_downtime_count' => 'integer',
        'restitution_manual_total' => 'decimal:2',
        'restitution_over_sla_total' => 'decimal:2',
        'restitution_grand_total' => 'decimal:2',
        'subtotal_before_restitution' => 'decimal:2',
        'subtotal_after_restitution' => 'decimal:2',
        'ppn_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    /**
     * Relasi: Invoice Period
     */
    public function invoicePeriod(): BelongsTo
    {
        return $this->belongsTo(InvoicePeriod::class, 'invoice_period_id');
    }

    /**
     * Generate summary dari invoice lines
     */
    public static function generateFromLines(InvoicePeriod $period): self
    {
        $period->refresh();
        if (! $period->calculationIsCurrent()) {
            throw new \LogicException('Calculate the period with InvoiceCalculator before reading summary.');
        }

        return self::where('invoice_period_id', $period->id)->firstOrFail();
    }

    /**
     * Format mata uang Indonesia
     */
    public function formatCurrency(float $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }
}
