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

    // Konstanta
    public const PPN_RATE = 0.11;

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
        $lines = $period->lines()->with(['typeOrder', 'bankJatimLink'])->get();

        $summary = [
            'invoice_period_id' => $period->id,
            'total_locations' => $lines->count(),

            // Existing
            'existing_count' => $lines->where('typeOrder.type_order_code', 'existing')->count(),
            'existing_total' => $lines->where('typeOrder.type_order_code', 'existing')->sum('price_total'),

            // PSB
            'psb_count' => $lines->where('typeOrder.type_order_code', 'pasang_baru')->count(),
            'psb_total' => $lines->where('typeOrder.type_order_code', 'pasang_baru')->sum('price_total'),

            // Relokasi
            'relocation_count' => $lines->where('typeOrder.type_order_code', 'relokasi')->count(),
            'relocation_total' => $lines->where('typeOrder.type_order_code', 'relokasi')->sum('price_total'),

            // Upgrade
            'upgrade_count' => $lines->where('typeOrder.type_order_code', 'upgrade')->count(),
            'upgrade_total' => $lines->where('typeOrder.type_order_code', 'upgrade')->sum('price_total'),

            // Downgrade
            'downgrade_count' => $lines->where('typeOrder.type_order_code', 'downgrade')->count(),
            'downgrade_total' => $lines->where('typeOrder.type_order_code', 'downgrade')->sum('price_total'),

            // Pemutusan
            'termination_count' => $lines->where('typeOrder.type_order_code', 'pemutusan')->count(),
            'termination_total' => $lines->where('typeOrder.type_order_code', 'pemutusan')->sum('price_total'),

            // SLA & Downtime
            'below_sla_count' => $lines->filter(fn($line) => $line->isBelowSla())->count(),
            'high_downtime_count' => $lines->filter(fn($line) => $line->getTotalDowntimeHours() > 7)->count(),

            // Restitusi
            'restitution_manual_total' => $lines->sum('restitution_manual'),
            'restitution_over_sla_total' => $lines->sum('restitution_over_sla'),
            'restitution_grand_total' => $lines->sum('restitution_total'),

            // Tagihan
            'subtotal_before_restitution' => $lines->sum('price_total'),
            'subtotal_after_restitution' => $lines->sum('price_after_restitution'),
        ];

        // Hitung PPN dan Grand Total
        $summary['ppn_amount'] = $summary['subtotal_after_restitution'] * self::PPN_RATE;
        $summary['grand_total'] = $summary['subtotal_after_restitution'] + $summary['ppn_amount'];

        return self::updateOrCreate(
            ['invoice_period_id' => $period->id],
            $summary
        );
    }

    /**
     * Format mata uang Indonesia
     */
    public function formatCurrency(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }
}
