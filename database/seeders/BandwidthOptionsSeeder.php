<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BandwidthOptionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data pilihan bandwidth
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('bandwidth_options')->count() > 0) {
            return;
        }

        $bandwidths = [
            [
                'bandwidth_code' => 'not_applicable',
                'bandwidth_name' => 'TIDAK BERLAKU',
                'value_kbps' => null,
                'description' => 'Untuk layanan seperti COLOCATION atau TELKOM PLASATRON yang tidak memiliki bandwidth jaringan',
                'is_not_applicable' => true,
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '32_kbps',
                'bandwidth_name' => '32 Kbps',
                'value_kbps' => 32,
                'description' => 'Bandwidth 32 Kbps',
                'is_not_applicable' => false,
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '128_kbps',
                'bandwidth_name' => '128 Kbps',
                'value_kbps' => 128,
                'description' => 'Bandwidth 128 Kbps',
                'is_not_applicable' => false,
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '256_kbps',
                'bandwidth_name' => '256 Kbps',
                'value_kbps' => 256,
                'description' => 'Bandwidth 256 Kbps',
                'is_not_applicable' => false,
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '512_kbps',
                'bandwidth_name' => '512 Kbps',
                'value_kbps' => 512,
                'description' => 'Bandwidth 512 Kbps',
                'is_not_applicable' => false,
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '1_mbps',
                'bandwidth_name' => '1 Mbps',
                'value_kbps' => 1024,
                'description' => 'Bandwidth 1 Mbps (1024 Kbps)',
                'is_not_applicable' => false,
                'sort_order' => 6,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '2_mbps',
                'bandwidth_name' => '2 Mbps',
                'value_kbps' => 2048,
                'description' => 'Bandwidth 2 Mbps (2048 Kbps)',
                'is_not_applicable' => false,
                'sort_order' => 7,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '10_mbps',
                'bandwidth_name' => '10 Mbps',
                'value_kbps' => 10240,
                'description' => 'Bandwidth 10 Mbps (10240 Kbps)',
                'is_not_applicable' => false,
                'sort_order' => 8,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '12_mbps',
                'bandwidth_name' => '12 Mbps',
                'value_kbps' => 12288,
                'description' => 'Bandwidth 12 Mbps (12288 Kbps)',
                'is_not_applicable' => false,
                'sort_order' => 9,
                'is_active' => true,
            ],
            [
                'bandwidth_code' => '100_mbps',
                'bandwidth_name' => '100 Mbps',
                'value_kbps' => 102400,
                'description' => 'Bandwidth 100 Mbps (102400 Kbps)',
                'is_not_applicable' => false,
                'sort_order' => 10,
                'is_active' => true,
            ],
        ];

        DB::table('bandwidth_options')->insert($bandwidths);
    }
}
