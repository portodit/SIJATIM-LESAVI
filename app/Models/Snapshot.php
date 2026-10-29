<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Snapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'snapshot_code',
        'period_month',
        'period_year',
        'status',
        'current_step',
        'step_statuses',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'integer',
        'period_year' => 'integer',
        'current_step' => 'integer',
        'step_statuses' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(SnapshotLocation::class);
    }

    public function traffics(): HasMany
    {
        return $this->hasMany(SnapshotTraffic::class);
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(SnapshotDowntime::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function getPeriodAttribute()
    {
        $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $bulan[$this->period_month].' '.$this->period_year;
    }

    public function getProgressStepsAttribute()
    {
        $steps = [
            'input' => $this->locations()->count() > 0 || $this->traffics()->count() > 0,
            'validation' => false,
            'calculation' => false,
            'generate' => $this->invoices()->count() > 0,
        ];

        // Validation is complete if all locations have matching traffic data
        $steps['validation'] = $this->locations()->count() > 0 &&
                               $this->traffics()->count() >= $this->locations()->count() * 0.8;

        // Calculation is complete if validation is done
        $steps['calculation'] = $steps['validation'];

        return $steps;
    }

    public function getProgressPercentageAttribute()
    {
        $steps = $this->progress_steps;
        $completed = count(array_filter($steps));

        return round(($completed / 4) * 100);
    }

    // Step definitions (1-9, no overview)
    public static function getStepDefinitions(): array
    {
        return [
            1 => ['name' => 'Mengelola list SSID seluruh lokasi', 'desc' => 'SSID baru, perubahan data, dan duplikasi'],
            2 => ['name' => 'Mengisi type order dan periode', 'desc' => 'Existing, upgrade, downgrade, PSB, pemutusan, relokasi'],
            3 => ['name' => 'Mengisi data bandwidth', 'desc' => 'Bandwidth eksisting, upgrade, dan downgrade'],
            4 => ['name' => 'Benchmarking bandwidth dengan EoS', 'desc' => 'Validasi silang dengan data titik jaringan'],
            5 => ['name' => 'Mengisi data trafik jaringan', 'desc' => 'Pemakaian average dari MRTG'],
            6 => ['name' => 'Mengisi dan menghitung harga layanan', 'desc' => 'Harga eksisting, upgrade, downgrade, PSB, prorate'],
            7 => ['name' => 'Mengisi data gangguan layanan dan SLA', 'desc' => 'Downtime, SLA actual, dan availability'],
            8 => ['name' => 'Menghitung nilai restitusi', 'desc' => 'Restitusi tagihan dan restitusi over SLA'],
            9 => ['name' => 'Benchmarking harga dengan addendum', 'desc' => 'Validasi akhir harga sebelum finalisasi'],
        ];
    }

    public static function getTotalSteps(): int
    {
        return 9;
    }

    public function getStepStatus(string $step): string
    {
        $statuses = $this->step_statuses ?? [];

        return $statuses[$step] ?? 'pending';
    }

    public function setStepStatus(int $step, string $status): void
    {
        $statuses = $this->step_statuses ?? [];
        $statuses[$step] = $status;
        $this->step_statuses = $statuses;
        $this->save();
    }

    public function getCompletedStepsCount(): int
    {
        $statuses = $this->step_statuses ?? [];

        return count(array_filter($statuses, fn ($s) => $s === 'completed'));
    }

    public function getCurrentStep(): int
    {
        return $this->current_step ?? 0;
    }

    public function getNextStep(): ?int
    {
        $totalSteps = count(self::getStepDefinitions()) - 1;
        for ($i = $this->getCurrentStep(); $i <= $totalSteps; $i++) {
            if ($this->getStepStatus($i) !== 'completed') {
                return $i;
            }
        }

        return null;
    }

    public function getProgressPercentageNewAttribute(): int
    {
        $totalSteps = count(self::getStepDefinitions());
        $completed = $this->getCompletedStepsCount();

        return round(($completed / $totalSteps) * 100);
    }
}
