<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $table = 'invoice_lines';

    protected $fillable = [
        'invoice_period_id',
        'bank_jatim_link_id',
        'type_order_id',
        'line_number',
        'effective_date',
        'is_new_ssid',
        'ssid_temp',
        'new_ssid_note',
        'address_snapshot',
        'old_address_snapshot',
        'new_address',
        'existing_bandwidth_option_id',
        'upgrade_bandwidth_option_id',
        'downgrade_bandwidth_option_id',
        'usage_avg_existing',
        'usage_avg_upgrade',
        'traffic_total_existing',
        'traffic_total_upgrade',
        'downtime_days',
        'downtime_hours',
        'downtime_minutes',
        'sla_note',
        'sla_actual',
        'sla_target',
        'availability_hours',
        'price_existing',
        'price_upgrade',
        'price_downgrade',
        'price_prorate',
        'price_psb',
        'price_relocation',
        'price_total',
        'restitution_manual',
        'restitution_over_sla',
        'restitution_total',
        'price_after_restitution',
        'bandwidth_status',
        'price_status',
        'validation_notes',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'effective_date' => 'date',
        'downtime_days' => 'integer',
        'downtime_hours' => 'integer',
        'downtime_minutes' => 'integer',
        'sla_actual' => 'decimal:6',
        'sla_target' => 'decimal:6',
        'availability_hours' => 'decimal:2',
        'price_existing' => 'decimal:2',
        'price_upgrade' => 'decimal:2',
        'price_downgrade' => 'decimal:2',
        'price_prorate' => 'decimal:2',
        'price_psb' => 'decimal:2',
        'price_relocation' => 'decimal:2',
        'price_total' => 'decimal:2',
        'restitution_manual' => 'decimal:2',
        'restitution_over_sla' => 'decimal:2',
        'restitution_total' => 'decimal:2',
        'price_after_restitution' => 'decimal:2',
        'usage_avg_existing' => 'integer',
        'usage_avg_upgrade' => 'integer',
        'traffic_total_existing' => 'integer',
        'traffic_total_upgrade' => 'integer',
    ];

    // Konstanta
    public const SLA_TARGET_DEFAULT = 0.99; // 99%
    public const BILLING_DAYS_DEFAULT = 30;

    /**
     * Relasi: Invoice Period
     */
    public function invoicePeriod(): BelongsTo
    {
        return $this->belongsTo(InvoicePeriod::class, 'invoice_period_id');
    }

    /**
     * Relasi: Bank Jatim Link (Master)
     */
    public function bankJatimLink(): BelongsTo
    {
        return $this->belongsTo(BankJatimLink::class, 'bank_jatim_link_id');
    }

    /**
     * Relasi: Type Order
     */
    public function typeOrder(): BelongsTo
    {
        return $this->belongsTo(TypeOrder::class, 'type_order_id');
    }

    /**
     * Relasi: Bandwidth Eksisting
     */
    public function existingBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'existing_bandwidth_option_id');
    }

    /**
     * Relasi: Bandwidth Upgrade
     */
    public function upgradeBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'upgrade_bandwidth_option_id');
    }

    /**
     * Relasi: Bandwidth Downgrade
     */
    public function downgradeBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'downgrade_bandwidth_option_id');
    }

    /**
     * Relasi: Invoice Bandwidth (child table)
     */
    public function invoiceBandwidth(): HasOne
    {
        return $this->hasOne(InvoiceBandwidth::class);
    }

    /**
     * Relasi: Invoice Traffic Usage (child table)
     */
    public function trafficUsage(): HasOne
    {
        return $this->hasOne(InvoiceTrafficUsage::class);
    }

    /**
     * Relasi: Invoice SLA (child table)
     */
    public function sla(): HasOne
    {
        return $this->hasOne(InvoiceSla::class);
    }

    /**
     * Relasi: Invoice Restitution (child table)
     */
    public function restitution(): HasOne
    {
        return $this->hasOne(InvoiceRestitution::class);
    }

    /**
     * Relasi: Invoice Price (child table)
     */
    public function price(): HasOne
    {
        return $this->hasOne(InvoicePrice::class);
    }

    /**
     * Relasi: Bandwidth Benchmark Results
     */
    public function bandwidthBenchmarks(): HasMany
    {
        return $this->hasMany(BandwidthBenchmarkResult::class);
    }

    /**
     * Relasi: Price Benchmark Results
     */
    public function priceBenchmarks(): HasMany
    {
        return $this->hasMany(PriceBenchmarkResult::class);
    }

    /**
     * Cek apakah SLA di bawah target
     */
    public function isBelowSla(): bool
    {
        if ($this->sla_actual === null) {
            return false;
        }
        return $this->sla_actual < ($this->sla_target ?? self::SLA_TARGET_DEFAULT);
    }

    /**
     * Hitung total downtime dalam jam
     */
    public function getTotalDowntimeHours(): float
    {
        return ($this->downtime_days * 24) + $this->downtime_hours + ($this->downtime_minutes / 60);
    }

    /**
     * Hitung SLA Actual
     */
    public function calculateSlaActual(int $totalHoursPerMonth = 720): float
    {
        $downtimeHours = $this->getTotalDowntimeHours();
        $availability = $totalHoursPerMonth - $downtimeHours;
        return $availability / $totalHoursPerMonth;
    }

    /**
     * Hitung restitusi over SLA
     */
    public function calculateRestitutionOverSla(): float
    {
        $slaTarget = $this->sla_target ?? self::SLA_TARGET_DEFAULT;

        if ($this->sla_actual >= $slaTarget) {
            return 0;
        }

        $price = $this->price_total ?? 0;
        $totalHours = 720;
        $availabilityHours = $this->availability_hours ?? $totalHours;

        // Rumus: ((SLA_Target - SLA_Actual) / Total_Jam) * Harga
        $diffHours = ($slaTarget * $totalHours) - $availabilityHours;
        $restitution = ($diffHours / $totalHours) * $price;

        return max(0, $restitution);
    }

    /**
     * Hitung harga total
     */
    public function calculatePriceTotal(): float
    {
        return (float) (
            ($this->price_existing ?? 0) +
            ($this->price_upgrade ?? 0) +
            ($this->price_downgrade ?? 0) +
            ($this->price_prorate ?? 0) +
            ($this->price_psb ?? 0) +
            ($this->price_relocation ?? 0)
        );
    }

    /**
     * Hitung harga setelah restitusi
     */
    public function calculatePriceAfterRestitution(): float
    {
        $total = $this->price_total ?? $this->calculatePriceTotal();
        $restitution = $this->restitution_total ?? 0;
        return max(0, $total - $restitution);
    }

    /**
     * Hitung prorata berdasarkan tanggal efektif
     */
    public static function calculateProrate(
        float $monthlyPrice,
        \Carbon\Carbon $effectiveDate,
        int $billingDays = 30
    ): float {
        $endOfMonth = $effectiveDate->copy()->endOfMonth()->day;
        $effectiveDay = $effectiveDate->day;

        // Untuk upgrade/downgrade: hitung dari tanggal efektif sampai akhir bulan
        $activeDays = $endOfMonth - $effectiveDay + 1;

        return ($monthlyPrice / $billingDays) * $activeDays;
    }

    /**
     * Generate line number untuk periode
     */
    public static function generateLineNumber(int $invoicePeriodId): int
    {
        $max = self::where('invoice_period_id', $invoicePeriodId)->max('line_number');
        return ($max ?? 0) + 1;
    }

    /**
     * Cek apakah Type Order & Bandwidth sudah lengkap
     */
    public function isTypeOrderComplete(): bool
    {
        // Type order harus dipilih
        if (!$this->type_order_id || !$this->typeOrder) {
            return false;
        }

        // Existing bandwidth WAJIB selalu ada
        if (!$this->existing_bandwidth_option_id) {
            return false;
        }

        // Cek berdasarkan type order
        $typeOrder = $this->typeOrder;

        // UPGRADE & DOWNGRADE: perlu upgrade atau downgrade option
        if (in_array($typeOrder->type_order_code, [TypeOrder::CODE_UPGRADE, TypeOrder::CODE_DOWNGRADE])) {
            if (!$this->upgrade_bandwidth_option_id && !$this->downgrade_bandwidth_option_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cek apakah Type Order perlu bandwidth baru (upgrade/downgrade)
     */
    public function needsNewBandwidth(): bool
    {
        if (!$this->typeOrder) {
            return false;
        }
        return $this->typeOrder->needsNewBandwidth();
    }
}
