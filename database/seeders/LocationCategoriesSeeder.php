<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data Kategori Lokasi Bank Jatim
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('location_categories')->count() > 0) {
            return;
        }

        $categories = [
            ['location_category_code' => 'internet_kantor_pusat', 'location_category_name' => 'INTERNET KANTOR PUSAT', 'sort_order' => 1, 'is_active' => true],
            ['location_category_code' => 'wan_kantor_pusat', 'location_category_name' => 'WAN KANTOR PUSAT', 'sort_order' => 2, 'is_active' => true],
            ['location_category_code' => 'colocation', 'location_category_name' => 'COLOCATION', 'sort_order' => 3, 'is_active' => true],
            ['location_category_code' => 'h2h', 'location_category_name' => 'H2H', 'sort_order' => 4, 'is_active' => true],
            ['location_category_code' => 'aplikasi_bi_rtgs_ssss_etp_bi', 'location_category_name' => 'Aplikasi BI-RTGS/SSSS/ETP BI', 'sort_order' => 5, 'is_active' => true],
            ['location_category_code' => 'spp_online', 'location_category_name' => 'SPP ONLINE', 'sort_order' => 6, 'is_active' => true],
            ['location_category_code' => 'call_center_infomedia', 'location_category_name' => 'CALL CENTER INFOMEDIA', 'sort_order' => 7, 'is_active' => true],
            ['location_category_code' => 'dc', 'location_category_name' => 'DC', 'sort_order' => 8, 'is_active' => true],
            ['location_category_code' => 'drc', 'location_category_name' => 'DRC', 'sort_order' => 9, 'is_active' => true],
            ['location_category_code' => 'h2h_metro', 'location_category_name' => 'H2H METRO', 'sort_order' => 10, 'is_active' => true],
            ['location_category_code' => 'jkd_bi', 'location_category_name' => 'JKD BI', 'sort_order' => 11, 'is_active' => true],
            ['location_category_code' => 'kantor_kas', 'location_category_name' => 'KANTOR KAS', 'sort_order' => 12, 'is_active' => true],
            ['location_category_code' => 'samsat_online', 'location_category_name' => 'SAMSAT ONLINE', 'sort_order' => 13, 'is_active' => true],
            ['location_category_code' => 'uptb', 'location_category_name' => 'UPTB', 'sort_order' => 14, 'is_active' => true],
            ['location_category_code' => 'capem', 'location_category_name' => 'CAPEM', 'sort_order' => 15, 'is_active' => true],
            ['location_category_code' => 'atm', 'location_category_name' => 'ATM', 'sort_order' => 16, 'is_active' => true],
            ['location_category_code' => 'payment_point', 'location_category_name' => 'PAYMENT POINT', 'sort_order' => 17, 'is_active' => true],
            ['location_category_code' => 'kantor_fungsional', 'location_category_name' => 'KANTOR FUNGSIONAL', 'sort_order' => 18, 'is_active' => true],
            ['location_category_code' => 'crm', 'location_category_name' => 'CRM', 'sort_order' => 19, 'is_active' => true],
            ['location_category_code' => 'cabang', 'location_category_name' => 'CABANG', 'sort_order' => 20, 'is_active' => true],
            ['location_category_code' => 'kpp', 'location_category_name' => 'KPP', 'sort_order' => 21, 'is_active' => true],
            ['location_category_code' => 'pbb', 'location_category_name' => 'PBB', 'sort_order' => 22, 'is_active' => true],
        ];

        DB::table('location_categories')->insert($categories);
    }
}
