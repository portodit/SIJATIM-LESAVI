<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceStepsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data untuk tahapan invoice Bank Jatim.
     *
     * Idempotent: each row is keyed on step_code. A step that already exists (the create
     * migration and later migrations insert and rename these rows) is left as it is, so
     * the names set by the migrations are not overwritten by the older names below.
     */
    public function run(): void
    {
        $steps = [
            [
                'step_number' => 0,
                'step_code' => 'overview',
                'step_name' => 'Overview Snapshot',
                'description' => 'Ringkasan progres dan validasi keseluruhan',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 1,
                'step_code' => 'ssid_list',
                'step_name' => 'Filling New SSID Data',
                'description' => 'Mengisi data SSID baru untuk periode ini',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 2,
                'step_code' => 'type_order',
                'step_name' => 'Input Perubahan Data SSID',
                'description' => 'Bandwidth, pemakaian average, downtime, dan harga',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 3,
                'step_code' => 'preview',
                'step_name' => 'Preview Invoice Bulan Ini',
                'description' => 'Preview data invoice periode berjalan (read-only)',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($steps as $step) {
            DB::table('invoice_steps')->updateOrInsert(
                ['step_code' => $step['step_code']],
                fn (bool $exists) => $exists ? [] : $step
            );
        }
    }
}
