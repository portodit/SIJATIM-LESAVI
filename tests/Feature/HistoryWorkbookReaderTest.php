<?php

namespace Tests\Feature;

use App\Services\Billing\HistoryWorkbookReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HistoryWorkbookReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_telkom_layout_is_detected_by_header_text(): void
    {
        $book = $this->book();
        $this->telkomSheet($book, 'JAN26');
        $path = $this->save($book);

        $result = (new HistoryWorkbookReader)->analyse($path);

        $this->assertSame('JAN26', $result['sheet']);
        $this->assertSame([['name' => 'JAN26', 'rows' => 10, 'sid_rows' => 3]], $result['sheets']);
        $this->assertSame([3, 4], $result['header_rows']);
        $this->assertSame(6, $result['first_row']);
        $this->assertSame([
            'sid' => 'G', 'branch' => 'B', 'address' => 'C', 'category' => 'D', 'service' => 'E', 'type_order' => 'F',
            'bandwidth' => 'I', 'bandwidth_upgrade' => 'J', 'bandwidth_downgrade' => 'K',
            'effective_date' => 'N', 'activation_date' => 'M', 'ip' => 'L', 'period' => 'H',
            'downtime_seconds' => 'S', 'reference_price' => 'Y', 'installation_fee' => 'AC', 'relocation_fee' => 'AD',
        ], $result['mapping']);
        $this->assertSame(array_keys(HistoryWorkbookReader::FIELDS), array_keys($result['mapping']));

        $columns = $result['columns'];
        $this->assertSame('HARGA (Rp) Upgrade (Prorate)', $columns['Z']['label']);
        $this->assertSame('HARGA (Rp) Relokasi', $columns['AD']['label']);
        $this->assertSame('Bandwidth Upgrade BW', $columns['J']['label']);
        $this->assertSame('ID SID JAN 2025', $columns['G']['label']);
        $this->assertSame('TANGGAL MULAI AKTIF LAYANAN (UPGRADE/DOWNGRADE)', $columns['N']['label']);
        $this->assertSame('HARGA SETELAH RESTITUSI', $columns['AF']['label']);
        $this->assertSame('ID SID AGUST 2026', $columns['AJ']['label']);
        $this->assertSame(['4700020-38090', '1253487532', '2095151855/2095149600'], $columns['G']['samples']);
        $this->assertSame(['100 Mbps', '100 Mbps', '20 Mbps'], $columns['I']['samples']);
        $this->assertSame(['20412151', '20776000', '1377297'], $columns['Y']['samples']);
        $this->assertSame(['7/26/2017', '2018-04-09', '2025-12-12'], $columns['M']['samples']);
        $this->assertSame([], $columns['K']['samples']);
        $this->assertSame([], $columns['AG']['samples'], 'stray and footer rows are not sampled');
        $indexes = array_map([Coordinate::class, 'columnIndexFromString'], array_keys($columns));
        $sorted = $indexes;
        sort($sorted);
        $this->assertSame($sorted, $indexes);

        $records = (new HistoryWorkbookReader)->records($path, 'JAN26', $result['mapping'], $result['first_row']);

        $this->assertCount(3, $records);
        $this->assertSame([1, 2, 3], array_column(array_column($records, 'normalized'), '_order'));
        $this->assertSame([6, 7, 8], array_column($records, 'row_number'));
        foreach ($records as $record) {
            $this->assertSame('history', $record['kind']);
            $this->assertSame('JAN26', $record['sheet']);
            $this->assertSame('decimal', $record['normalized']['_number_format']);
            $this->assertSame(['_number_format', '_order', ...array_keys(HistoryWorkbookReader::FIELDS)], array_keys($record['normalized']));
        }
        $second = $records[1]['normalized'];
        $this->assertTrue(is_int($second['sid']) || is_float($second['sid']));
        $this->assertEquals(1253487532, $second['sid']);
        $this->assertSame('KANTOR CABANG SURABAYA', $second['branch']);
        $this->assertSame('TELKOM VPN IP', $second['service']);
        $this->assertSame('UPGRADE', $second['type_order']);
        $this->assertSame('100 Mbps', $second['bandwidth']);
        $this->assertSame('200 Mbps', $second['bandwidth_upgrade']);
        $this->assertEquals(202512, $second['period']);
        $this->assertEquals(20776000, $second['reference_price']);
        $this->assertNull($second['bandwidth_downgrade']);
        $this->assertSame('4700020-38090', $records[0]['normalized']['sid']);
        $this->assertSame('7/26/2017', $records[0]['normalized']['activation_date']);
        $this->assertEquals(43199, $second['activation_date']);
        $this->assertEquals(1253487532, $records[1]['original'][7]);
        $this->assertSame('KANTOR CABANG SURABAYA', $records[1]['original'][2]);
        $this->assertEquals(2, $records[1]['original'][1], 'cached formula value is returned instead of the formula text');
        $this->assertCount(40, $records[1]['original']);
    }

    public function test_detail_sheet_beats_sheets_with_more_sid_rows(): void
    {
        $book = $this->book();
        $database = $this->sheet($book, 'DATABASE', [
            1 => ['A' => 'SID', 'B' => 'ALAMAT2', 'C' => 'KATEGORI LOKASI', 'D' => 'IP', 'E' => 'TANGGAL AKTIVASI', 'F' => 'MIKROTIK/NON MIKROTIK'],
        ]);
        foreach (range(2, 9) as $row) {
            $this->fillRow($database, $row, ['A' => '47000'.$row.'-38090', 'B' => 'Alamat '.$row, 'C' => 'ATM', 'D' => '10.0.0.'.$row, 'F' => 'MIKROTIK']);
        }
        $this->sheet($book, 'Summary JAN26', [2 => ['B' => 'INVOICE PERIODE JANUARI 2026'], 6 => ['B' => 'KATEGORI', 'C' => 'TOTAL TITIK']]);
        $this->telkomSheet($book, 'JAN26');
        $master = $this->sheet($book, 'Master Jaringan Jan25(791)', [
            1 => ['A' => 'Master Jaringan Januari 2025'],
            2 => ['A' => 'NO', 'B' => 'AREA', 'C' => 'UNIT', 'D' => 'SID', 'E' => 'BW', 'G' => 'SITE', 'H' => 'ALAMAT', 'I' => 'IP PE', 'J' => 'BW ', 'K' => 'Harga Addendum', 'L' => 'NOTE'],
        ]);
        foreach (range(3, 6) as $row) {
            $this->fillRow($master, $row, ['A' => $row - 2, 'B' => 'SURABAYA', 'D' => '95000'.$row, 'E' => '100 Mbps', 'H' => 'Jl. '.$row]);
        }
        $path = $this->save($book);

        $result = (new HistoryWorkbookReader)->analyse($path);

        $this->assertSame('JAN26', $result['sheet']);
        $this->assertSame(
            [['DATABASE', 8], ['Summary JAN26', 0], ['JAN26', 3], ['Master Jaringan Jan25(791)', 4]],
            array_map(fn ($s) => [$s['name'], $s['sid_rows']], $result['sheets']),
        );
        $this->assertSame('G', $result['mapping']['sid']);
        $this->assertSame(6, $result['first_row']);

        $master = (new HistoryWorkbookReader)->analyse($path, 'Master Jaringan Jan25(791)');
        $this->assertSame([2], $master['header_rows']);
        $this->assertSame(3, $master['first_row']);
        $this->assertSame('D', $master['mapping']['sid']);
        $this->assertSame('B', $master['mapping']['branch']);
        $this->assertSame('H', $master['mapping']['address']);
        $this->assertSame('E', $master['mapping']['bandwidth']);
        $this->assertSame('I', $master['mapping']['ip']);
        $this->assertNull($master['mapping']['category']);
        $this->assertNull($master['mapping']['reference_price']);
    }

    public function test_equal_candidates_prefer_more_sid_rows_then_workbook_order(): void
    {
        $book = $this->book();
        $this->telkomSheet($book, 'JAN26');
        $this->telkomSheet($book, 'Data Invois Jan26');
        $this->fillRow($this->telkomSheet($book, 'Lebih Lengkap'), 9, ['B' => 'KANTOR PUSAT', 'E' => 'TELKOM ASTINET', 'G' => '4700020-99999']);
        $path = $this->save($book);

        $this->assertSame('Lebih Lengkap', (new HistoryWorkbookReader)->analyse($path)['sheet']);
        $this->assertSame('JAN26', (new HistoryWorkbookReader)->analyse($path, 'JAN26')['sheet']);

        $book = $this->book();
        $this->telkomSheet($book, 'JAN26');
        $this->telkomSheet($book, 'Data Invois Jan26');

        $this->assertSame('JAN26', (new HistoryWorkbookReader)->analyse($this->save($book))['sheet']);

        $book = $this->book();
        $this->telkomSheet($book, 'Data Invois Jan26 (791)')->setCellValue('S4', '(Jam)');
        $this->telkomSheet($book, 'JAN26');

        $this->assertSame('JAN26', (new HistoryWorkbookReader)->analyse($this->save($book))['sheet'], 'the sheet that maps more fields wins a full tie');
    }

    public function test_shifted_columns_with_a_single_header_row(): void
    {
        $book = $this->book();
        $this->sheet($book, 'Sep 2026', [
            1 => ['A' => 'No', 'B' => 'Layanan', 'C' => 'Alamat Lokasi', 'D' => 'Kategori', 'E' => 'SSID', 'F' => 'Cabang', 'G' => 'Jenis Order', 'H' => 'BW Existing', 'I' => 'Harga Existing (Rp)', 'J' => 'Downtime (detik)'],
            2 => ['A' => 1, 'B' => 'TELKOM ASTINET', 'C' => 'Jl. Ahmad Yani 1', 'D' => 'KANTOR CABANG', 'E' => 1109732773, 'F' => 'KC MALANG', 'G' => 'EKSISTING', 'H' => '50 Mbps', 'I' => 5000000, 'J' => 120],
            3 => ['A' => 2, 'B' => 'TELKOM VPN IP', 'C' => 'Jl. Merdeka 2', 'D' => 'ATM', 'E' => '4700020-10498', 'F' => 'KC KEDIRI', 'G' => 'PASANG BARU', 'H' => '10 Mbps', 'I' => 1000000, 'J' => 0],
        ]);
        $path = $this->save($book);

        $result = (new HistoryWorkbookReader)->analyse($path);

        $this->assertSame('Sep 2026', $result['sheet']);
        $this->assertSame([1], $result['header_rows']);
        $this->assertSame(2, $result['first_row']);
        $this->assertSame([
            'sid' => 'E', 'branch' => 'F', 'address' => 'C', 'category' => 'D', 'service' => 'B', 'type_order' => 'G',
            'bandwidth' => 'H', 'bandwidth_upgrade' => null, 'bandwidth_downgrade' => null,
            'effective_date' => null, 'activation_date' => null, 'ip' => null, 'period' => null,
            'downtime_seconds' => 'J', 'reference_price' => 'I', 'installation_fee' => null, 'relocation_fee' => null,
        ], $result['mapping']);
        $this->assertSame('Downtime (detik)', $result['columns']['J']['label']);
        $this->assertSame(['1109732773', '4700020-10498'], $result['columns']['E']['samples']);

        $records = (new HistoryWorkbookReader)->records($path, 'Sep 2026', ['sid' => 'E', 'bandwidth' => 'h', 'service' => null, 'branch' => ''], 2);
        $this->assertCount(2, $records);
        $this->assertSame(['_number_format', '_order', 'sid', 'bandwidth'], array_keys($records[0]['normalized']));
        $this->assertSame('10 Mbps', $records[1]['normalized']['bandwidth']);
    }

    public function test_explicit_sheet_and_errors(): void
    {
        $book = $this->book();
        $this->telkomSheet($book, 'JAN26');
        $this->sheet($book, 'DATABASE', [
            1 => ['A' => 'SID', 'B' => 'ALAMAT2', 'C' => 'KATEGORI LOKASI', 'D' => 'IP', 'E' => 'TANGGAL AKTIVASI', 'F' => 'MIKROTIK/NON MIKROTIK'],
            2 => ['A' => '4700020-38090', 'B' => 'Jl. A', 'C' => 'ATM', 'D' => '10.0.0.1', 'E' => '7/26/2017', 'F' => 'MIKROTIK'],
            3 => ['A' => 1253487532, 'B' => 'Jl. B', 'C' => 'CRM', 'D' => '10.0.0.2', 'E' => 43199, 'F' => 'NON MIKROTIK'],
        ]);
        $path = $this->save($book);
        $reader = new HistoryWorkbookReader;

        $result = $reader->analyse($path, 'DATABASE');

        $this->assertSame('DATABASE', $result['sheet']);
        $this->assertSame([1], $result['header_rows']);
        $this->assertSame(2, $result['first_row']);
        $this->assertSame('A', $result['mapping']['sid']);
        $this->assertSame('B', $result['mapping']['address']);
        $this->assertSame('C', $result['mapping']['category']);
        $this->assertSame('D', $result['mapping']['ip']);
        $this->assertSame('E', $result['mapping']['activation_date']);
        $this->assertNull($result['mapping']['branch']);
        $this->assertSame(['7/26/2017', '2018-04-09'], $result['columns']['E']['samples']);
        $this->assertSame(2, $result['sheets'][1]['sid_rows']);
        $this->assertCount(2, $reader->records($path, 'DATABASE', $result['mapping'], 2));

        try {
            $reader->analyse($path, 'Tidak Ada');
            $this->fail('Unknown sheet must throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Sheet Tidak Ada tidak ditemukan.', $e->getMessage());
        }
        try {
            $reader->records($path, 'Tidak Ada', $result['mapping'], 2);
            $this->fail('Unknown sheet must throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Sheet Tidak Ada tidak ditemukan.', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kolom SSID belum dipilih.');
        $reader->records($path, 'DATABASE', ['sid' => null, 'address' => 'B'], 2);
    }

    public function test_workbook_without_sid_header_returns_empty_analysis(): void
    {
        $book = $this->book();
        $this->sheet($book, 'NOTE', [1 => ['A' => 'Catatan', 'B' => 'Isi'], 2 => ['A' => 'Satu', 'B' => '1234567890']]);
        $this->sheet($book, 'Rekap', [3 => ['B' => 'NO', 'C' => 'PERIODE', 'D' => 'TROBLE NO'], 4 => ['B' => 1, 'C' => 202608, 'D' => 'INC51733072']]);
        $path = $this->save($book);

        $result = (new HistoryWorkbookReader)->analyse($path);

        $this->assertNull($result['sheet']);
        $this->assertSame([], $result['header_rows']);
        $this->assertNull($result['first_row']);
        $this->assertSame([], $result['columns']);
        $this->assertSame(array_keys(HistoryWorkbookReader::FIELDS), array_keys($result['mapping']));
        $this->assertSame([], array_filter($result['mapping'], fn ($letter) => $letter !== null));
        $this->assertSame([['name' => 'NOTE', 'rows' => 2, 'sid_rows' => 0], ['name' => 'Rekap', 'rows' => 4, 'sid_rows' => 0]], $result['sheets']);

        $explicit = (new HistoryWorkbookReader)->analyse($path, 'Rekap');
        $this->assertSame('Rekap', $explicit['sheet']);
        $this->assertNull($explicit['first_row']);
        $this->assertSame([], $explicit['columns']);
    }

    public function test_oversized_sheet_is_rejected(): void
    {
        $book = $this->book();
        $this->sheet($book, 'Besar', [1 => ['A' => 'SID'], 50001 => ['A' => '4700020-38090']]);
        $path = $this->save($book);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sheet Besar melebihi batas 50.000 baris / 91 kolom.');
        (new HistoryWorkbookReader)->analyse($path);
    }

    /** Telkom invoice layout: title row 2, group headers row 3, sub-headers row 4, letter row 5 (September also has a few text labels in it), data from row 6. */
    private function telkomSheet(Spreadsheet $book, string $title): Worksheet
    {
        $letters = array_map([Coordinate::class, 'stringFromColumnIndex'], range(1, 36));
        $sheet = $this->sheet($book, $title, [
            2 => ['B' => '• TELKOM - MONTHLY RECURRING CHARGES'],
            3 => [
                'A' => 'NO.', 'B' => 'JENIS KANTOR', 'C' => 'ALAMAT', 'D' => 'KATEGORI LOKASI', 'E' => 'LAYANAN', 'F' => 'TYPE ORDER', 'G' => 'ID', 'H' => 'PERIOD',
                'I' => 'Bandwidth', 'L' => 'IP Host Telkom', 'M' => 'TANGGAL AKTIVASI', 'N' => "TANGGAL MULAI AKTIF LAYANAN \n (UPGRADE/DOWNGRADE)",
                'O' => "PEMAKAIAN AVERAGE\n (Kbps)", 'Q' => 'TRAFFIK TOTAL (Kbps)', 'S' => 'DURASI DOWNTIME', 'T' => 'SLA', 'V' => 'AVAILABILITY (Jam/bulan)',
                'W' => 'RESTITUSI (Rp)', 'Y' => 'HARGA (Rp)', 'AE' => 'TOTAL', 'AF' => 'HARGA SETELAH RESTITUSI', 'AG' => 'SUB TOTAL PER CABANG', 'AH' => 'MIKROTIK/NONMIKROTIK', 'AI' => 'KETERANGAN',
                'AJ' => 'ID', 'AK' => 'Bandwidth', 'AN' => 'HARGA INV vs HARGA Adendum II',
            ],
            4 => [
                'G' => 'SID JAN 2025', 'I' => 'Eksisting BW', 'J' => 'Upgrade BW', 'K' => 'Downgrade BW', 'O' => 'Eksisting', 'P' => 'Upgrade', 'Q' => 'Eksisting', 'R' => 'Upgrade',
                'S' => '(Detik)', 'T' => 'SLA ACTUAL', 'U' => 'SLA PKS', 'W' => 'Restitusi Over SLA', 'X' => 'Restitusi Tagihan', 'Y' => 'Eksisting', 'Z' => 'Upgrade (Prorate)',
                'AA' => 'Downgrade (Prorate)', 'AB' => 'Cabut (Prorate)', 'AC' => 'PSB (Prorate)', 'AD' => 'Relokasi', 'AE' => 'TOTAL', 'AF' => 'HARGA SETELAH RESTITUSI',
                'AG' => 'SUB TOTAL PER CABANG', 'AH' => 'MIKROTIK/NONMIKROTIK', 'AI' => 'KETERANGAN',
                'AJ' => 'SID AGUST 2026', 'AK' => 'Eksisting BW', 'AL' => 'BW Nojar', 'AM' => 'Compare BW', 'AN' => 'Harga ADD II',
            ],
            5 => array_combine($letters, $letters) + ['AL' => 'BW ', 'AM' => 'Compare', 'AN' => 'Harga '],
            6 => [
                'A' => 1, 'B' => 'KANTOR PUSAT', 'C' => 'Bank Jatim Internet Kantor Pusat', 'D' => 'INTERNET KANTOR PUSAT', 'E' => 'TELKOM ASTINET', 'F' => 'EKSISTING',
                'G' => '4700020-38090', 'H' => 202512, 'I' => '100 Mbps', 'L' => '10.10.30.1 /30', 'M' => '7/26/2017', 'S' => 0, 'Y' => 20412151, 'AE' => 20412151,
                'AJ' => '4700020-38090', 'AK' => '100 Mbps', 'AL' => '100 Mbps', 'AM' => 1, 'AN' => 5000000,
            ],
            7 => [
                'A' => '=A6+1', 'B' => 'KANTOR CABANG SURABAYA', 'C' => 'Jl. Basuki Rahmat 1', 'D' => 'KANTOR CABANG', 'E' => 'TELKOM VPN IP', 'F' => 'UPGRADE',
                'G' => 1253487532, 'H' => 202512, 'I' => '100 Mbps', 'J' => '200 Mbps', 'L' => '192.168.152.2 /30', 'M' => 43199, 'N' => 46022, 'S' => 0, 'Y' => 20776000, 'AE' => 20776000,
                'AJ' => 1253487532, 'AK' => '100 Mbps',
            ],
            8 => [
                'A' => 3, 'B' => 'KANTOR PUSAT', 'C' => 'Jl. Pemuda 3', 'D' => 'ATM', 'E' => 'TELKOM NEUCENTRIX', 'F' => 'PASANG BARU',
                'G' => '2095151855/2095149600', 'H' => 202512, 'I' => '20 Mbps', 'L' => '36.64.131.241/29', 'M' => 46003, 'N' => 46003, 'S' => 0, 'Y' => 1377297, 'AE' => 1377297,
                'AJ' => '4700020-0003122034', 'AK' => '256 Kbps',
            ],
            9 => ['B' => 'catatan tanpa SID', 'Y' => 99],
            10 => ['A' => 3, 'Y' => 42565545, 'AE' => 42565545, 'AG' => 777],
        ]);
        foreach (['I3:K3', 'Y3:AD3', 'O3:P3', 'Q3:R3', 'T3:U3', 'W3:X3', 'A3:A4', 'B3:B4', 'C3:C4', 'AK3:AM3'] as $range) {
            $sheet->mergeCells($range);
        }

        return $sheet;
    }

    /** @param array<int, array<string, mixed>> $rows row => column letter => value */
    private function sheet(Spreadsheet $book, string $title, array $rows): Worksheet
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        foreach ($rows as $row => $cells) {
            $this->fillRow($sheet, $row, $cells);
        }

        return $sheet;
    }

    /** @param array<string, mixed> $cells */
    private function fillRow(Worksheet $sheet, int $row, array $cells): void
    {
        foreach ($cells as $letter => $value) {
            $sheet->setCellValue($letter.$row, $value);
        }
    }

    private function book(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        return $book;
    }

    private function save(Spreadsheet $book): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'history_'.bin2hex(random_bytes(6)).'.xlsx';
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
