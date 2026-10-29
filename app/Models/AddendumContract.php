<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddendumContract extends Model
{
    use HasFactory;

    protected $table = 'addendum_contracts';

    protected $fillable = [
        'contract_number',
        'contract_title',
        'effective_date',
        'end_date',
        'status',
        'uploaded_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'end_date' => 'date',
    ];

    // Status constants
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    /**
     * Relasi: Banyak Price Items
     */
    public function priceItems(): HasMany
    {
        return $this->hasMany(AddendumPriceItem::class);
    }

    /**
     * Relasi: User yang upload
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Scope: Active contracts
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Cek apakah kontrak aktif
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
