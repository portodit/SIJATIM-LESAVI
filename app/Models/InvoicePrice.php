<?php

namespace App\Models;

use App\Models\Concerns\ProtectsInvoiceInputs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePrice extends Model
{
    use HasFactory;
    use ProtectsInvoiceInputs;

    protected $table = 'invoice_prices';

    protected $fillable = [
        'invoice_line_id',
        'harga_eksisting',
        'harga_upgrade',
        'harga_downgrade',
        'harga_cabut_prorate',
        'harga_psb',
        'harga_relokasi',
        'total',
    ];

    protected $casts = [
        'harga_eksisting' => 'decimal:2',
        'harga_upgrade' => 'decimal:2',
        'harga_downgrade' => 'decimal:2',
        'harga_cabut_prorate' => 'decimal:2',
        'harga_psb' => 'decimal:2',
        'harga_relokasi' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Hitung total dari komponen harga
     * Formula Excel: =SUM(Y:AD)
     */
    public function calculateTotal(): ?float
    {
        return $this->total === null ? null : (float) $this->total;
    }
}
