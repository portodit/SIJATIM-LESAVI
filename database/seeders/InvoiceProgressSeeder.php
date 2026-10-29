<?php

namespace Database\Seeders;

use App\Services\SeedDataPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the invoice progress shipped with the repository (database/seed-data) into an empty database.
 * Never overwrites a database that already holds invoice periods.
 */
class InvoiceProgressSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('invoice_periods')->exists()) {
            $this->command?->warn('Data periode invoice sudah ada di database ini, jadi data bawaan repo tidak dimuat.');
            $this->command?->warn('Gunakan `php artisan migrate:fresh --seed` untuk menggantinya dengan data dari repo.');

            return;
        }

        $result = (new SeedDataPackage)->import();

        $this->command?->info(sprintf('Memuat %d tabel (%s baris) dan %d berkas.', count($result['tables']), number_format($result['rows'], 0, ',', '.'), $result['files']));
        foreach ($result['warnings'] as $warning) {
            $this->command?->warn($warning);
        }
        $this->command?->table(
            ['Periode', 'Status', 'Baris invoice', 'Grand total'],
            array_map(fn (array $period) => [
                $period['period_code'],
                $period['status'],
                number_format($period['lines'], 0, ',', '.'),
                $period['grand_total'] === null ? '-' : number_format((float) $period['grand_total'], 2, ',', '.'),
            ], $result['periods'])
        );
        $this->command?->info('Login: test@example.com / password');
    }
}
