<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OfficeTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data Jenis Kantor Bank Jatim
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('office_types')->count() > 0) {
            return;
        }

        $officeTypes = [
            ['office_type_code' => 'kantor_pusat', 'office_type_name' => 'KANTOR PUSAT', 'sort_order' => 1, 'is_active' => true],
            ['office_type_code' => 'utama', 'office_type_name' => 'UTAMA', 'sort_order' => 2, 'is_active' => true],
            ['office_type_code' => 'banyuwangi', 'office_type_name' => 'BANYUWANGI', 'sort_order' => 3, 'is_active' => true],
            ['office_type_code' => 'jember', 'office_type_name' => 'JEMBER', 'sort_order' => 4, 'is_active' => true],
            ['office_type_code' => 'malang', 'office_type_name' => 'MALANG', 'sort_order' => 5, 'is_active' => true],
            ['office_type_code' => 'kepanjen', 'office_type_name' => 'KEPANJEN', 'sort_order' => 6, 'is_active' => true],
            ['office_type_code' => 'batu', 'office_type_name' => 'BATU', 'sort_order' => 7, 'is_active' => true],
            ['office_type_code' => 'madiun', 'office_type_name' => 'MADIUN', 'sort_order' => 8, 'is_active' => true],
            ['office_type_code' => 'kediri', 'office_type_name' => 'KEDIRI', 'sort_order' => 9, 'is_active' => true],
            ['office_type_code' => 'pamekasan', 'office_type_name' => 'PAMEKASAN', 'sort_order' => 10, 'is_active' => true],
            ['office_type_code' => 'bojonegoro', 'office_type_name' => 'BOJONEGORO', 'sort_order' => 11, 'is_active' => true],
            ['office_type_code' => 'lumajang', 'office_type_name' => 'LUMAJANG', 'sort_order' => 12, 'is_active' => true],
            ['office_type_code' => 'ngawi', 'office_type_name' => 'NGAWI', 'sort_order' => 13, 'is_active' => true],
            ['office_type_code' => 'jombang', 'office_type_name' => 'JOMBANG', 'sort_order' => 14, 'is_active' => true],
            ['office_type_code' => 'probolinggo', 'office_type_name' => 'PROBOLINGGO', 'sort_order' => 15, 'is_active' => true],
            ['office_type_code' => 'kraksaan', 'office_type_name' => 'KRAKSAAN', 'sort_order' => 16, 'is_active' => true],
            ['office_type_code' => 'blitar', 'office_type_name' => 'BLITAR', 'sort_order' => 17, 'is_active' => true],
            ['office_type_code' => 'tulungagung', 'office_type_name' => 'TULUNGAGUNG', 'sort_order' => 18, 'is_active' => true],
            ['office_type_code' => 'mojokerto', 'office_type_name' => 'MOJOKERTO', 'sort_order' => 19, 'is_active' => true],
            ['office_type_code' => 'tuban', 'office_type_name' => 'TUBAN', 'sort_order' => 20, 'is_active' => true],
            ['office_type_code' => 'sumenep', 'office_type_name' => 'SUMENEP', 'sort_order' => 21, 'is_active' => true],
            ['office_type_code' => 'nganjuk', 'office_type_name' => 'NGANJUK', 'sort_order' => 22, 'is_active' => true],
            ['office_type_code' => 'ponorogo', 'office_type_name' => 'PONOROGO', 'sort_order' => 23, 'is_active' => true],
            ['office_type_code' => 'pacitan', 'office_type_name' => 'PACITAN', 'sort_order' => 24, 'is_active' => true],
            ['office_type_code' => 'trenggalek', 'office_type_name' => 'TRENGGALEK', 'sort_order' => 25, 'is_active' => true],
            ['office_type_code' => 'pasuruan', 'office_type_name' => 'PASURUAN', 'sort_order' => 26, 'is_active' => true],
            ['office_type_code' => 'sampang', 'office_type_name' => 'SAMPANG', 'sort_order' => 27, 'is_active' => true],
            ['office_type_code' => 'bangkalan', 'office_type_name' => 'BANGKALAN', 'sort_order' => 28, 'is_active' => true],
            ['office_type_code' => 'gresik', 'office_type_name' => 'GRESIK', 'sort_order' => 29, 'is_active' => true],
            ['office_type_code' => 'sidoarjo', 'office_type_name' => 'SIDOARJO', 'sort_order' => 30, 'is_active' => true],
            ['office_type_code' => 'lamongan', 'office_type_name' => 'LAMONGAN', 'sort_order' => 31, 'is_active' => true],
            ['office_type_code' => 'situbondo', 'office_type_name' => 'SITUBONDO', 'sort_order' => 32, 'is_active' => true],
            ['office_type_code' => 'bondowoso', 'office_type_name' => 'BONDOWOSO', 'sort_order' => 33, 'is_active' => true],
            ['office_type_code' => 'magetan', 'office_type_name' => 'MAGETAN', 'sort_order' => 34, 'is_active' => true],
            ['office_type_code' => 'dr_sutomo', 'office_type_name' => 'Dr. SUTOMO', 'sort_order' => 35, 'is_active' => true],
            ['office_type_code' => 'perak', 'office_type_name' => 'PERAK', 'sort_order' => 36, 'is_active' => true],
            ['office_type_code' => 'jakarta', 'office_type_name' => 'JAKARTA', 'sort_order' => 37, 'is_active' => true],
            ['office_type_code' => 'kangean', 'office_type_name' => 'KANGEAN', 'sort_order' => 38, 'is_active' => true],
            ['office_type_code' => 'bawean', 'office_type_name' => 'BAWEAN', 'sort_order' => 39, 'is_active' => true],
            ['office_type_code' => 'pare', 'office_type_name' => 'PARE', 'sort_order' => 40, 'is_active' => true],
            ['office_type_code' => 'hr_muhammad', 'office_type_name' => 'Hr. MUHAMMAD', 'sort_order' => 41, 'is_active' => true],
            ['office_type_code' => 'syariah', 'office_type_name' => 'SYARIAH', 'sort_order' => 42, 'is_active' => true],
            ['office_type_code' => 'batam', 'office_type_name' => 'BATAM', 'sort_order' => 43, 'is_active' => true],
            ['office_type_code' => 'caruban', 'office_type_name' => 'CARUBAN', 'sort_order' => 44, 'is_active' => true],
        ];

        DB::table('office_types')->insert($officeTypes);
    }
}
