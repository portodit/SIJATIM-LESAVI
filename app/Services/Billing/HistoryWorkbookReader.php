<?php

namespace App\Services\Billing;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class HistoryWorkbookReader
{
    /** field => [Indonesian label for the UI, required?], in display order. */
    public const FIELDS = [
        'sid' => ['SSID / ID', true],
        'branch' => ['Jenis kantor', true],
        'address' => ['Alamat', true],
        'category' => ['Kategori lokasi', true],
        'service' => ['Layanan', true],
        'type_order' => ['Type order', true],
        'bandwidth' => ['Bandwidth eksisting', true],
        'bandwidth_upgrade' => ['Bandwidth upgrade', false],
        'bandwidth_downgrade' => ['Bandwidth downgrade', false],
        'effective_date' => ['Tanggal mulai (PSB/Cabut/Upgrade/Downgrade)', false],
        'activation_date' => ['Tanggal aktivasi', false],
        'ip' => ['IP Host Telkom', false],
        'period' => ['Period (YYYYMM)', false],
        'downtime_seconds' => ['Durasi downtime (detik)', false],
        'reference_price' => ['Harga eksisting Telkom', false],
        'installation_fee' => ['Biaya PSB Telkom', false],
        'relocation_fee' => ['Biaya relokasi Telkom', false],
    ];

    /**
     * @return array{sheets: list<array{name: string, rows: int, sid_rows: int}>, sheet: ?string, header_rows: list<int>, first_row: ?int, columns: array<string, array{label: string, samples: list<string>}>, mapping: array<string, ?string>}
     */
    public function analyse(string $path, ?string $sheet = null): array
    {
        $book = $this->open($path);
        try {
            $found = [];
            foreach ($book->getWorksheetIterator() as $candidate) {
                $found[] = $this->detect($candidate);
            }
            $chosen = $this->choose($found, $sheet);
            $columns = $chosen ? $this->columns($book->getSheetByName($chosen['name']), $chosen) : [];
        } finally {
            $book->disconnectWorksheets();
        }

        return [
            'sheets' => array_map(fn ($f) => ['name' => $f['name'], 'rows' => $f['rows'], 'sid_rows' => $f['sid_rows']], $found),
            'sheet' => $chosen['name'] ?? null,
            'header_rows' => $chosen['header_rows'] ?? [],
            'first_row' => $chosen['first_row'] ?? null,
            'columns' => $columns,
            'mapping' => $chosen['mapping'] ?? $this->emptyMapping(),
        ];
    }

    /**
     * @param  array<string, ?string>  $mapping  field => column letter (null = not mapped)
     * @return list<array{kind: string, sheet: string, row_number: int, normalized: array, original: array}>
     */
    public function records(string $path, string $sheet, array $mapping, int $firstRow): array
    {
        $indexes = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $letter = strtoupper(trim((string) ($mapping[$field] ?? '')));
            if ($letter !== '') {
                $indexes[$field] = Coordinate::columnIndexFromString($letter);
            }
        }
        if (! isset($indexes['sid'])) {
            throw new \InvalidArgumentException('Kolom SSID belum dipilih.');
        }
        $book = $this->open($path, $sheet);
        try {
            $worksheet = $book->getSheetByName($sheet);
            [$last, $columns] = $this->bounds($worksheet);
            $records = [];
            for ($r = max(1, $firstRow); $r <= $last; $r++) {
                if (! $this->isId($this->value($worksheet, $indexes['sid'], $r))) {
                    continue;
                }
                $values = [];
                for ($c = 1; $c <= $columns; $c++) {
                    $values[$c] = $this->value($worksheet, $c, $r);
                }
                $data = ['_number_format' => 'decimal', '_order' => count($records) + 1];
                foreach ($indexes as $field => $c) {
                    $data[$field] = $values[$c] ?? null;
                }
                $records[] = ['kind' => 'history', 'sheet' => $sheet, 'row_number' => $r, 'normalized' => $data, 'original' => $values];
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return $records;
    }

    private function open(string $path, ?string $only = null): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if ($only !== null) {
            // Parsing only the wanted sheet is several times faster; an unknown name must fail before loading.
            if (! in_array($only, $reader->listWorksheetNames($path), true)) {
                throw new \InvalidArgumentException('Sheet '.$only.' tidak ditemukan.');
            }
            $reader->setLoadSheetsOnly([$only]);
        }

        return $reader->load($path);
    }

    private function bounds(Worksheet $sheet): array
    {
        $last = $sheet->getHighestDataRow();
        $columns = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($last > 50000 || $columns > 91) {
            throw new \InvalidArgumentException('Sheet '.$sheet->getTitle().' melebihi batas 50.000 baris / 91 kolom. Pecah sumber menjadi file terpisah.');
        }

        return [$last, $columns];
    }

