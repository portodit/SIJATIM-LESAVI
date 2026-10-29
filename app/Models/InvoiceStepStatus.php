<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceStepStatus extends Model
{
    use HasFactory;

    protected $table = 'invoice_step_statuses';

    protected $fillable = [
        'invoice_period_id',
        'invoice_step_id',
        'status',
        'started_at',
        'completed_at',
        'completed_by',
        'error_count',
        'warning_count',
        'last_import_batch_id',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'error_count' => 'integer',
        'warning_count' => 'integer',
    ];

    // Status enum
    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ERROR = 'error';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Relasi: Invoice Period
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(InvoicePeriod::class, 'invoice_period_id');
    }

    /**
     * Relasi: Invoice Step (master)
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(InvoiceStep::class, 'invoice_step_id');
    }

    /**
     * Relasi: User yang menyelesaikan
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Relasi: Import batch terakhir
     */
    public function lastImportBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'last_import_batch_id');
    }

    /**
     * Cek apakah step sudah selesai
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Cek apakah step sedang dikerjakan
     */
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Cek apakah step ada error
     */
    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR || $this->error_count > 0;
    }

    /**
     * Get badge color berdasarkan status
     */
    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_NOT_STARTED => 'gray',
            self::STATUS_IN_PROGRESS => 'blue',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_ERROR => 'red',
            self::STATUS_SKIPPED => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Get label status dalam Bahasa Indonesia
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_NOT_STARTED => 'Belum Dimulai',
            self::STATUS_IN_PROGRESS => 'Sedang Dikerjakan',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_ERROR => 'Error',
            self::STATUS_SKIPPED => 'Dilewati',
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
     * Scope: Step yang belum selesai
     */
    public function scopeIncomplete($query)
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_SKIPPED]);
    }
}
