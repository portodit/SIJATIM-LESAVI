<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnapshotLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'no',
        'jenis_kantor',
        'alamat',
        'kategori_lokasi',
        'layanan',
        'service_id',
        'bandwidth_eksisting',
        'bandwidth_upgrade',
        'sla_target',
        'harga_eksisting',
        'harga_upgrade',
        'harga_cabut',
    ];

    protected $casts = [
        'no' => 'integer',
        'sla_target' => 'decimal:2',
        'harga_eksisting' => 'decimal:2',
        'harga_upgrade' => 'decimal:2',
        'harga_cabut' => 'decimal:2',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
