<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InvoiceAdjustment extends Model
{
    use \App\Models\Concerns\ProtectsInvoiceInputs;
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'attributes' => 'array', 'source' => 'array'];
}