    private function emptyMapping(): array
    {
        return array_fill_keys(array_keys(self::FIELDS), null);
    }

    private function value(Worksheet $sheet, int $column, int $row): mixed
    {
        if (! $sheet->cellExists([$column, $row])) {
            return null;
        }
        $cell = $sheet->getCell([$column, $row]);

        // Cached formula values are source claims only; never execute workbook formulas.
        return $cell->getDataType() === 'f' ? $cell->getOldCalculatedValue() : $cell->getValue();
    }

    private function text(Worksheet $sheet, int $column, int $row): string
    {
        $value = $this->value($sheet, $column, $row);
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return '';
        }

        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', (string) $value) ?? '');
    }

    private function isId(mixed $value): bool
    {
        try {
            $sid = SourceValue::sid($value);
        } catch (\InvalidArgumentException) {
            return false;
        }

        // Sub-header text such as "SID JAN 2025" has digits too, but real SIDs never mix spaces with words.
        return strlen($sid) >= 5 && preg_match('/\d/', $sid) === 1 && ! (preg_match('/\s/u', $sid) && preg_match('/\pL{2}/u', $sid));
    }

    private function detect(Worksheet $sheet): array
    {
        [$last, $columns] = $this->bounds($sheet);
        $found = ['name' => $sheet->getTitle(), 'rows' => $last, 'columns' => $columns, 'sid_rows' => 0, 'header_rows' => [], 'first_row' => null, 'data_rows' => [], 'labels' => [], 'mapping' => $this->emptyMapping(), 'coverage' => null];
        $header = $this->sidHeader($sheet, $last, $columns);
        if (! $header) {
            return $found;
        }
        [$headerRow, $sidColumn] = $header;
        for ($r = $headerRow + 1; $r <= $last; $r++) {
            if ($this->isId($this->value($sheet, $sidColumn, $r))) {
                $found['data_rows'][] = $r;
            }
        }
        $found['first_row'] = $found['data_rows'][0] ?? null;
        $found['sid_rows'] = count($found['data_rows']);
        $found['header_rows'] = $this->headerRows($sheet, $headerRow, ($found['first_row'] ?? $headerRow + 1) - 1, $columns);
        $found['labels'] = $this->labels($sheet, $found['header_rows'], $columns);
        $found['mapping'] = $this->mapping($found['labels']);
        $required = array_keys(array_filter(self::FIELDS, fn ($f) => $f[1]));
        $found['coverage'] = count(array_filter($required, fn ($field) => $found['mapping'][$field] !== null));

        return $found;
    }

    /** @return array{0: int, 1: int}|null  [row, column] of the first SID/ID header cell within rows 1-15 */
    private function sidHeader(Worksheet $sheet, int $last, int $columns): ?array
    {
        for ($r = 1; $r <= min(15, $last); $r++) {
            for ($c = 1; $c <= $columns; $c++) {
                $h = SourceValue::header($this->text($sheet, $c, $r));
                if (in_array($h, ['id', 'sid', 'nojar'], true) || str_starts_with($h, 'sid_') || str_starts_with($h, 'id_')) {
                    return [$r, $c];
                }
            }
        }

        return null;
    }

    /** The SID header row plus the rows below it up to the first data row, without blank rows and "A, B, C..." letter rows (a few stray labels, as in September's comparison block, are tolerated). */
    private function headerRows(Worksheet $sheet, int $from, int $to, int $columns): array
    {
        $rows = [];
        for ($r = $from; $r <= $to; $r++) {
            $texts = array_filter(array_map(fn ($c) => $this->text($sheet, $c, $r), range(1, $columns)), fn ($t) => $t !== '');
            $letters = count(array_filter($texts, fn ($t) => preg_match('/^[A-Z]{1,3}$/', $t)));
            if ($r === $from || ($texts && $letters < 0.8 * count($texts))) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    /** @return array<int, string> column index => label; a group label in the first header row carries over merged-style empty cells. */
    private function labels(Worksheet $sheet, array $headerRows, int $columns): array
    {
        $labels = [];
        $carry = '';
        for ($c = 1; $c <= $columns; $c++) {
            $texts = array_map(fn ($r) => $this->text($sheet, $c, $r), $headerRows);
            $top = array_shift($texts);
            $lower = array_values(array_filter($texts, fn ($t) => $t !== ''));
            if ($top !== '') {
                $carry = $top;
            } elseif ($carry !== '' && $lower) {
                $top = $carry;
            } else {
                $carry = '';
            }
            $parts = [];
            foreach ([$top, ...$lower] as $part) {
                if ($part !== '' && strcasecmp((string) end($parts), $part) !== 0) {
                    $parts[] = $part;
                }
            }
            if ($parts) {
                $labels[$c] = implode(' ', $parts);
            }
        }

        return $labels;
    }

    private function mapping(array $labels): array
    {
        $mapping = $this->emptyMapping();
        $headers = array_map([SourceValue::class, 'header'], $labels);
        $used = [];
        foreach (array_keys(self::FIELDS) as $field) {
            foreach ($headers as $c => $header) {
                if (! isset($used[$c]) && $this->matches($field, $header)) {
                    $mapping[$field] = Coordinate::stringFromColumnIndex($c);
                    $used[$c] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function matches(string $field, string $h): bool
    {
        $tokens = explode('_', $h);
        $has = fn (string ...$needles) => array_filter($needles, fn ($n) => str_contains($h, $n)) !== [];
        $is = fn (string ...$names) => array_filter($names, fn ($n) => $h === $n || str_starts_with($h, $n.'_')) !== [];
        $bw = in_array('bw', $tokens, true) || in_array('bandwidth', $tokens, true);

        return match ($field) {
            'sid' => array_intersect(['sid', 'ssid', 'nojar'], $tokens) !== [] || $is('id'),
            'branch' => $has('jenis_kantor') || $is('cabang', 'branch', 'area', 'unit_kerja'),
            'address' => $has('alamat'),
            'category' => $has('kategori'),
            'service' => $is('layanan', 'service') && ! $has('tanggal', 'mulai', 'aktif'),
            'type_order' => $has('type_order', 'tipe_order', 'jenis_order'),
            'bandwidth' => ! $has('upgrade', 'downgrade', 'nojar', 'compare', 'inv') && (($bw && $has('eksisting', 'existing')) || in_array($h, ['bandwidth', 'bw'], true)),
            'bandwidth_upgrade' => $bw && $has('upgrade'),
            'bandwidth_downgrade' => $bw && $has('downgrade'),
            'effective_date' => $has('tanggal_mulai', 'mulai_aktif', 'tanggal_efektif', 'tgl_efektif'),
            'activation_date' => $has('aktivasi'),
            'ip' => in_array('ip', $tokens, true),
            'period' => $is('period', 'periode'),
            // SourceValue::header() turns a bare "Downtime (Detik)" into duration_seconds.
            'downtime_seconds' => ($has('downtime') || $h === 'duration_seconds') && ! $has('jam', 'menit', 'hour'),
            'reference_price' => $has('harga') && $has('eksisting', 'existing') && ! $has('setelah', 'add', 'adendum', 'addendum', 'inv', 'compare', 'nojar'),
            // Telkom labels the installation fee "PSB (Prorate)" although it is the one-off fee.
            'installation_fee' => in_array('psb', $tokens, true) && ! $has('total', 'setelah'),
            'relocation_fee' => $has('relokasi') && $has('harga', 'biaya'),
            default => false,
        };
    }

    /** Sheets with a SID header compete on required-field coverage, then SID rows, then all mapped fields (then workbook order); an explicit sheet name wins outright. */
    private function choose(array $found, ?string $sheet): ?array
    {
        if ($sheet !== null) {
            foreach ($found as $candidate) {
                if ($candidate['name'] === $sheet) {
                    return $candidate;
                }
            }
            throw new \InvalidArgumentException('Sheet '.$sheet.' tidak ditemukan.');
        }
        $rank = fn (array $f) => [$f['coverage'], $f['sid_rows'], count(array_filter($f['mapping']))];
        $best = null;
        foreach ($found as $candidate) {
            if ($candidate['coverage'] !== null && ($best === null || ($rank($candidate) <=> $rank($best)) > 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return array<string, array{label: string, samples: list<string>}> */
    private function columns(Worksheet $sheet, array $found): array
    {
        $dates = array_flip(array_filter([$found['mapping']['effective_date'], $found['mapping']['activation_date']]));
        $samples = array_fill(1, $found['columns'], []);
        foreach ($found['data_rows'] as $r) {
            $open = array_keys(array_filter($samples, fn ($s) => count($s) < 3));
            if (! $open) {
                break;
            }
            foreach ($open as $c) {
                $value = $this->value($sheet, $c, $r);
                if ($value !== null && (! is_string($value) || trim($value) !== '')) {
                    $samples[$c][] = $this->sample($value, isset($dates[Coordinate::stringFromColumnIndex($c)]));
                }
            }
        }
        $columns = [];
        foreach ($samples as $c => $values) {
            if (isset($found['labels'][$c]) || $values) {
                $columns[Coordinate::stringFromColumnIndex($c)] = ['label' => $found['labels'][$c] ?? '', 'samples' => $values];
            }
        }

        return $columns;
    }

    private function sample(mixed $value, bool $date): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // Data-only loading drops cell formats, so Excel dates arrive as serial numbers.
        if ($date && (is_int($value) || is_float($value)) && $value > 20000 && $value < 80000) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }

        return mb_substr(trim((string) $value), 0, 40);
    }
}
