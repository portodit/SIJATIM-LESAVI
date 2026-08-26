<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EosNetworkPoint extends Model
{
    use HasFactory;

    protected $table = 'eos_network_points';

    protected $fillable = [
        'invoice_period_id',
        'point_name',
        'location',
        'ssid',
        'ip_address',
        'eos_bandwidth',
        'upload_batch_id',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    /**
     * Relasi: Invoice Period
     */
    public function invoicePeriod(): BelongsTo
    {
        return $this->belongsTo(InvoicePeriod::class);
    }

    /**
     * Relasi: Upload Batch
     */
    public function uploadBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'upload_batch_id');
    }

    /**
     * Relasi: User yang upload
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Scope: Filter by SSID
     */
    public function scopeBySsid($query, string $ssid)
    {
        return $query->where('ssid', $ssid);
    }
}
