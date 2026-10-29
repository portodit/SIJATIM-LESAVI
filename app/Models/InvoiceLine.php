<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use App\Services\Billing\BillingMath;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class InvoiceLine extends Model
{
    use HasFactory;
    use ProtectsInvoiceInputs;

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
        'downtime_seconds', 'service_snapshot', 'legacy_inputs', 'traffic_exception_reason',
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
        'usage_avg_existing' => 'decimal:12', 'usage_avg_upgrade' => 'decimal:12',
        'traffic_total_existing' => 'decimal:6', 'traffic_total_upgrade' => 'decimal:6',
        'downtime_seconds' => 'integer', 'service_snapshot' => 'array', 'legacy_inputs' => 'array',
    ];

    // Konstanta
    public const SLA_TARGET_DEFAULT = (float) BillingMath::SLA_TARGET;

    public const BILLING_DAYS_DEFAULT = 30;

    protected static function booted(): void
    {
        static::saving(function ($line) {
            $line->sla_target = BillingMath::SLA_TARGET;
        });
    }

    /**
     * SID used for billing inputs (events, downtime): snapshot first, then master, then imported text.
     */
    public function billingSid(): string
    {
        return (string) ($this->service_snapshot['sid'] ?? $this->bankJatimLink?->ssid ?? $this->ssid_temp);
    }

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
        return $this->belongsTo(BankJatimLink::class, 'bank_jatim_link_id')->withTrashed();
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
     * Cek apakah SLA di bawah target
     */
    public function isBelowSla(): bool
    {
        if ($this->sla_actual === null) {
            return false;
        }

        return $this->sla_actual < self::SLA_TARGET_DEFAULT;
    }

    /**
     * Hitung total downtime dalam jam
     */
    public function getTotalDowntimeHours(): float
    {
        return $this->getTotalDowntimeSeconds() / 3600;
    }

    /**
     * Hitung total downtime dalam detik
     */
    public function getTotalDowntimeSeconds(): int
    {
        return $this->downtime_seconds ?? (($this->downtime_days * 86400) + ($this->downtime_hours * 3600) + ($this->downtime_minutes * 60));
    }

    /**
     * Pecah total detik downtime menjadi days/hours/minutes.
     * Sisa di bawah 1 menit dibuang (skema tidak punya kolom detik).
     */
    public static function downtimeSecondsToParts(int $totalSeconds): array
    {
        $totalSeconds = max(0, $totalSeconds);
        $days = intdiv($totalSeconds, 86400);
        $remainder = $totalSeconds % 86400;
        $hours = intdiv($remainder, 3600);
        $minutes = intdiv($remainder % 3600, 60);

        return [
            'downtime_seconds' => $totalSeconds,
            'downtime_days' => $days,
            'downtime_hours' => $hours,
            'downtime_minutes' => $minutes,
        ];
    }

    /**
     * Hitung ulang seluruh field turunan (availability, SLA, harga, restitusi)
     * dan simpan. Dipanggil setiap kali data Step 2 (bandwidth/downtime/harga)
     * berubah, supaya Step 3 (preview) bisa langsung baca kolom yang sudah tersimpan
     * tanpa perlu hitung ulang setiap render.
     */
    public function recalculateDerived(): void
    {
        // Legacy input endpoints may call this; authoritative calculations are explicit period operations.
        $this->sla_actual = null;
        $this->price_total = null;
        $this->price_after_restitution = null;
        $this->save();
    }

    /**
     * Hitung SLA Actual
     */
    public function calculateSlaActual(?int $totalHoursPerMonth = null): ?float
    {
        if ($this->downtime_seconds === null) {
            return null;
        }

        return (float) BillingMath::sla($this->downtime_seconds, $this->invoicePeriod->billing_days * 86400);
    }

    /**
     * Hitung restitusi over SLA
     */
    public function calculateRestitutionOverSla(): ?float
    {
        if ($this->price_total === null || $this->downtime_seconds === null) {
            return null;
        }

        return (float) BillingMath::slaCredit((string) $this->price_total, $this->downtime_seconds, $this->invoicePeriod->billing_days * 86400, BillingMath::SLA_TARGET);
    }

    /**
     * Hitung harga total
     */
    public function calculatePriceTotal(): ?float
    {
        return $this->price_total === null ? null : (float) $this->price_total;
    }

    /**
     * Hitung harga setelah restitusi
     */
    public function calculatePriceAfterRestitution(): ?float
    {
        return $this->price_after_restitution === null ? null : (float) $this->price_after_restitution;
    }

    /**
     * Hitung prorata berdasarkan tanggal efektif
     */
    public static function calculateProrate(
        float $monthlyPrice,
        Carbon $effectiveDate,
        int $billingDays = 30
    ): float {
        $endOfMonth = $effectiveDate->copy()->endOfMonth()->day;
        $effectiveDay = $effectiveDate->day;

        // Untuk upgrade/downgrade: hitung dari tanggal efektif sampai akhir bulan
        $activeDays = $endOfMonth - $effectiveDay + 1;

        return (float) BillingMath::prorate((string) $monthlyPrice, $activeDays, $billingDays);
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
     * Keep a draft period's lines grouped by branch (jenis kantor) and numbered 1..N.
     * Branches keep the order in which they first appear; inside a branch the current
     * order is kept, so a newly added line lands at the end of its own branch.
     * Locked periods are never renumbered. Returns how many lines changed number.
     */
    public static function regroup(InvoicePeriod|int $period): int
    {
        $periodId = $period instanceof InvoicePeriod ? $period->id : $period;
        // Read the stored state: a passed model may be stale.
        $current = InvoicePeriod::find($periodId);
        if (! $current || $current->isLocked()) {
            return 0;
        }

        return DB::transaction(function () use ($periodId) {
            $lines = self::where('invoice_period_id', $periodId)->with('bankJatimLink.officeType')
                ->orderBy('line_number')->orderBy('id')->get(['id', 'line_number', 'bank_jatim_link_id', 'service_snapshot']);
            $groups = [];
            foreach ($lines as $line) {
                // Same source as the calculation snapshot: stored snapshot first, else the master's office type.
                $snapshot = $line->service_snapshot ?? ['branch' => $line->bankJatimLink?->officeType?->office_type_name];
                $groups[$snapshot['branch'] ?? '-'][] = $line;
            }
            $changed = 0;
            $number = 0;
            foreach ($groups as $group) {
                foreach ($group as $line) {
                    $number++;
                    if ($line->line_number !== $number) {
                        // No unique index on (invoice_period_id, line_number), so a direct update is safe.
                        DB::table('invoice_lines')->where('id', $line->id)->update(['line_number' => $number]);
                        $changed++;
                    }
                }
            }

            return $changed;
        });
    }

    /**
     * Cek apakah Type Order & Bandwidth sudah lengkap
     */
    public function isTypeOrderComplete(): bool
    {
        // Type order harus dipilih
        if (! $this->type_order_id || ! $this->typeOrder) {
            return false;
        }

        // Existing bandwidth WAJIB selalu ada
        if (! $this->existing_bandwidth_option_id) {
            return false;
        }

        // Cek berdasarkan type order
        $typeOrder = $this->typeOrder;

        // UPGRADE & DOWNGRADE: perlu upgrade atau downgrade option
        if (in_array($typeOrder->type_order_code, [TypeOrder::CODE_UPGRADE, TypeOrder::CODE_DOWNGRADE])) {
            if (! $this->upgrade_bandwidth_option_id && ! $this->downgrade_bandwidth_option_id) {
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
        if (! $this->typeOrder) {
            return false;
        }

        return $this->typeOrder->needsNewBandwidth();
    }
}
