<?php

namespace App\Services\Billing;

use App\Models\InvoicePeriod;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class WorkbookSourceReader
{
    public const KINDS = ['inventory', 'events', 'tariffs', 'outages', 'incidents', 'traffic', 'adjustments', 'notes', 'workbook'];

    public const TEMPLATES = [
        'inventory' => ['sid', 'branch', 'category', 'service', 'bandwidth', 'address', 'ip', 'activation_date', 'billing_start_date', 'billing_end_date', 'router_mode', 'physical_router_status', 'router_billing_from', 'contract_number'],
        'events' => ['sid', 'event_type', 'effective_date', 'bandwidth', 'new_address', 'fee'],
        'tariffs' => ['category', 'service', 'bandwidth', 'router_mode', 'sid', 'monthly_price', 'installation_price', 'relocation_price', 'effective_start_date', 'effective_end_date', 'contract_number'],
        'outages' => ['sid', 'kind', 'opened_at', 'closed_at', 'duration_seconds', 'ticket', 'review_status', 'reason', 'usage_start', 'usage_end', 'coverage_start', 'coverage_end'],
        'incidents' => ['sid', 'opened_at', 'closed_at', 'ticket', 'reason', 'usage_start', 'usage_end'],
        'traffic' => ['sid', 'inbound_kbps', 'outbound_kbps', 'coverage_start', 'coverage_end'],
        'adjustments' => ['sid', 'kind', 'amount', 'reason', 'reference'],
        'notes' => ['note'],
    ];

    public function read(string $path, string $kind, InvoicePeriod $period): array
    {
        $probe = fopen($path, 'rb');
        $signature = fread($probe, 4);
        fclose($probe);
        if (! str_starts_with($signature, 'PK') && $signature !== "\xD0\xCF\x11\xE0") {
            $handle = fopen($path, 'r');
            $firstLine = fgets($handle);
            rewind($handle);
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            $headers = array_map(fn ($h) => SourceValue::header(ltrim($h, "\xEF\xBB\xBF")), fgetcsv($handle, 0, $delimiter, '"', ''));
            $simpleDowntimeTemplate = $kind === 'outages' && count(array_filter($headers)) === 2 && in_array('sid', $headers, true) && in_array('duration_seconds', $headers, true);
            $records = [];
            $number = 1;
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $number++;
                if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }
                $d = ['_number_format' => 'decimal'];
                foreach ($headers as $i => $h) {
                    if ($h) {
                        $d[$h] = $row[$i] ?? null;
                    }
                }
                if ($simpleDowntimeTemplate) {
                    $d['_manual_total_template'] = true;
                }
                $this->record($records, $kind, 'CSV', $number, $d, $row);
            }
            fclose($handle);
            if (! $records) {
                throw new \InvalidArgumentException('File sumber kosong.');
            }

            return $records;
        }
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $book = $reader->load($path);
        $records = [];
        $hasMain = false;
        foreach ($book->getWorksheetIterator() as $candidate) {
            if (str_contains(strtoupper((string) $candidate->getCell('F3')->getValue()), 'TYPE ORDER')) {
                $hasMain = true;
            }
        }
        foreach ($book->getWorksheetIterator() as $sheet) {
            $title = $sheet->getTitle();
            $rows = [];
            $last = $sheet->getHighestDataRow();
            $columns = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($last > 50000 || $columns > 91) {
                $book->disconnectWorksheets();
                throw new \InvalidArgumentException('Sheet '.$title.' melebihi batas 50.000 baris / 91 kolom. Pecah sumber menjadi file terpisah.');
            }
            for ($r = 1; $r <= $last; $r++) {
                $values = [];
                for ($c = 1; $c <= $columns; $c++) {
                    if (! $sheet->cellExists([$c, $r])) {
                        $values[$c] = null;

                        continue;
                    }
                    $cell = $sheet->getCell([$c, $r]);
                    $value = $cell->getValue();
                    // Cached formula values are source claims only; never execute workbook formulas.
                    $values[$c] = $cell->getDataType() === 'f' ? $cell->getOldCalculatedValue() : $value;
                }
                if (count(array_filter($values, fn ($v) => $v !== null && $v !== ''))) {
                    $rows[$r] = $values;
                }
            }
            $first = reset($rows) ?: [];
            $headers = array_map([SourceValue::class, 'header'], $first);
            $normalizedHeader = array_key_first($rows);
            foreach (array_slice($rows, 0, 20, true) as $r => $candidate) {
                $h = array_map([SourceValue::class, 'header'], $candidate);
                if (in_array('sid', $h, true) && (in_array('event_type', $h, true) || in_array('inbound_kbps', $h, true) || in_array('branch', $h, true) || in_array('duration_seconds', $h, true) || in_array('amount', $h, true) || in_array('monthly_price', $h, true))) {
                    $headers = $h;
                    $normalizedHeader = $r;
                    break;
                }
            }
            $simpleDowntimeTemplate = $kind === 'outages' && count(array_filter($headers)) === 2 && in_array('sid', $headers, true) && in_array('duration_seconds', $headers, true);
            if (in_array('sid', $headers, true) && (in_array('event_type', $headers, true) || in_array('inbound_kbps', $headers, true) || in_array('branch', $headers, true) || in_array('duration_seconds', $headers, true) || in_array('amount', $headers, true) || in_array('monthly_price', $headers, true) || in_array('opened_at', $headers, true))) {
                foreach (array_filter($rows, fn ($r) => $r > $normalizedHeader, ARRAY_FILTER_USE_KEY) as $r => $row) {
                    $data = ['_number_format' => 'decimal'];
                    foreach ($headers as $c => $header) {
                        if ($header) {
                            $data[$header] = $row[$c] ?? null;
                        }
                    }
                    if ($simpleDowntimeTemplate) {
                        $data['_manual_total_template'] = true;
                    }
                    $tableKind = $kind === 'workbook' ? (in_array('event_type', $headers, true) ? 'events' : (in_array('inbound_kbps', $headers, true) ? 'traffic' : (in_array('monthly_price', $headers, true) ? 'tariffs' : (in_array('duration_seconds', $headers, true) ? 'outages' : (in_array('amount', $headers, true) ? 'adjustments' : 'inventory'))))) : $kind;
                    $this->record($records, $tableKind, $title, $r, $data, $row);
                }

                continue;
            }
            if ($kind === 'notes' && in_array('note', $headers, true)) {
                foreach (array_slice($rows, 1, null, true) as $r => $row) {
                    $this->record($records, 'notes', $title, $r, ['note' => implode(' ', array_filter($row, fn ($v) => $v !== null))], $row);
                }

                continue;
            }
            $role = $this->role($title, $rows);
            if (in_array($kind, ['events', 'workbook'])) {
                $this->eventLists($records, $title, $rows);
            }
            if (! $role || ($kind !== 'workbook' && $kind !== $role && ! ($kind === 'events' && $role === 'inventory'))) {
                continue;
            }
            if ($role === 'inventory') {
                $main = $this->isMain($rows);
                if ($main) {
                    foreach ($rows as $r => $row) {
                        if ($r < 6 || empty($row[7]) || ! is_numeric($row[1] ?? null)) {
                            continue;
                        }
                        $order = SourceValue::order($row[6] ?? 'existing');
                        $usageToken = (string) ($row[8] ?? '');
                        $usageStart = preg_match('/^(20\d{2})(0[1-9]|1[0-2])$/', $usageToken, $m) ? $m[1].'-'.$m[2].'-01' : null;
                        if ($kind !== 'events') {
                            $this->record($records, 'inventory', $title, $r, ['sid' => $row[7], 'branch' => $row[2], 'address' => $row[3], 'category' => $row[4], 'service' => $row[5], 'bandwidth' => $row[9], 'ip' => $row[12], 'activation_date' => $row[13], 'billing_start_date' => $order === 'pasang_baru' ? $row[14] : null, 'router_mode' => 'none', 'usage_start' => $usageStart, 'reference_price' => $row[25], 'reference_downtime_seconds' => $row[19]], $row);
                        }
                        if ($kind === 'events' || $kind === 'workbook') {
                            if ($order !== 'existing') {
                                $this->record($records, 'events', $title, $r, ['sid' => $row[7], 'event_type' => $order, 'effective_date' => $row[14], 'bandwidth' => $row[$order === 'upgrade' ? 10 : ($order === 'downgrade' ? 11 : 9)] ?? null, 'fee' => $order === 'relokasi' ? ($row[30] ?? null) : null], $row);
                            }
                        }
                    }
                }
                // Master/DATABASE sheets are supplementary evidence when the main inventory exists.
                else {
                    $database = str_contains(strtolower($title), 'database');
                    foreach ($rows as $r => $row) {
                        if ($r <= ($database ? 1 : 2)) {
                            continue;
                        }
                        $sid = $row[$database ? 1 : 4] ?? null;
                        if (! $sid || strlen((string) $sid) < 6) {
                            continue;
                        }
                        $d = $database ? ['sid' => $sid, 'address' => $row[2] ?? null, 'category' => $row[3] ?? null, 'ip' => $row[4] ?? null, 'activation_date' => $row[5] ?? null, 'physical_router_status' => $row[6] ?? 'unknown'] : ['sid' => $sid, 'branch' => $row[2] ?? null, 'category' => $row[3] ?? null, 'bandwidth' => $row[5] ?? null, 'address' => $row[8] ?? null, 'ip' => $row[9] ?? null, 'reference_price' => $row[11] ?? null];
                        $this->record($records, $hasMain && $kind === 'workbook' ? 'reference' : 'inventory', $title, $r, $d, $row);
                    }
                }
            } elseif ($role === 'traffic') {
                $reported = SourceValue::reportedMonth(implode(' ', $rows[2] ?? []));
                foreach ($rows as $r => $row) {
                    if ($r < 5 || empty($row[4]) || ! is_numeric($row[2] ?? null)) {
                        continue;
                    }
                    $begin = $reported ?? $period->start_date->toDateString();
                    $this->record($records, 'traffic', $title, $r, ['sid' => $row[4], 'inbound_kbps' => $row[6], 'outbound_kbps' => $row[7], 'coverage_start' => $begin, 'coverage_end' => Carbon::parse($begin)->endOfMonth()->toDateString()], $row);
                }
            } elseif ($role === 'outages') {
                $this->downtime($records, $title, $rows, $period);
            } elseif ($role === 'incidents') {
                $this->incidents($records, $title, $rows, $period);
            } elseif ($role === 'tariffs') {
                $this->tariffs($records, $title, $rows, $period);
            } elseif ($role === 'notes') {
                foreach ($rows as $r => $row) {
                    $this->record($records, 'notes', $title, $r, ['note' => implode(' | ', array_filter($row, fn ($v) => $v !== null && $v !== ''))], $row);
                }
            }
            if ($kind === 'workbook' && (str_contains(strtolower($title), 'inv') || str_contains(strtolower($title), 'summary'))) {
                foreach ($rows as $r => $row) {
                    $label = strtolower(implode(' ', array_filter($row, fn ($v) => is_string($v))));
                    $key = null;
                    if (str_contains($label, 'restitusi')) {
                        $key = 'sla_credit';
                    } elseif (str_contains($label, 'grand total') || str_contains($label, 'total tagihan') || str_contains($label, 'total bayar')) {
                        $key = 'grand_total';
                    } elseif (str_contains($label, 'ppn')) {
                        $key = 'tax';
                    } elseif (str_contains($label, 'sub total') || str_contains($label, 'subtotal')) {
                        $key = 'subtotal';
                    }
                    if ($key) {
                        $numbers = array_filter($row, fn ($v) => is_int($v) || is_float($v));
                        if ($numbers) {
                            $value = end($numbers);
                            $this->record($records, 'reference', $title, $r, ['total_key' => $key, 'amount' => (string) abs($value)], $row);
                        }
                    }
                }
            }
        }
        $book->disconnectWorksheets();
        if (! $records) {
            throw new \InvalidArgumentException('Tidak ditemukan tabel sumber yang sesuai. Gunakan template atau pilih jenis import yang benar.');
        }

        return $records;
    }

    private function role(string $name, array $rows): ?string
    {
        $n = strtolower($name);
        if (str_contains($n, 'avg mrtg')) {
            return 'traffic';
        }
        if (str_contains($n, 'downtime')) {
            return 'outages';
        }
        if (str_contains(preg_replace('/\s+/', '', $n), 'rekapggn')) {
            return 'incidents';
        }
        if (str_contains($n, 'add ii')) {
            return 'tariffs';
        }
        if (str_contains($n, 'inv') || str_contains($n, 'summary')) {
            return 'reference';
        }
        if ($this->isMain($rows) || str_contains($n, 'database') || str_contains($n, 'master jaringan')) {
            return 'inventory';
        }
        if ($n === 'note') {
            return 'notes';
        }

        return null;
    }

    private function eventLists(array &$records, string $title, array $rows): void
    {
        $section = null;
        $columns = null;
        foreach ($rows as $number => $row) {
            $text = SourceValue::header(implode(' ', array_filter($row, fn ($v) => is_string($v))));
            foreach (['pasang_baru', 'pemutusan', 'upgrade', 'downgrade', 'relokasi', 'cabut'] as $type) {
                if ($text === $type || $text === 'daftar_'.$type) {
                    $section = SourceValue::order($type);
                }
            }
            $h = array_map([SourceValue::class, 'header'], $row);
            $sid = array_search('sid', $h, true);
            if ($sid === false) {
                $sid = array_search('nojar', $h, true);
            }
            $date = false;
            foreach (['tanggal_efektif', 'tgl_efektif', 'effective_date', 'tanggal_billing', 'tanggal_perubahan'] as $label) {
                if (($i = array_search($label, $h, true)) !== false) {
                    $date = $i;
                    break;
                }
            }
            if ($sid !== false && $date !== false) {
                $columns = ['sid' => $sid, 'effective_date' => $date];
                foreach (['type_order' => 'event_type', 'jenis_perubahan' => 'event_type', 'event_type' => 'event_type', 'bandwidth' => 'bandwidth', 'biaya' => 'fee', 'alamat_baru' => 'new_address'] as $from => $to) {
                    if (($i = array_search($from, $h, true)) !== false) {
                        $columns[$to] = $i;
                    }
                }

                continue;
            }
            if (! $columns || empty($row[$columns['sid']]) || empty($row[$columns['effective_date']])) {
                continue;
            }
            $d = ['event_type' => $section];
            foreach ($columns as $key => $i) {
                $d[$key] = $row[$i] ?? null;
            }
            if (! $d['event_type']) {
                continue;
            }
            $this->record($records, 'events', $title, $number, $d, $row);
        }
    }

    private function isMain(array $rows): bool
    {
        return str_contains(strtoupper((string) ($rows[4][7] ?? '')), 'SID') && str_contains(strtoupper((string) ($rows[3][6] ?? '')), 'TYPE ORDER');
    }

    private function record(array &$records, string $kind, string $sheet, int $row, array $data, array $original): void
    {
        $records[] = ['kind' => $kind, 'sheet' => $sheet, 'row_number' => $row, 'normalized' => $data, 'original' => $original];
    }

    private function downtime(array &$records, string $title, array $rows, InvoicePeriod $period): void
    {
        $headers = array_map([SourceValue::class, 'header'], $rows[3] ?? []);
        foreach ($headers as $column => $header) {
            if (! in_array($header, ['nojar', 'sid', 'ssid'], true)) {
                continue;
            }
            $seconds = null;
            for ($c = $column + 1; $c < $column + 8; $c++) {
                if (str_contains($headers[$c] ?? '', 'detik') && str_contains($headers[$c] ?? '', 'downtime')) {
                    $seconds = $c;
                    break;
                }
            }
            // September uses a shorter "Downtime (Detik)" label; operational-period seconds are not downtime.
            $seconds ??= $column + 3;
            foreach ($rows as $r => $row) {
                if ($r <= 3 || empty($row[$column])) {
                    continue;
                }
                $shift = is_numeric($row[$column]) && (float) $row[$column] < 10000 && ! empty($row[$column + 1]) ? 1 : 0;
                $sid = $row[$column + $shift] ?? null;
                if (! $sid || strlen((string) $sid) < 6) {
                    continue;
                }
                $this->record($records, 'outages', $title, $r, ['sid' => $sid, 'kind' => 'aggregate', 'duration_seconds' => $row[$seconds + $shift] ?? null, 'review_status' => 'pending', 'usage_start' => $period->start_date->toDateString(), 'usage_end' => $period->end_date->toDateString()], $row);
            }
        }
    }

    private function incidents(array &$records, string $title, array $rows, InvoicePeriod $period): void
    {
        $detailed = str_starts_with(strtolower($title), 'ket ');
        $headerRow = null;
        foreach (array_slice($rows, 0, 15, true) as $n => $candidate) {
            $h = array_map([SourceValue::class, 'header'], $candidate);
            if (in_array('sid', $h, true) && (in_array('open_time', $h, true) || in_array('open', $h, true))) {
                $headerRow = $n;
                break;
            }
        }
        if (! $headerRow) {
            return;
        }
        $headers = array_map([SourceValue::class, 'header'], $rows[$headerRow] ?? []);
        $find = function (array $names) use ($headers) {
            foreach ($headers as $i => $h) {
                if (in_array($h, $names, true)) {
                    return $i;
                }
            }

            return null;
        };
        $sidColumn = $find(['sid', 'ssid']);
        $open = $find(['open', 'open_time']);
        $close = $find(['close', 'close_time']);
        $ticket = $find(['tiket', 'trouble_no', 'troble_no', 'trouble_no_', 'troble_no_']);
        $reason = $find(['ket', 'fault']);
        $lastSid = null;
        if (! $sidColumn || ! $open || ! $close) {
            return;
        }
        foreach ($rows as $r => $row) {
            if ($r <= $headerRow) {
                continue;
            }
            $lastSid = ($row[$sidColumn] ?? null) ?: ($detailed ? $lastSid : null);
            if (! $lastSid || empty($row[$open]) || empty($row[$close])) {
                continue;
            }
            $this->record($records, $detailed ? 'outages' : 'incidents', $title, $r, ['sid' => $lastSid, 'kind' => $detailed ? 'interval' : 'evidence', 'opened_at' => $row[$open], 'closed_at' => $row[$close], 'ticket' => $row[$ticket] ?? null, 'reason' => $row[$reason] ?? null, 'review_status' => $detailed ? 'pending' : 'excluded', 'usage_start' => $period->start_date->toDateString(), 'usage_end' => $period->end_date->toDateString()], $row);
        }
    }

    private function tariffs(array &$records, string $title, array $rows, InvoicePeriod $period): void
    {
        $router = 'none';
        $category = null;
        $service = null;
        foreach ($rows as $r => $row) {
            $text = strtoupper(implode(' ', array_filter($row, fn ($v) => $v !== null)));
            if (str_contains($text, 'TANPA PENAMBAHAN')) {
                $router = 'none';
            } elseif (str_contains($text, 'PENAMBAHAN MANAGED')) {
                $router = 'managed';
            }
            if (! empty($row[3])) {
                $category = $row[3];
            }
            if (! empty($row[4])) {
                $service = $row[4];
            }
            if (empty($row[5]) || ! is_numeric($row[6] ?? null) || ! $category || ! $service) {
                continue;
            }
            $categories = str_contains($category, ',') ? array_map('trim', explode(',', $category)) : ($category === 'ATM/CRM Stand Alone' ? ['ATM', 'CRM'] : [$category]);
            if (str_contains(strtolower($service), 'managed router')) {
                $service = trim(explode('+', $service)[0]);
                $router = 'managed';
            }
            foreach ($categories as $cat) {
                $this->record($records, 'tariffs', $title, $r, ['category' => $cat, 'service' => $service, 'bandwidth' => $row[5], 'router_mode' => $router, 'monthly_price' => $row[6], 'installation_price' => $row[8] ?? null, 'relocation_price' => 0, 'effective_start_date' => $period->start_date->toDateString(), 'contract_number' => 'ADD II'], $row);
            }
        }
    }
}
