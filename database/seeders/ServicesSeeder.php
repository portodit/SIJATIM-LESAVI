<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data jenis layanan konektivitas Telkom
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('services')->count() > 0) {
            return;
        }

        $services = [
            [
                'service_code' => 'telkom_vpn_ip',
                'service_name' => 'TELKOM VPN IP',
                'description' => 'Layanan VPN IP dari Telkom untuk koneksi aman antar lokasi',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'service_code' => 'telkom_metro',
                'service_name' => 'TELKOM METRO',
                'description' => 'Layanan Metro Ethernet dari Telkom untuk koneksi bandwidth tinggi',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'service_code' => 'telkom_astinet',
                'service_name' => 'TELKOM ASTINET',
                'description' => 'Layanan Astinet dari Telkom untuk koneksi internet dedicated',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'service_code' => 'telkom_managed_network',
                'service_name' => 'TELKOM MANAGED NETWORK',
                'description' => 'Layanan Managed Network dari Telkom dengan pengelolaan penuh',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'service_code' => 'telkom_plasatron',
                'service_name' => 'TELKOM PLASATRON',
                'description' => 'Layanan Plasatron dari Telkom untuk koneksi fiber optik',
                'sort_order' => 5,
                'is_active' => true,
            ],
        ];

        DB::table('services')->insert($services);
    }
}
