<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceRestitution extends Model
{
    use HasFactory;

    protected $table = 'invoice_restitutions';

    protected $fillable = [
        'invoice_line_id',
        'restitusi_tagihan',
        'restitusi_over_sla',
        'total_after_restitusi',
    ];

    protected $casts = [
        'restitusi_tagihan' => 'decimal:2',
        'restitusi_over_sla' => 'decimal:2',
        'total_after_restitusi' => 'decimal:2',
    ];

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Hitung Restitusi Over SLA
     * Formula Excel: =IF(SLA < 99%, ((Availability - Downtime)/720)*HargaEksisting, 0)
     */
    public static function calculateRestitusiOverSla(?float $slaActual, ?float $hargaEksisting): float
    {
        if ($slaActual === null || $hargaEksisting === null) {
            return 0;
        }

        if ($slaActual < 0.99) {
            // ((720 - downtime_hours) / 720) * harga
            // Asumsi 720 jam per bulan
            return (0.01) * $hargaEksisting; // Simplified
        }

        return 0;
    }
}
