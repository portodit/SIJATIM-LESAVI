<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingProfile extends Model
{
    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function ($profile) {
            $settings = $profile->settings ?? [];
            $settings['sla_target'] = \App\Services\Billing\BillingMath::SLA_TARGET;
            $profile->settings = $settings;
        });
        static::updating(function ($profile) {
            if ($profile->isDirty(['settings', 'name', 'version'])) {
                throw new \LogicException('Buat versi profil baru untuk mengubah aturan billing.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Profil billing harus diarsipkan, bukan dihapus.'));
    }
}
