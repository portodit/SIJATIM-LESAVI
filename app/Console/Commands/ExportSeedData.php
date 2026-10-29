<?php

namespace App\Console\Commands;

use App\Services\SeedDataPackage;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Refreshes the "data package" (database + generated invoice/source files) that `php artisan migrate --seed`
 * loads for teammates. Reads the current database; writes only into the package folder.
 */
class ExportSeedData extends Command
{
    protected $signature = 'sijatim:export-seed-data {--path= : Folder tujuan (default database/seed-data)}';

    protected $description = 'Ekspor database dan berkas invoice saat ini ke paket data (database/seed-data) agar rekan tim mendapat progres terbaru lewat migrate --seed';

    public function handle(SeedDataPackage $package): int
    {
        $path = $this->option('path');
        $dir = $path ? (preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) ? $path : base_path($path)) : null;

        try {
            $summary = $package->export($dir);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Paket data ditulis ke '.$summary['directory']);
        $this->line(sprintf(
            'Tabel: %d | Baris: %s | Berkas: %d | Ukuran: %s MB',
            $summary['tables'],
            number_format($summary['rows'], 0, ',', '.'),
            $summary['files'],
            number_format($summary['bytes'] / 1048576, 2, ',', '.')
        ));
        $this->newLine();
        $this->warn($path
            ? 'Folder ini bukan folder bawaan, jadi tidak dipakai oleh migrate --seed. Salin ke database/seed-data bila perlu.'
            : 'Jangan lupa commit folder database/seed-data agar rekan tim mendapat data terbaru.');

        return self::SUCCESS;
    }
}
