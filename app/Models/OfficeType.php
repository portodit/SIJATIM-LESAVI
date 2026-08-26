<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeType extends Model
{
    use HasFactory;

    protected $table = 'office_types';

    protected $fillable = [
        'office_type_code',
        'office_type_name',
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
        return $this->hasMany(BankJatimLink::class, 'office_type_id');
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
     * Get name attribute (alias for office_type_name)
     */
    public function getNameAttribute()
    {
        return $this->office_type_name;
    }

    /**
     * Get code attribute (alias for office_type_code)
     */
    public function getCodeAttribute()
    {
        return $this->office_type_code;
    }

    /**
     * Get links dengan sequence dalam kelompok
     */
    public function getOrderedLinks()
    {
        return $this->bankJatimLinks()
            ->where('link_status', 'active')
            ->orderBy('sequence_in_office_type')
            ->get();
    }

    /**
     * Get next sequence number untuk link baru
     */
    public function getNextSequenceNumber(): int
    {
        $maxSequence = $this->bankJatimLinks()->max('sequence_in_office_type');
        return ($maxSequence ?? 0) + 1;
    }
}
