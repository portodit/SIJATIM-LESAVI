<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $guarded = [];

    protected $casts = ['original' => 'array', 'normalized' => 'array', 'errors' => 'array', 'resolution_history' => 'array'];

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
