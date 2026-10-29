<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSla extends Model
{
    use HasFactory;
    use ProtectsInvoiceInputs;

    protected $table = 'invoice_slas';

    protected $fillable = [
        'invoice_line_id',
        'downtime_hours',
        'sla_pks',
        'availability_hours',
        'sla_actual',
    ];

    protected $casts = [
        'downtime_hours' => 'decimal:4',
        'sla_pks' => 'decimal:6',
        'availability_hours' => 'decimal:4',
        'sla_actual' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        static::saving(function ($sla) {
            $sla->sla_pks = \App\Services\Billing\BillingMath::SLA_TARGET;
        });
    }

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Hitung SLA Actual
     * Formula Excel: =(Availability - Downtime) / Availability
     */
    public function calculateSlaActual(): ?float
    {
        return $this->sla_actual === null ? null : (float) $this->sla_actual;
    }

    /**
     * Cek apakah SLA di bawah threshold (99%)
     */
    public function isBelowThreshold(): bool
    {
        return $this->sla_actual !== null && $this->sla_actual < \App\Services\Billing\BillingMath::SLA_TARGET;
    }

    /**
     * Get SLA dalam persen
     */
    public function getSlaPercentAttribute(): ?float
    {
        return $this->sla_actual !== null ? ($this->sla_actual * 100) : null;
    }
}
