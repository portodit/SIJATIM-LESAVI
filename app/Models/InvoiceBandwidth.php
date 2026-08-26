<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvoiceBandwidth extends Model
{
    use HasFactory;

    protected $table = 'invoice_bandwidths';

    protected $fillable = [
        'invoice_line_id',
        'existing_bw_id',
        'upgrade_bw_id',
        'downgrade_bw_id',
    ];

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Relasi: Bandwidth Eksisting
     */
    public function existingBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'existing_bw_id');
    }

    /**
     * Relasi: Bandwidth Upgrade
     */
    public function upgradeBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'upgrade_bw_id');
    }

    /**
     * Relasi: Bandwidth Downgrade
     */
    public function downgradeBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'downgrade_bw_id');
    }
}
