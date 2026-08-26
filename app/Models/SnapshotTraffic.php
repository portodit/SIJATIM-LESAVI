<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnapshotTraffic extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'service_id',
        'pemakaian_average_eksisting',
        'pemakaian_average_upgrade',
        'trafik_total_eksisting',
        'trafik_total_upgrade',
        'imported_at',
    ];

    protected $casts = [
        'pemakaian_average_eksisting' => 'decimal:2',
        'pemakaian_average_upgrade' => 'decimal:2',
        'trafik_total_eksisting' => 'decimal:2',
        'trafik_total_upgrade' => 'decimal:2',
        'imported_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(SnapshotLocation::class, 'service_id', 'service_id');
    }
}
