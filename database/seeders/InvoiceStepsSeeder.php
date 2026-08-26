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
                'step_name' => 'Mengelola List SSID',
                'description' => 'SSID baru, perubahan data, dan duplikasi',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 2,
                'step_code' => 'type_order',
                'step_name' => 'Type Order & Periode',
                'description' => 'Existing, upgrade, downgrade, PSB, pemutusan, relokasi',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 3,
                'step_code' => 'bandwidth',
                'step_name' => 'Data Bandwidth',
                'description' => 'Bandwidth eksisting, upgrade, downgrade',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 4,
                'step_code' => 'bandwidth_benchmark',
                'step_name' => 'Benchmark Bandwidth EoS',
                'description' => 'Validasi silang dengan data titik jaringan',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 5,
                'step_code' => 'traffic_usage',
                'step_name' => 'Data Trafik Jaringan',
                'description' => 'Pemakaian average dari MRTG',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 6,
                'step_code' => 'price',
                'step_name' => 'Harga Layanan',
                'description' => 'Harga eksisting, upgrade, downgrade, PSB, prorate',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 7,
                'step_code' => 'sla',
                'step_name' => 'Data Gangguan & SLA',
                'description' => 'Downtime, SLA actual, availability',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 8,
                'step_code' => 'restitution',
                'step_name' => 'Nilai Restitusi',
                'description' => 'Restitusi tagihan dan restitusi over SLA',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 9,
                'step_code' => 'price_benchmark',
                'step_name' => 'Benchmark Harga Addendum',
                'description' => 'Validasi akhir harga sebelum finalisasi',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'step_number' => 10,
                'step_code' => 'finalization',
                'step_name' => 'Finalisasi Invoice',
                'description' => 'Generate Excel dan PDF invoice final',
                'is_required' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('invoice_steps')->insert($steps);
    }
}
