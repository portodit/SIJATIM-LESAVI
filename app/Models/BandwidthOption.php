<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BandwidthOption extends Model
{
    use HasFactory;

    protected $table = 'bandwidth_options';

    protected $fillable = [
        'bandwidth_code',
        'bandwidth_name',
        'value_kbps',
        'description',
        'is_not_applicable',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'value_kbps' => 'integer',
        'sort_order' => 'integer',
        'is_not_applicable' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Scope: Hanya yang aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Hanya bandwidth normal (bukan N/A)
     */
    public function scopeNormal($query)
    {
        return $query->where('is_not_applicable', false);
    }

    /**
     * Scope: Order by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Scope: Order by value_kbps (numeric)
     */
    public function scopeOrderByValue($query)
    {
        return $query->orderBy('value_kbps');
    }

    /**
     * Cek apakah bandwidth ini adalah "Tidak Berlaku"
     */
    public function isNotApplicable(): bool
    {
        return $this->is_not_applicable;
    }

    /**
     * Get BandwidthOption by code
     */
    public static function getByCode(string $code): ?self
    {
        return self::where('bandwidth_code', $code)->first();
    }

    /**
     * Get BandwidthOption by value_kbps
     */
    public static function getByKbps(int $value): ?self
    {
        return self::where('value_kbps', $value)->first();
    }

    /**
     * Convert string bandwidth to Kbps
     * Contoh: "10 Mbps" -> 10240, "512 Kbps" -> 512
     */
    public static function parseToKbps(string $value): ?int
    {
        $value = trim($value);

        // Pattern untuk Mbps
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*Mbps$/i', $value, $matches)) {
            $mbps = (float) str_replace(',', '.', $matches[1]);

            return (int) round($mbps * 1024);
        }

        // Pattern untuk Kbps
        if (preg_match('/^(\d+)\s*Kbps$/i', $value, $matches)) {
            return (int) $matches[1];
        }

        // Pattern untuk Gbps
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*Gbps$/i', $value, $matches)) {
            $gbps = (float) str_replace(',', '.', $matches[1]);

            return (int) round($gbps * 1024 * 1024);
        }

        // Jika hanya angka
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Get formatted bandwidth name
     */
    public function getFormattedNameAttribute(): string
    {
        if ($this->is_not_applicable) {
            return $this->bandwidth_name;
        }

        return $this->bandwidth_name;
    }
}
