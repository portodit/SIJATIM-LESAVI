<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationCategory extends Model
{
    use HasFactory;

    protected $table = 'location_categories';

    protected $fillable = [
        'location_category_code',
        'location_category_name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Relasi: Banyak Bank Jatim Links
     */
    public function bankJatimLinks(): HasMany
    {
        return $this->hasMany(BankJatimLink::class, 'location_category_id');
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
     * Get name attribute (alias for location_category_name)
     */
    public function getNameAttribute()
    {
        return $this->location_category_name;
    }
}
