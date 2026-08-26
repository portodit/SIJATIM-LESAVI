<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class InvoicePeriod extends Model
{
    use HasFactory;

    protected $table = 'invoice_periods';

    protected $fillable = [
        'period_code',
        'month',
        'year',
        'start_date',
        'end_date',
        'billing_days',
        'status',
        'current_step_code',
        'progress_percentage',
        'is_locked',
        'finalized_at',
        'finalized_by',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'billing_days' => 'integer',
        'progress_percentage' => 'integer',
        'is_locked' => 'boolean',
        'finalized_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // Nama bulan dalam Bahasa Indonesia
    public const MONTH_NAMES = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
        4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September',
        10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    // Kode 3 huruf bulan
    public const MONTH_CODES = [
        1 => 'JAN', 2 => 'FEB', 3 => 'MAR',
        4 => 'APR', 5 => 'MEI', 6 => 'JUN',
        7 => 'JUL', 8 => 'AGU', 9 => 'SEP',
        10 => 'OKT', 11 => 'NOV', 12 => 'DES',
    ];

    // Status enum
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_VALIDATION = 'validation';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_REVISION = 'revision';
    public const STATUS_ARCHIVED = 'archived';

    // Step codes
    public const STEP_OVERVIEW = 'overview';
    public const STEP_SSID_LIST = 'ssid_list';
    public const STEP_TYPE_ORDER = 'type_order';
    public const STEP_BANDWIDTH = 'bandwidth';
    public const STEP_BANDWIDTH_BENCHMARK = 'bandwidth_benchmark';
    public const STEP_TRAFFIC_USAGE = 'traffic_usage';
    public const STEP_PRICE = 'price';
    public const STEP_SLA = 'sla';
    public const STEP_RESTITUTION = 'restitution';
    public const STEP_PRICE_BENCHMARK = 'price_benchmark';
    public const STEP_FINALIZATION = 'finalization';

    /**
     * Relasi: User yang membuat periode
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relasi: User yang finalisasi
     */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * Relasi: Banyak step statuses
     */
    public function stepStatuses(): HasMany
    {
        return $this->hasMany(InvoiceStepStatus::class);
    }

    /**
     * Relasi: Banyak invoice lines (detail SSID)
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Relasi: Banyak import batches
     */
    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    /**
     * Relasi: Satu summary
     */
    public function summary(): HasOne
    {
        return $this->hasOne(InvoiceSummary::class);
    }

    /**
     * Relasi: Banyak output dokumen (Excel, PDF)
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(InvoiceOutput::class);
    }

    /**
     * Relasi: Banyak hasil benchmarking bandwidth
     */
    public function bandwidthBenchmarkResults(): HasMany
    {
        return $this->hasMany(BandwidthBenchmarkResult::class);
    }

    /**
     * Relasi: Banyak hasil benchmarking harga
     */
    public function priceBenchmarkResults(): HasMany
    {
        return $this->hasMany(PriceBenchmarkResult::class);
    }

    /**
     * Generate period_code dari month dan year
     * Contoh: 6, 2026 -> "JUN26"
     */
    public static function generatePeriodCode(int $month, int $year): string
    {
        $monthCode = self::MONTH_CODES[$month] ?? strtoupper(substr(date('M', mktime(0, 0, 0, $month, 1)), 0, 3));
        return $monthCode . substr((string) $year, -2);
    }

    /**
     * Generate tanggal awal periode (tanggal 1)
     */
    public static function generateStartDate(int $month, int $year): Carbon
    {
        return Carbon::create($year, $month, 1)->startOfDay();
    }

    /**
     * Generate tanggal akhir periode (tanggal 30)
     * Untuk bulan < 12: gunakan tanggal 30
     * Untuk Desember: gunakan tanggal 31
     */
    public static function generateEndDate(int $month, int $year): Carbon
    {
        $day = $month == 12 ? 31 : 30;
        return Carbon::create($year, $month, $day)->endOfDay();
    }

    /**
     * Cek apakah periode sudah ada
     */
    public static function periodExists(int $month, int $year): bool
    {
        return self::where('month', $month)->where('year', $year)->exists();
    }

    /**
     * Get nama bulan lengkap
     */
    public function getMonthNameAttribute(): string
    {
        return self::MONTH_NAMES[$this->month] ?? '';
    }

    /**
     * Get kode bulan 3 huruf
     */
    public function getMonthCodeAttribute(): string
    {
        return self::MONTH_CODES[$this->month] ?? '';
    }

    /**
     * Get formatted period string
     * Contoh: "Juni 2026"
     */
    public function getPeriodNameAttribute(): string
    {
        return $this->month_name . ' ' . $this->year;
    }

    /**
     * Get formatted short period
     * Contoh: "JUN26"
     */
    public function getShortPeriodAttribute(): string
    {
        return $this->period_code;
    }

    /**
     * Cek apakah dokumen terkunci
     */
    public function isLocked(): bool
    {
        return $this->is_locked || $this->status === self::STATUS_FINALIZED;
    }

    /**
     * Cek apakah bisa diedit
     */
    public function canEdit(): bool
    {
        return !$this->isLocked() && $this->status !== self::STATUS_ARCHIVED;
    }

    /**
     * Get badge color berdasarkan status
     */
    public function getStatusBadgeColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_IN_PROGRESS => 'blue',
            self::STATUS_VALIDATION => 'yellow',
            self::STATUS_FINALIZED => 'green',
            self::STATUS_REVISION => 'orange',
            self::STATUS_ARCHIVED => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get label status dalam Bahasa Indonesia
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_IN_PROGRESS => 'Sedang Dikerjakan',
            self::STATUS_VALIDATION => 'Validasi',
            self::STATUS_FINALIZED => 'Selesai',
            self::STATUS_REVISION => 'Revisi',
            self::STATUS_ARCHIVED => 'Diarsipkan',
            default => 'Unknown',
        };
    }

    /**
     * Get human-readable current step name
     */
    public function getCurrentStepNameAttribute(): ?string
    {
        if (!$this->current_step_code) {
            return null;
        }
        return InvoiceStep::where('step_code', $this->current_step_code)->value('step_name');
    }

    /**
     * Scope: Filter berdasarkan status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Filter periode aktif (tidak archived)
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    /**
     * Scope: Filter berdasarkan tahun
     */
    public function scopeYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Boot method untuk event model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate period_code, start_date, end_date sebelum create
        static::creating(function ($period) {
            if (!$period->period_code) {
                $period->period_code = self::generatePeriodCode($period->month, $period->year);
            }
            if (!$period->start_date) {
                $period->start_date = self::generateStartDate($period->month, $period->year);
            }
            if (!$period->end_date) {
                $period->end_date = self::generateEndDate($period->month, $period->year);
            }
            if (!$period->billing_days) {
                $period->billing_days = 30;
            }
        });
    }
}
