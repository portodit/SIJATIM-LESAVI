<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceRestitution extends Model
{
    use HasFactory;
    use ProtectsInvoiceInputs;

    protected $table = 'invoice_restitutions';

    protected $fillable = [
        'invoice_line_id',
        'restitusi_tagihan',
        'restitusi_over_sla',
        'total_after_restitusi',
    ];

    protected $casts = [
        'restitusi_tagihan' => 'decimal:2',
        'restitusi_over_sla' => 'decimal:2',
        'total_after_restitusi' => 'decimal:2',
    ];

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    // Compatibility projection only. InvoiceCalculator owns all credit calculations.
}
