<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'service_code',
        'service_name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Relasi: Banyak Bank Jujuy Links
     */
    public function bankJatimLinks(): HasMany
    {
        return $this->hasMany(BankJatimLink::class, 'service_id');
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
     * Get name attribute (alias for service_name)
     */
    public function getNameAttribute()
    {
        return $this->service_name;
    }
}
