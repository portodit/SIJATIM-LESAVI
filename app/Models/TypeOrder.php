<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypeOrder extends Model
{
    use HasFactory;

    protected $table = 'type_orders';

    protected $fillable = [
        'type_order_code',
        'type_order_name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    // Type Order codes
    public const CODE_EXISTING = 'existing';

    public const CODE_UPGRADE = 'upgrade';

    public const CODE_PASANG_BARU = 'pasang_baru';

    public const CODE_RELOKASI = 'relokasi';

    public const CODE_DOWNGRADE = 'downgrade';

    public const CODE_PEMUTUSAN = 'pemutusan';

    /**
     * Relasi: Banyak Invoice Lines
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'type_order_id');
    }

    /**
     * Scope: Hanya yang aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Order by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Cek apakah perlu tanggal efektif
     */
    public function needsEffectiveDate(): bool
    {
        return in_array($this->type_order_code, [
            self::CODE_UPGRADE,
            self::CODE_DOWNGRADE,
            self::CODE_PASANG_BARU,
            self::CODE_RELOKASI,
            self::CODE_PEMUTUSAN,
        ]);
    }

    /**
     * Cek apakah perlu bandwidth lama
     */
    public function needsOldBandwidth(): bool
    {
        return in_array($this->type_order_code, [
            self::CODE_EXISTING,
            self::CODE_UPGRADE,
            self::CODE_DOWNGRADE,
            self::CODE_PEMUTUSAN,
        ]);
    }

    /**
     * Cek apakah perlu bandwidth baru
     */
    public function needsNewBandwidth(): bool
    {
        return in_array($this->type_order_code, [
            self::CODE_UPGRADE,
            self::CODE_DOWNGRADE,
            self::CODE_PASANG_BARU,
        ]);
    }

    /**
     * Cek apakah menggunakan prorata
     */
    public function usesProrate(): bool
    {
        return in_array($this->type_order_code, [
            self::CODE_UPGRADE,
            self::CODE_DOWNGRADE,
            self::CODE_PASANG_BARU,
            self::CODE_PEMUTUSAN,
        ]);
    }

    /**
     * Get TypeOrder by code
     */
    public static function getByCode(string $code): ?self
    {
        return self::where('type_order_code', $code)->first();
    }
}
