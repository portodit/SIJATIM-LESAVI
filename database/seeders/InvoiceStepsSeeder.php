<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceStepsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data untuk tahapan invoice Bank Jatim
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('invoice_steps')->count() > 0) {
            return;
        }

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

        DB::table('invoice_steps')->insert($steps);
    }
}
