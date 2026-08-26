<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddendumPriceItem extends Model
{
    use HasFactory;

    protected $table = 'addendum_price_items';

    protected $fillable = [
        'addendum_contract_id',
        'bandwidth_option_id',
        'bandwidth_description',
        'price_monthly',
        'price_installasi',
        'price_nrc',
        'price_rc',
        'service_category',
        'tier',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_installasi' => 'decimal:2',
        'price_nrc' => 'decimal:2',
        'price_rc' => 'decimal:2',
    ];

    /**
     * Relasi: Addendum Contract
     */
    public function addendumContract(): BelongsTo
    {
        return $this->belongsTo(AddendumContract::class);
    }

    /**
     * Relasi: Bandwidth Option
     */
    public function bandwidthOption(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class);
    }
}
