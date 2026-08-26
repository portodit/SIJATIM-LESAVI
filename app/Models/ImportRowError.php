<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowError extends Model
{
    use HasFactory;

    protected $table = 'import_row_errors';

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'ssid_value',
        'error_type',
        'error_message',
        'raw_data',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'resolved_at' => 'datetime',
    ];

    // Error type constants
    public const TYPE_VALIDATION = 'validation';
    public const TYPE_DUPLICATE = 'duplicate';
    public const TYPE_NOT_FOUND = 'not_found';
    public const TYPE_INVALID_FORMAT = 'invalid_format';

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    /**
     * Relasi: Import Batch
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /**
     * Relasi: User yang resolve
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope: Pending errors
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Resolved errors
     */
    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    /**
     * Resolve error
     */
    public function resolve(int $userId, ?string $notes = null): bool
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
        return true;
    }

    /**
     * Ignore error
     */
    public function ignore(int $userId, ?string $notes = null): bool
    {
        $this->update([
            'status' => self::STATUS_IGNORED,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
        return true;
    }
}
