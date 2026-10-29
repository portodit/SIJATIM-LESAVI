<?php

namespace App\Models;

use App\Services\Billing\TaxRate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceMaster extends Model
{
    public function contract()
    {
        return $this->belongsTo(AddendumContract::class, 'addendum_contract_id');
    }

    use HasFactory;

    protected $table = 'price_masters';

    protected $fillable = [
        'location_category_id',
        'service_id',
        'bandwidth_option_id',
        'monthly_price',
        'monthly_price_with_ppn',
        'installation_price',
        'installation_price_with_ppn',
        'addendum_reference',
        'effective_start_date',
        'effective_end_date',
        'notes',
        'is_active',
        'addendum_contract_id', 'router_mode', 'sid', 'relocation_price',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'monthly_price_with_ppn' => 'decimal:2',
        'installation_price' => 'decimal:2',
        'installation_price_with_ppn' => 'decimal:2',
        'effective_start_date' => 'date',
        'effective_end_date' => 'date',
        'is_active' => 'boolean',
        'relocation_price' => 'decimal:2',
    ];

    /**
     * Relasi: Location Category
     */
    public function locationCategory(): BelongsTo
    {
        return $this->belongsTo(LocationCategory::class, 'location_category_id');
    }

    /**
     * Relasi: Service
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * Relasi: Bandwidth Option
     */
    public function bandwidthOption(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'bandwidth_option_id');
    }

    /**
     * Scope: Hanya yang aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Berlaku pada tanggal tertentu
     */
    public function scopeEffectiveOn($query, $date)
    {
        return $query->where('effective_start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_end_date')
                    ->orWhere('effective_end_date', '>=', $date);
            });
    }

    /**
     * Find price berdasarkan kombinasi
     */
    public static function findPrice(
        int $locationCategoryId,
        int $serviceId,
        ?int $bandwidthOptionId,
        $date = null
    ): ?self {
        $date = $date ?? now();

        return self::active()
            ->where('location_category_id', $locationCategoryId)
            ->where('service_id', $serviceId)
            ->where('bandwidth_option_id', $bandwidthOptionId)
            ->effectiveOn($date)
            ->first();
    }

    /**
     * Hitung harga dengan PPN (tarif PPN dari Master Harga, kecuali $rate diberikan)
     */
    public function calculateWithPpn(?string $rate = null): float
    {
        return (float) $this->monthly_price * (1 + (float) ($rate ?? TaxRate::current()));
    }

    /**
     * Hitung biaya PSB dengan PPN (tarif PPN dari Master Harga, kecuali $rate diberikan)
     */
    public function calculateInstallationWithPpn(?string $rate = null): float
    {
        if (! $this->installation_price) {
            return 0;
        }

        return (float) $this->installation_price * (1 + (float) ($rate ?? TaxRate::current()));
    }

    /**
     * Hitung harga prorata
     */
    public function calculateProrate(
        Carbon $effectiveDate,
        Carbon $endOfMonth,
        bool $isPasangBaru = false
    ): float {
        $daysInMonth = 30; // Dasar 30 hari Bank jatim
        $effectiveDay = $effectiveDate->day;
        $endDay = $endOfMonth->day;

        // Untuk PSB dan Upgrade/Downgrade: dari tanggal efektif sampai akhir bulan
        $activeDays = $endDay - $effectiveDay + 1;

        return ($this->monthly_price / $daysInMonth) * $activeDays;
    }

    /**
     * Hitung harga prorata untuk upgrade/downgrade
     */
    public function calculateUpgradeDowngradeProrate(
        self $oldPrice,
        Carbon $effectiveDate,
        Carbon $endOfMonth
    ): array {
        $daysInMonth = 30;
        $effectiveDay = $effectiveDate->day;
        $endDay = $endOfMonth->day;

        // Hari sebelum perubahan (1 sampai effective - 1)
        $oldDays = $effectiveDay - 1;
        // Hari setelah perubahan (effective sampai akhir bulan)
        $newDays = $endDay - $effectiveDay + 1;

        $oldPriceTotal = ($oldPrice->monthly_price / $daysInMonth) * $oldDays;
        $newPriceTotal = ($this->monthly_price / $daysInMonth) * $newDays;

        return [
            'old_price_total' => $oldPriceTotal,
            'new_price_total' => $newPriceTotal,
            'total' => $oldPriceTotal + $newPriceTotal,
        ];
    }
}
