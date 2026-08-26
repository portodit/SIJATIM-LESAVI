<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceBenchmarkResult extends Model
{
    use HasFactory;

    protected $table = 'price_benchmark_results';

    protected $fillable = [
        'invoice_line_id',
        'invoice_period_id',
        'invoice_price',
        'invoice_price_type',
        'addendum_price_item_id',
        'addendum_price',
        'comparison_result',
        'difference',
        'difference_percent',
        'notes',
    ];

    protected $casts = [
        'invoice_price' => 'decimal:2',
        'addendum_price' => 'decimal:2',
        'difference' => 'decimal:2',
        'difference_percent' => 'decimal:4',
    ];

    // Comparison result constants
    public const RESULT_MATCH = 'match';
    public const RESULT_HIGHER = 'higher';
    public const RESULT_LOWER = 'lower';
    public const RESULT_NOT_IN_ADDENDUM = 'not_in_addendum';
    public const RESULT_NOT_IN_INVOICE = 'not_in_invoice';
    public const RESULT_DIFFERENT = 'different';

    /**
     * Relasi: Invoice Line
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Relasi: Invoice Period
     */
    public function invoicePeriod(): BelongsTo
    {
        return $this->belongsTo(InvoicePeriod::class);
    }

    /**
     * Relasi: Addendum Price Item
     */
    public function addendumPriceItem(): BelongsTo
    {
        return $this->belongsTo(AddendumPriceItem::class);
    }

    /**
     * Get label untuk comparison result
     */
    public function getResultLabelAttribute(): string
    {
        return match($this->comparison_result) {
            self::RESULT_MATCH => 'Sesuai',
            self::RESULT_HIGHER => 'Invoice Lebih Tinggi',
            self::RESULT_LOWER => 'Invoice Lebih Rendah',
            self::RESULT_NOT_IN_ADDENDUM => 'Tidak ada di Addendum',
            self::RESULT_NOT_IN_INVOICE => 'Tidak ada di Invoice',
            self::RESULT_DIFFERENT => 'Berbeda',
            default => 'Unknown',
        };
    }
}
