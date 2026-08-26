<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceStep extends Model
{
    use HasFactory;

    protected $table = 'invoice_steps';

    protected $fillable = [
        'step_number',
        'step_code',
        'step_name',
        'description',
        'is_required',
        'is_active',
    ];

    protected $casts = [
        'step_number' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Relasi: Banyak invoice step statuses
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(InvoiceStepStatus::class);
    }

    /**
     * Scope: Only active steps
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Order by step number
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('step_number');
    }

    /**
     * Get next step
     */
    public function getNextStep()
    {
        return self::where('step_number', '>', $this->step_number)
            ->where('is_active', true)
            ->orderBy('step_number')
            ->first();
    }

    /**
     * Get previous step
     */
    public function getPreviousStep()
    {
        return self::where('step_number', '<', $this->step_number)
            ->where('is_active', true)
            ->orderBy('step_number', 'desc')
            ->first();
    }
}
