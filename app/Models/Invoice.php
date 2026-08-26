<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'invoice_number',
        'total_amount',
        'total_locations',
        'file_path',
        'generated_by',
        'generated_at',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'total_locations' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function getFormattedAmountAttribute()
    {
        return 'Rp ' . number_format($this->total_amount, 0, ',', '.');
    }
}
