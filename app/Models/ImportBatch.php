<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'usage_start' => 'date', 'usage_end' => 'date', 'committed_at' => 'datetime', 'input_version' => 'integer'];

    public function rows()
    {
        return $this->hasMany(ImportRow::class);
    }

    public function invoicePeriod()
    {
        return $this->belongsTo(InvoicePeriod::class);
    }
}
