<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use Illuminate\Database\Eloquent\Model;

class InvoiceAdjustment extends Model
{
    use ProtectsInvoiceInputs;

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'attributes' => 'array', 'source' => 'array'];
}
