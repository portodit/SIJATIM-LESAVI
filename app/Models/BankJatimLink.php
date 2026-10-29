<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankJatimLink extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bank_jatim_links';

    protected $fillable = [
        'ssid',
        'office_type_id',
        'sequence_in_office_type',
        'location_category_id',
        'service_id',
        'current_bandwidth_option_id',
        'address',
        'ip_host_telkom',
        'service_start_date',
        'service_end_date',
        'link_status',
        'last_import_batch_id',
        'created_by',
        'updated_by',
        'notes',
        'billing_start_date', 'billing_end_date', 'physical_router_status', 'billable_router_status', 'router_billing_from', 'addendum_contract_id',
    ];

    protected $casts = [
        'service_start_date' => 'date',
        'service_end_date' => 'date',
        'sequence_in_office_type' => 'integer',
        'billing_start_date' => 'date', 'billing_end_date' => 'date', 'router_billing_from' => 'date',
    ];

    // Link status
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_TERMINATED = 'terminated';

    /**
     * Relasi: Office Type (Jenis Kantor)
     */
    public function officeType(): BelongsTo
    {
        return $this->belongsTo(OfficeType::class, 'office_type_id');
    }

    /**
     * Relasi: Location Category (Kategori Lokasi)
     */
    public function locationCategory(): BelongsTo
    {
        return $this->belongsTo(LocationCategory::class, 'location_category_id');
    }

    /**
     * Relasi: Service (Jenis Layanan)
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * Relasi: Current Bandwidth Option
     */
    public function currentBandwidth(): BelongsTo
    {
        return $this->belongsTo(BandwidthOption::class, 'current_bandwidth_option_id');
    }

    /**
     * Relasi: Invoice Lines
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'bank_jatim_link_id');
    }

    /**
     * Relasi: Last Import Batch
     */
    public function lastImportBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'last_import_batch_id');
    }

    /**
     * Relasi: User yang membuat
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relasi: User yang terakhir update
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Hanya link aktif
     */
    public function scopeActive($query)
    {
        return $query->where('link_status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Filter berdasarkan Jenis Kantor
     */
    public function scopeByOfficeType($query, int $officeTypeId)
    {
        return $query->where('office_type_id', $officeTypeId);
    }

    /**
     * Scope: Filter berdasarkan Kategori Lokasi
     */
    public function scopeByLocationCategory($query, int $categoryId)
    {
        return $query->where('location_category_id', $categoryId);
    }

    /**
     * Scope: Filter berdasarkan Layanan
     */
    public function scopeByService($query, int $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    /**
     * Scope: Order dengan sorting invoice
     */
    public function scopeOrderedForInvoice($query)
    {
        return $query
            ->join('office_types', 'bank_jatim_links.office_type_id', '=', 'office_types.id')
            ->orderBy('office_types.sort_order')
            ->orderBy('bank_jatim_links.sequence_in_office_type')
            ->select('bank_jatim_links.*');
    }

    /**
     * Cek apakah link aktif
     */
    public function isActive(): bool
    {
        return $this->link_status === self::STATUS_ACTIVE;
    }

    /**
     * Cek apakah link terminated
     */
    public function isTerminated(): bool
    {
        return $this->link_status === self::STATUS_TERMINATED;
    }

    /**
     * Get global number untuk invoice (generated on-the-fly)
     */
    public function getGlobalNumber(): int
    {
        // Hitung semua link sebelum ini berdasarkan sorting invoice
        $count = self::query()
            ->join('office_types', 'bank_jatim_links.office_type_id', '=', 'office_types.id')
            ->where(function ($q) {
                $q->where('office_types.sort_order', '<', $this->officeType->sort_order)
                    ->orWhere(function ($q2) {
                        $q2->where('office_types.sort_order', '=', $this->officeType->sort_order)
                            ->where('bank_jatim_links.sequence_in_office_type', '<', $this->sequence_in_office_type);
                    });
            })
            ->where('bank_jatim_links.link_status', self::STATUS_ACTIVE)
            ->count();

        return $count + 1;
    }

    /**
     * Get next sequence number untuk Jenis Kantor
     */
    public static function getNextSequenceForOfficeType(int $officeTypeId): int
    {
        $max = self::where('office_type_id', $officeTypeId)->max('sequence_in_office_type');

        return ($max ?? 0) + 1;
    }

    /**
     * Update master data setelah invoice difinalisasi
     */
    public function applyChanges(array $changes): void
    {
        $this->fill($changes);
        $this->save();
    }

    /**
     * Renumber semua sequence_in_office_type untuk Jenis Kantor tertentu
     * Agar tidak ada nomor bolong (gap) dalam setiap location_category
     * Dihitung per location_category
     */
    public static function renumberSequencesForOfficeType(int $officeTypeId): void
    {
        // Get all unique location categories in this office type
        $categories = self::where('office_type_id', $officeTypeId)
            ->select('location_category_id')
            ->distinct()
            ->pluck('location_category_id');

        foreach ($categories as $categoryId) {
            // Renumber each category separately
            $links = self::where('office_type_id', $officeTypeId)
                ->where('location_category_id', $categoryId)
                ->orderBy('sequence_in_office_type')
                ->get();

            $sequence = 1;
            foreach ($links as $link) {
                if ($link->sequence_in_office_type != $sequence) {
                    $link->update(['sequence_in_office_type' => $sequence]);
                }
                $sequence++;
            }
        }
    }

    /**
     * Renumber sequences for a specific location category
     * Called after adding/removing SSID within a category
     */
    public static function renumberSequencesForLocationCategory(int $officeTypeId, int $locationCategoryId): void
    {
        $links = self::where('office_type_id', $officeTypeId)
            ->where('location_category_id', $locationCategoryId)
            ->orderBy('sequence_in_office_type')
            ->get();

        $sequence = 1;
        foreach ($links as $link) {
            if ($link->sequence_in_office_type != $sequence) {
                $link->update(['sequence_in_office_type' => $sequence]);
            }
            $sequence++;
        }
    }

    /**
     * Boot: Auto renumber sequence saat delete
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (BankJatimLink $link) {
            $officeTypeId = $link->office_type_id;
            $deletedSequence = $link->sequence_in_office_type;

            // Hapus semua invoice_lines yang terkait
            // Historical invoice lines retain their service identity, even after archival.

            // Renumber sequence setelah delete
            self::renumberSequencesForOfficeType($officeTypeId);
        });

        // NOTE: Sequence handling is now done in InvoicePeriodController
        // to properly manage sequences within location_category groups
        // Remove auto-assign sequence on create to avoid overwriting controller logic
    }
}
