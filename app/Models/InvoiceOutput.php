<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceOutput extends Model
{
    use HasFactory;

    protected $table = 'invoice_outputs';

    protected $fillable = [
        'invoice_period_id',
        'output_type',
        'file_name',
        'file_path',
        'file_hash',
        'file_size',
        'status',
        'version',
        'generated_by',
        'notes',
        'invoice_revision_id',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'version' => 'integer',
    ];

    // Output types
    public const TYPE_EXCEL_INVOICE = 'excel_invoice';

    public const TYPE_PDF_CETAK = 'pdf_cetak';

    public const TYPE_LAMPIRAN = 'lampiran';

    public const TYPE_SUMMARY_PNG = 'summary_png';

    /**
     * Name shown on download buttons.
     */
    public function getDownloadLabelAttribute(): string
    {
        return match ($this->output_type) {
            self::TYPE_EXCEL_INVOICE => 'Excel',
            self::TYPE_PDF_CETAK => 'PDF',
            self::TYPE_SUMMARY_PNG => 'Ringkasan (PNG)',
            default => ucfirst(str_replace('_', ' ', $this->output_type)),
        };
    }

    // Status
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Relasi: Invoice Period
     */
    public function invoicePeriod(): BelongsTo
    {
        return $this->belongsTo(InvoicePeriod::class, 'invoice_period_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(InvoiceRevision::class, 'invoice_revision_id');
    }

    /**
     * Relasi: User yang generate
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Scope: Filter by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('output_type', $type);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Final files only
     */
    public function scopeFinal($query)
    {
        return $query->where('status', self::STATUS_FINAL);
    }

    /**
     * Cek apakah file ada
     */
    public function fileExists(): bool
    {
        return file_exists($this->file_path);
    }

    /**
     * Get file size formatted
     */
    public function getFileSizeFormattedAttribute(): string
    {
        if (! $this->file_size) {
            return '-';
        }

        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Create new version
     */
    public function createNewVersion(string $filePath, string $fileName, User $user): self
    {
        $this->status = self::STATUS_ARCHIVED;
        $this->save();

        return self::create([
            'invoice_period_id' => $this->invoice_period_id,
            'output_type' => $this->output_type,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_hash' => hash_file('sha256', $filePath),
            'file_size' => filesize($filePath),
            'status' => self::STATUS_DRAFT,
            'version' => $this->version + 1,
            'generated_by' => $user->id,
        ]);
    }
}
