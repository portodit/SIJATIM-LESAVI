<?php

namespace App\Console\Commands;

use App\Models\BandwidthOption;
use App\Models\InvoicePeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Corrects the starting bandwidth of services in one open period, and the master SSID list,
 * from an operator-supplied list (SSID, bandwidth). Preview by default; --apply writes.
 */
class FixServiceBandwidth extends Command
{
    protected $signature = 'billing:fix-bandwidth
        {period : Kode periode, misalnya AGU26}
        {file : CSV atau XLSX dengan dua kolom: SSID dan bandwidth (baris judul boleh ada)}
        {--apply : Simpan perubahan. Tanpa opsi ini hanya pratinjau.}';

    protected $description = 'Koreksi bandwidth SSID pada satu periode dan di Master Daftar SSID dari daftar SSID + bandwidth';

    public function handle(): int
    {
        $period = InvoicePeriod::where('period_code', strtoupper((string) $this->argument('period')))->first();
        $file = (string) $this->argument('file');
        if (! $period || ! is_file($file)) {
            $this->error($period ? 'File tidak ditemukan: '.$file : 'Periode tidak ditemukan.');

            return self::FAILURE;
        }
        if ($period->isLocked()) {
            $this->error('Periode '.$period->period_code.' sudah terkunci. Buka revisi terlebih dahulu.');

            return self::FAILURE;
        }

        $options = BandwidthOption::all()->mapWithKeys(fn ($b) => [self::key($b->bandwidth_name) => $b]);
        $lines = $period->lines()->with('bankJatimLink')->get()->keyBy(fn ($l) => $l->billingSid());
        $wanted = [];
        $errors = [];
        foreach ($this->rows($file) as $number => [$sid, $bandwidth]) {
            $option = $bandwidth === '' ? null : $options[self::key($bandwidth)] ?? null;
            if (isset($wanted[$sid])) {
                $errors[] = "Baris $number: SSID $sid muncul lebih dari sekali.";
            } elseif (! $option) {
                $errors[] = "Baris $number: bandwidth \"$bandwidth\" tidak ada di Master.";
            } elseif (! $lines->has($sid)) {
                $errors[] = "Baris $number: SSID $sid tidak ada di periode {$period->period_code}.";
            } else {
                $wanted[$sid] = $option;
            }
        }
        if ($errors || ! $wanted) {
            foreach (array_slice($errors, 0, 30) as $error) {
                $this->error($error);
            }
            $this->error(($errors ? count($errors).' masalah' : 'File kosong').'. Tidak ada yang disimpan.');

            return self::FAILURE;
        }

        $names = BandwidthOption::pluck('bandwidth_name', 'id');
        $periodChanges = [];
        $masterChanges = [];
        foreach ($wanted as $sid => $option) {
            $line = $lines[$sid];
            $current = $line->service_snapshot['bandwidth_option_id'] ?? $line->existing_bandwidth_option_id;
            if ((int) $current !== $option->id) {
                $periodChanges[$sid] = ['from' => $current, 'to' => $option->id];
            }
            if ($line->bankJatimLink && (int) $line->bankJatimLink->current_bandwidth_option_id !== $option->id) {
                $masterChanges[$sid] = $line->bankJatimLink;
            }
        }
        $missing = $lines->keys()->diff(array_keys($wanted));

        $this->info("Periode {$period->period_code}: ".count($wanted).' SSID di file, '.count($periodChanges).' bandwidth berubah.');
        $this->info('Master Daftar SSID: '.count($masterChanges).' SSID diisi/diubah.');
        if ($missing->isNotEmpty()) {
            $this->warn($missing->count().' SSID periode tidak ada di file dan tidak diubah: '.$missing->take(10)->implode(', ').($missing->count() > 10 ? ', ...' : ''));
        }
        $this->table(['SSID', 'Sekarang', 'Menjadi'], collect($periodChanges)->take(20)->map(fn ($c, $sid) => [$sid, $names[$c['from']] ?? '(kosong)', $names[$c['to']]])->values()->all());
        if (count($periodChanges) > 20) {
            $this->line('... dan '.(count($periodChanges) - 20).' lainnya.');
        }
        if (! $this->option('apply')) {
            $this->warn('Pratinjau saja, belum ada yang disimpan. Jalankan ulang dengan --apply untuk menyimpan.');

            return self::SUCCESS;
        }

        $hash = hash_file('sha256', $file);
        DB::transaction(function () use ($period, $lines, $wanted, $periodChanges, $masterChanges, $file, $hash) {
            foreach ($periodChanges as $sid => $change) {
                $line = $lines[$sid];
                $snapshot = $line->service_snapshot;
                if ($snapshot) {
                    $snapshot['bandwidth_option_id'] = $change['to'];
                    $line->service_snapshot = $snapshot;
                }
                $line->existing_bandwidth_option_id = $change['to'];
                $line->save();
            }
            foreach ($masterChanges as $sid => $master) {
                $master->update(['current_bandwidth_option_id' => $wanted[$sid]->id]);
            }
            DB::table('billing_audit_events')->insert(['invoice_period_id' => $period->id, 'action' => 'bandwidth_corrected_from_list', 'details' => json_encode(['file' => basename($file), 'sha256' => $hash, 'period_changes' => $periodChanges, 'master_changes' => array_keys($masterChanges)]), 'user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            if ($periodChanges) {
                $period->markInputsChanged('bandwidth_corrected', ['file' => basename($file), 'sha256' => $hash, 'changed' => count($periodChanges)]);
            }
        });
        $this->info('Tersimpan. Buka periode '.$period->period_code.' lalu klik Hitung ulang.');

        return self::SUCCESS;
    }

    /**
     * "512 kbps", "512 KbPS", " 100 Mbps " -> "512 kbps" / "100 mbps"; "0", "-" and "tidak berlaku" are the same.
     */
    public static function key(string $value): string
    {
        $value = strtolower(preg_replace('/\s+/', ' ', trim($value)));
        if (in_array($value, ['0', '-', 'tidak berlaku', 'n/a'], true)) {
            return 'tidak berlaku';
        }

        return preg_replace('/^([\d.,]+)\s*(kbps|mbps)$/', '$1 $2', $value);
    }

    /**
     * @return array<int, array{0: string, 1: string}> file row number => [SSID, bandwidth]
     */
    private function rows(string $file): array
    {
        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            $book = IOFactory::load($file);
            $raw = $book->getActiveSheet()->toArray(null, true, true, false);
            $book->disconnectWorksheets();
        } else {
            $raw = array_map(function ($line) {
                $delimiter = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : ',');

                return str_getcsv($line, $delimiter, '"', '');
            }, file($file, FILE_IGNORE_NEW_LINES));
        }
        $rows = [];
        foreach ($raw as $i => $row) {
            $sid = trim((string) ($row[0] ?? ''), " \t\xEF\xBB\xBF");
            $bandwidth = trim((string) ($row[1] ?? ''));
            if (($sid === '' && $bandwidth === '') || ($i === 0 && strtolower($sid) === 'ssid')) {
                continue;
            }
            $rows[$i + 1] = [$sid, $bandwidth];
        }

        return $rows;
    }
}
