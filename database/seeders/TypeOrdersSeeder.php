<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TypeOrdersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master data Type Order.
     *
     * Idempotent: each row is keyed on type_order_code, so it also works on a fresh
     * database where a migration has already inserted 'multiple_changes'.
     */
    public function run(): void
    {
        $typeOrders = [
            [
                'type_order_code' => 'existing',
                'type_order_name' => 'EXISTING',
                'description' => 'Layanan yang sudah aktif sebelum periode invoice dan tidak mengalami perubahan. Tagihan dihitung penuh untuk satu periode.',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'type_order_code' => 'upgrade',
                'type_order_name' => 'UPGRADE',
                'description' => 'Bandwidth layanan dinaikkan pada pertengahan periode. Tagihan dihitung prorata: harga lama × hari lama + harga baru × hari baru.',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'type_order_code' => 'pasang_baru',
                'type_order_name' => 'PASANG BARU',
                'description' => 'Layanan baru yang mulai aktif setelah periode invoice berjalan. Tagihan dihitung prorata mulai tanggal aktivasi.',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'type_order_code' => 'relokasi',
                'type_order_name' => 'RELOKASI',
                'description' => 'Layanan berpindah lokasi pada periode berjalan. Tagihan biasanya tetap normal atau sesuai kebijakan.',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'type_order_code' => 'downgrade',
                'type_order_name' => 'DOWNGRADE',
                'description' => 'Bandwidth layanan diturunkan pada pertengahan periode. Tagihan dihitung prorata: harga lama × hari lama + harga baru × hari baru.',
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'type_order_code' => 'pemutusan',
                'type_order_name' => 'PEMUTUSAN',
                'description' => 'Layanan dihentikan pada pertengahan periode. Tagihan dihitung prorata mulai tanggal 1 sampai tanggal pemutusan.',
                'sort_order' => 6,
                'is_active' => true,
            ],
        ];

        foreach ($typeOrders as $row) {
            DB::table('type_orders')->updateOrInsert(
                ['type_order_code' => $row['type_order_code']],
                fn (bool $exists) => $exists
                    ? $row + ['updated_at' => now()]
                    : $row + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
