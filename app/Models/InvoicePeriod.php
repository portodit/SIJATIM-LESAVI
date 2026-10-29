<?php

namespace App\Models;

use App\Services\Billing\BillingMath;
use Brick\Math\BigInteger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        'billing_profile_id', 'invoice_metadata', 'input_version', 'calculated_input_version',
        'calculation', 'calculated_at', 'sla_coverage',
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
        'invoice_metadata' => 'array', 'calculation' => 'array', 'sla_coverage' => 'array',
        'calculated_at' => 'datetime', 'input_version' => 'integer', 'calculated_input_version' => 'integer',
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

    public const STEP_PREVIEW = 'preview';

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
     * Relasi: Banyak invoice lines (detail SSID)
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function billingProfile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(InvoiceRevision::class);
    }

    public function serviceEvents(): HasMany
    {
        return $this->hasMany(ServiceEvent::class);
    }

    public function outages(): HasMany
    {
        return $this->hasMany(OutageEvent::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class);
    }

    public function markInputsChanged(string $action, array $details = []): void
    {
        abort_if($this->fresh()->isLocked(), 423, 'Periode sudah terkunci. Buat revisi untuk mengubah input.');
        DB::table('invoice_periods')->where('id', $this->id)->update([
            'input_version' => DB::raw('input_version + 1'),
            'calculation' => null, 'calculated_input_version' => null, 'calculated_at' => null,
            'status' => $this->status === self::STATUS_REVISION ? self::STATUS_REVISION : self::STATUS_IN_PROGRESS,
            'updated_at' => now(),
        ]);
        DB::table('billing_audit_events')->insert([
            'invoice_period_id' => $this->id, 'action' => $action, 'details' => json_encode($details),
            'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->refresh();
    }

    public function calculationIsCurrent(): bool
    {
        if ($this->calculation === null || $this->calculated_input_version !== $this->input_version) {
            return false;
        }
        if ($this->isLocked()) {
            return true;
        }
        $target = (float) BillingMath::SLA_TARGET;

        return (float) ($this->calculation['profile']['settings']['sla_target'] ?? 0) === $target
            && ! collect($this->calculation['lines'] ?? [])->contains(fn ($line) => (float) ($line['sla_target'] ?? 0) !== $target);
    }

    public function pendingSourceIssues(): array
    {
        $issues = [];
        foreach ($this->importBatches()->whereNull('committed_at')->where('status', '!=', 'discarded')->whereHas('rows', fn ($q) => $q->where('status', '!=', 'ignored'))->get() as $batch) {
            $issues[] = ['code' => 'uncommitted_import', 'sid' => null, 'line_id' => null, 'message' => 'Import '.$batch->original_filename.' belum diselesaikan.', 'severity' => 'blocking', 'reason' => null];
            if ($batch->input_version !== $this->input_version) {
                $issues[] = ['code' => 'stale_source', 'sid' => null, 'line_id' => null, 'message' => 'Preview '.$batch->original_filename.' perlu validasi ulang terhadap input terbaru.', 'severity' => 'blocking', 'reason' => null];
            }
        }

        return $issues;
    }

    public function readinessIssues(): array
    {
        $issues = array_values(array_filter($this->calculation['issues'] ?? [], fn ($i) => ! in_array($i['code'], ['uncommitted_import', 'stale_source'])));
        array_push($issues, ...$this->pendingSourceIssues());
        $relocations = $this->pendingRelocationAddresses();
        $previousName = $relocations->isNotEmpty() ? $this->previousPeriod()?->period_name : null;
        foreach ($relocations as $sid => $line) {
            $issues[] = ['code' => 'relocation_address', 'sid' => $sid, 'line_id' => $line->id, 'message' => 'SSID ditandai relokasi di tagihan '.$previousName.'; alamat barunya belum diisi.', 'severity' => 'blocking', 'reason' => null];
        }
        if (! $this->calculationIsCurrent()) {
            $issues[] = ['code' => 'stale_calculation', 'sid' => null, 'line_id' => null, 'message' => 'Perhitungan belum tersedia atau input telah berubah. Hitung ulang sebelum finalisasi.', 'severity' => 'blocking', 'reason' => null];
        }

        return $issues;
    }

    /**
     * The invoice period of the preceding month (AGU26 for SEP26), if it exists.
     */
    public function previousPeriod(): ?self
    {
        $previous = Carbon::create($this->year, $this->month, 1)->subMonthNoOverflow();

        return self::where('year', $previous->year)->where('month', $previous->month)->first();
    }

    /**
     * Name of the following invoice month, e.g. "September 2026" for AGU26.
     */
    public function getNextPeriodNameAttribute(): string
    {
        $next = Carbon::create($this->year, $this->month, 1)->addMonthNoOverflow();

        return self::MONTH_NAMES[$next->month].' '.$next->year;
    }

    /**
     * SIDs flagged "Relokasi" without a new address in the previous month. Their new address must be entered here.
     */
    public function relocationFlaggedSids(): Collection
    {
        $previous = $this->previousPeriod();
        if (! $previous) {
            return collect();
        }

        return $previous->serviceEvents()->where('event_type', 'relokasi')->get()
            ->filter(fn ($event) => empty($event->attributes['new_address']))->pluck('sid')->unique()->values();
    }

    /**
     * Lines of relocated SIDs whose new address has not been entered yet, keyed by SID.
     */
    public function pendingRelocationAddresses(): Collection
    {
        $flagged = $this->relocationFlaggedSids();
        if ($flagged->isEmpty()) {
            return collect();
        }

        return $this->lines()->with('bankJatimLink')->get()
            ->filter(fn ($line) => $flagged->contains($line->billingSid()) && empty($line->new_address))
            ->keyBy(fn ($line) => $line->billingSid());
    }

    public function previousApprovedRevision(): ?InvoiceRevision
    {
        return InvoiceRevision::where('invoice_revisions.status', 'ready')->whereHas('invoicePeriod', fn ($q) => $q->where(fn ($q) => $q->where('year', '<', $this->year)->orWhere(fn ($q) => $q->where('year', $this->year)->where('month', '<', $this->month))))->join('invoice_periods as prior_periods', 'prior_periods.id', '=', 'invoice_revisions.invoice_period_id')->orderByDesc('prior_periods.year')->orderByDesc('prior_periods.month')->orderByDesc('invoice_revisions.version')->select('invoice_revisions.*')->first();
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
     * Generate period_code dari month dan year
     * Contoh: 6, 2026 -> "JUN26"
     */
    public static function generatePeriodCode(int $month, int $year): string
    {
        $monthCode = self::MONTH_CODES[$month] ?? strtoupper(substr(date('M', mktime(0, 0, 0, $month, 1)), 0, 3));

        return $monthCode.substr((string) $year, -2);
    }

    /**
     * Generate tanggal awal periode (tanggal 1)
     */
    public static function generateStartDate(int $month, int $year): Carbon
    {
        return Carbon::create($year, $month, 1)->startOfDay()->subMonthNoOverflow();
    }

    /**
     * Generate tanggal akhir periode (tanggal 30)
     * Untuk bulan < 12: gunakan tanggal 30
     * Untuk Desember: gunakan tanggal 31
     */
    public static function generateEndDate(int $month, int $year): Carbon
    {
        return self::generateStartDate($month, $year)->endOfMonth();
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
        return $this->month_name.' '.$this->year;
    }

    /**
     * Usage month billed by this invoice. Contoh: invoice Agustus 2026 → "Juli 2026"
     */
    public function getUsageNameAttribute(): string
    {
        return self::MONTH_NAMES[$this->start_date->month].' '.$this->start_date->year;
    }

    /**
     * Plain-language progress for operators.
     *
     * @return array{key: string, label: string, badge: string}
     */
    public function workflowState(?InvoiceRevision $latest = null): array
    {
        if ($this->isLocked()) {
            $latest ??= $this->revisions()->latest('version')->first();

            return match ($latest?->status) {
                'ready' => ['key' => 'issued', 'label' => 'Terbit · file siap', 'badge' => 'badge-success'],
                'failed' => ['key' => 'failed', 'label' => 'Terkunci · file gagal dibuat', 'badge' => 'badge-danger'],
                default => ['key' => 'generating', 'label' => 'Terkunci · file sedang dibuat', 'badge' => 'badge-info'],
            };
        }
        if ($this->calculationIsCurrent()) {
            return ['key' => 'calculated', 'label' => 'Sudah dihitung', 'badge' => 'badge-info'];
        }
        if ($this->status === self::STATUS_REVISION) {
            return ['key' => 'revision', 'label' => 'Revisi', 'badge' => 'badge-warning'];
        }
        if ($this->lines()->exists()) {
            return ['key' => 'filling', 'label' => 'Sedang diisi', 'badge' => 'badge-warning'];
        }

        return ['key' => 'new', 'label' => 'Baru', 'badge' => 'badge-gray'];
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
        return ! $this->isLocked() && $this->status !== self::STATUS_ARCHIVED;
    }

    /**
     * Get badge color berdasarkan status
     */
    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
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
        return match ($this->status) {
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
     * Default invoice number: the latest earlier period's number plus the months between them
     * (<digits>-<YYYYMM>); falls back to the profile prefix when no earlier number exists.
     */
    public static function nextInvoiceNumber(int $year, int $month, array $settings = []): string
    {
        $suffix = Carbon::create($year, $month, 1)->format('Ym');
        $earlier = static::query()->where(fn ($q) => $q->where('year', '<', $year)->orWhere(fn ($q) => $q->where('year', $year)->where('month', '<', $month)))
            ->orderByDesc('year')->orderByDesc('month')->cursor();

        foreach ($earlier as $previous) {
            if (preg_match('/^(\d+)-\d{6}$/', (string) ($previous->invoice_metadata['invoice_number'] ?? ''), $m)) {
                $gap = ($year * 12 + $month) - ($previous->year * 12 + $previous->month);

                return str_pad((string) BigInteger::of($m[1])->plus($gap), strlen($m[1]), '0', STR_PAD_LEFT).'-'.$suffix;
            }
        }

        return ($settings['invoice_prefix'] ?? '4807060000210').'-'.$suffix;
    }

    /**
     * Boot method untuk event model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate period_code, start_date, end_date sebelum create
        static::creating(function ($period) {
            if (! $period->period_code) {
                $period->period_code = self::generatePeriodCode($period->month, $period->year);
            }
            if (! $period->start_date) {
                $period->start_date = self::generateStartDate($period->month, $period->year);
            }
            if (! $period->end_date) {
                $period->end_date = self::generateEndDate($period->month, $period->year);
            }
            if (! $period->billing_days) {
                $period->billing_days = $period->start_date->daysInMonth;
            }
            $period->billing_profile_id ??= BillingProfile::where('is_active', true)->orderByDesc('version')->value('id');
            $settings = $period->billingProfile?->settings ?? [];
            $date = Carbon::create($period->year, $period->month, 1);
            $period->invoice_metadata ??= [
                'invoice_number' => self::nextInvoiceNumber($period->year, $period->month, $settings),
                'issue_date' => $date->toDateString(), 'due_date' => $date->copy()->day(min($settings['due_day'] ?? 20, $date->daysInMonth))->toDateString(),
            ];
        });
        static::deleting(function ($period) {
            if ($period->isLocked() || $period->revisions()->exists()) {
                throw new \LogicException('Periode dengan riwayat invoice tidak dapat dihapus.');
            }
        });
    }
}
