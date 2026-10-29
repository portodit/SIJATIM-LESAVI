<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use Illuminate\Database\Eloquent\Model;

class OutageEvent extends Model
{
    use ProtectsInvoiceInputs;

    protected $guarded = [];

    protected $casts = ['source' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'duration_seconds' => 'integer', 'coverage_start' => 'datetime', 'coverage_end' => 'datetime'];
}
