<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BandwidthBenchmarkResult extends Model
{
    use HasFactory;

    protected $table = 'bandwidth_benchmark_results';

    protected $fillable = [
        'invoice_line_id',
        'invoice_period_id',
        'invoice_bandwidth',
        'invoice_bandwidth_option_id',
        'eos_bandwidth',
        'eos_bandwidth_option_id',
        'comparison_result',
        'notes',
    ];

    protected $casts = [
        'comparison_result' => 'string',
    ];

    // Comparison result constants
    public const RESULT_MATCH = 'match';
    public const RESULT_UPGRADE = 'upgrade';
    public const RESULT_DOWNGRADE = 'downgrade';
    public const RESULT_NOT_IN_EOS = 'not_in_eos';
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
     * Relasi: Bandwidth Option Invoice
     */
    public function invoiceBandwidthOption(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'invoice_bandwidth_option_id');
    }

    /**
     * Relasi: Bandwidth Option EoS
     */
    public function eosBandwidthOption(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'eos_bandwidth_option_id');
    }

    /**
     * Get label untuk comparison result
     */
    public function getResultLabelAttribute(): string
    {
        return match($this->comparison_result) {
            self::RESULT_MATCH => 'Sesuai',
            self::RESULT_UPGRADE => 'Upgrade',
            self::RESULT_DOWNGRADE => 'Downgrade',
            self::RESULT_NOT_IN_EOS => 'Tidak ada di EoS',
            self::RESULT_NOT_IN_INVOICE => 'Tidak ada di Invoice',
            self::RESULT_DIFFERENT => 'Berbeda',
            default => 'Unknown',
        };
    }
}
