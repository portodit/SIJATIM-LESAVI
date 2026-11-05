<?php

namespace Tests\Feature;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\InvoiceRevision;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDocumentGenerator;
use App\Services\Billing\InvoiceFinalizer;
use App\Services\Billing\SummaryImage;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The invoice workbook has three sheets, laid out like the customer's Telkom workbook: Harga (tariffs),
 * Detail Layanan (one row per SID plus a TOTAL row) and Summary Invoice (as the picture).
 */
class ExcelOutputTest extends TestCase
{
    use RefreshDatabase;

    private const SPECIAL = 'Biaya contoh usage agst Inv Sept';

    /** The colours the Harga rows cycle through, by location category id. */
    private const CATEGORY_COLORS = [
        'FFF2CC', 'D9EAD3', 'CFE2F3', 'F4CCCC', 'D9D2E9', 'FCE5CD', 'D0E0E3', 'EAD1DC', 'C9DAF8', 'B6D7A8', 'FFE599', 'F9CB9C',
        'A2C4C9', 'B4A7D6', 'D5A6BD', 'EA9999', '9FC5E8', 'A4C2F4', '93C47D', 'FFD966', 'F6B26B', '76A5AF', 'C27BA0', 'E6B8AF',
    ];

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Issued invoice of September 2026 (usage August) with two SSIDs: "0012-ABC" (existing) and "00123456"
     * (upgraded on 22 August, 53,568 s downtime). Master Harga holds valid tariffs, a SID-specific one, and three
     * that must not be listed: expired before the usage month, starting after it, and inactive. With $withAdjustment the first
     * SID also sits in a special column of Rp70.000.
     */
    private function issuedRevision(bool $withAdjustment = false): InvoiceRevision
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'T', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw512 = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        $bw1024 = BandwidthOption::create(['bandwidth_code' => '1024', 'bandwidth_name' => '1 Mbps', 'value_kbps' => 1024]);
        $bw2048 = BandwidthOption::create(['bandwidth_code' => '2048', 'bandwidth_name' => '2 Mbps', 'value_kbps' => 2048]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $period = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);
        foreach ([['0012-ABC', 'Jl. Test Satu'], ['00123456', 'Jl. Test Dua']] as $i => [$sid, $address]) {
            $master = BankJatimLink::create(['ssid' => $sid, 'office_type_id' => $office->id, 'sequence_in_office_type' => $i + 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw512->id, 'address' => $address, 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
            InvoiceLine::create(['invoice_period_id' => $period->id, 'bank_jatim_link_id' => $master->id, 'line_number' => $i + 1, 'existing_bandwidth_option_id' => $bw512->id, 'address_snapshot' => $address, 'traffic_exception_reason' => 'MRTG belum tersedia dari penyedia.']);
        }
        $tariff = fn (array $attributes) => PriceMaster::create($attributes + ['service_id' => $service->id, 'location_category_id' => $category->id, 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        $tariff(['bandwidth_option_id' => $bw512->id, 'monthly_price' => '2319819']);
        $tariff(['bandwidth_option_id' => $bw1024->id, 'monthly_price' => '1300000']);
        $tariff(['bandwidth_option_id' => $bw1024->id, 'monthly_price' => '1200000', 'sid' => '0012-ABC']);
        $tariff(['bandwidth_option_id' => $bw1024->id, 'location_category_id' => null, 'router_mode' => 'managed', 'monthly_price' => '1350000', 'installation_price' => '2000000', 'effective_start_date' => '2026-01-01']);
        $tariff(['bandwidth_option_id' => $bw2048->id, 'monthly_price' => '999001', 'effective_end_date' => '2026-07-31']);
        $tariff(['bandwidth_option_id' => $bw2048->id, 'monthly_price' => '999002', 'router_mode' => 'managed', 'effective_start_date' => '2026-09-01']);
        $tariff(['bandwidth_option_id' => $bw512->id, 'monthly_price' => '999003', 'router_mode' => 'managed', 'is_active' => false]);
        ServiceEvent::create(['invoice_period_id' => $period->id, 'sid' => '00123456', 'event_type' => 'upgrade', 'effective_date' => '2026-08-22', 'attributes' => ['bandwidth_option_id' => $bw1024->id]]);
        $period->outages()->create(['sid' => '00123456', 'kind' => 'aggregate', 'duration_seconds' => 53568, 'review_status' => 'eligible', 'reason' => 'Test', 'coverage_start' => '2026-08-01 00:00:00', 'coverage_end' => '2026-09-01 00:00:00']);
        if ($withAdjustment) {
            InvoiceAdjustment::create(['invoice_period_id' => $period->id, 'sid' => '0012-ABC', 'kind' => InvoiceCalculator::SPECIAL_COLUMN, 'amount' => '70000', 'attributes' => ['column' => self::SPECIAL, 'summary' => 'Biaya contoh'], 'reason' => 'Contoh', 'reference' => 'Daftar SID uji']);
        }
        $period->update(['sla_coverage' => ['sids' => ['0012-ABC', '00123456']]]);
        $period->markInputsChanged('test');
        app(InvoiceCalculator::class)->calculate($period->fresh());
        $revision = app(InvoiceFinalizer::class)->finalize($period->fresh(), $period->fresh()->input_version, auth()->id());
        (new GenerateInvoiceDocuments($revision->id))->handle(app(InvoiceDocumentGenerator::class));

        return $revision->fresh();
    }

    private function issuedWorkbook(InvoiceRevision $revision): Spreadsheet
    {
        $this->assertSame('ready', $revision->status, (string) $revision->generation_error);
        $output = $revision->outputs()->where('output_type', 'excel_invoice')->sole();

        return IOFactory::load(Storage::disk('local')->path($output->file_path));
    }

    /** Writes a (modified) snapshot with the generator and reads the workbook back. */
    private function reissue(array $snapshot): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        $this->temporaryFiles[] = $path;
        app(InvoiceDocumentGenerator::class)->excel($snapshot, $path, 1);

        return IOFactory::load($path);
    }

    /** Runs the generator's own check of the saved files against a snapshot: null when it accepts them, else the reason. */
    private function validationError(InvoiceRevision $revision, array $snapshot): ?string
    {
        $disk = Storage::disk('local');
        $xlsx = $disk->path($revision->outputs()->where('output_type', 'excel_invoice')->sole()->file_path);
        $pdf = $disk->path($revision->outputs()->where('output_type', 'pdf_cetak')->sole()->file_path);
        try {
            (new \ReflectionMethod(InvoiceDocumentGenerator::class, 'validateFiles'))->invoke(app(InvoiceDocumentGenerator::class), $xlsx, $pdf, $snapshot);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Raw cell values by 1-based row number. */
    private function rows(Worksheet $sheet): array
    {
        return array_combine(range(1, $sheet->getHighestRow()), $sheet->toArray(null, false, false, false));
    }

    /** Row of a total on the Summary Invoice sheet: the table starts on row 6 and follows SummaryImage::rows(). */
    private function summaryRow(array $snapshot, string $key): int
    {
        foreach (SummaryImage::rows($snapshot)['rows'] as $i => $row) {
            if (($row['key'] ?? null) === $key) {
                return 6 + $i;
            }
        }
        $this->fail('No summary row '.$key);
    }

    /** A snapshot money string as the number in the cell (null stays empty). */
    private function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    public function test_the_workbook_has_exactly_the_three_sheets_in_order(): void
    {
        $revision = $this->issuedRevision();
        $book = $this->issuedWorkbook($revision);
        $this->assertSame(3, $book->getSheetCount());
        $this->assertSame(['Harga', 'Detail Layanan', 'Summary Invoice'], $book->getSheetNames());
        $this->assertSame('Summary Invoice', $book->getActiveSheet()->getTitle());
        $this->assertSame(4, $revision->outputs()->count(), 'Excel, PDF, Word and the summary picture are still issued.');
        $book->disconnectWorksheets();
    }

    public function test_the_issued_files_pass_the_validation_and_a_tampered_snapshot_does_not(): void
    {
        $revision = $this->issuedRevision();
        $snapshot = $revision->snapshot;
        $disk = Storage::disk('local');
        $xlsx = $disk->path($revision->outputs()->where('output_type', 'excel_invoice')->sole()->file_path);
        $pdf = $disk->path($revision->outputs()->where('output_type', 'pdf_cetak')->sole()->file_path);
        $validate = new \ReflectionMethod(InvoiceDocumentGenerator::class, 'validateFiles');
        $generator = app(InvoiceDocumentGenerator::class);

        $this->assertNull($validate->invoke($generator, $xlsx, $pdf, $snapshot), 'The issued files are accepted.');
        foreach (['net_exact', 'grand_total'] as $key) {
            $tampered = $snapshot;
            $tampered['totals'][$key] = (string) BigDecimal::of($snapshot['totals'][$key])->plus('1.00');
            $rejected = false;
            try {
                $validate->invoke($generator, $xlsx, $pdf, $tampered);
            } catch (\RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'A changed '.$key.' must be rejected.');
        }
    }

    public function test_detail_layanan_has_the_customer_header_layout(): void
    {
        $book = $this->issuedWorkbook($this->issuedRevision());
        $detail = $book->getSheetByName('Detail Layanan');
        $rows = $this->rows($detail);

        $this->assertSame("\u{2022} TELKOM - MONTHLY RECURRING CHARGES", $detail->getCell('B2')->getValue());
        $this->assertSame(['Calibri', 12.0, true], [$detail->getStyle('B2')->getFont()->getName(), (float) $detail->getStyle('B2')->getFont()->getSize(), $detail->getStyle('B2')->getFont()->getBold()]);
        $this->assertSame('Arial', $detail->getStyle('A6')->getFont()->getName());

        // Row 3: group header; row 4: sub-header; row 5: the column letters (none over TOTAL and HARGA SETELAH RESTITUSI).
        $row3 = ['NO.', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'LAYANAN', 'TYPE ORDER', 'ID', 'PERIOD', 'Bandwidth', null, null, 'IP Host Telkom', 'TANGGAL AKTIVASI', 'TANGGAL MULAI AKTIF LAYANAN  (UPGRADE/DOWNGRADE/ MANAGE SERVICE)', "PEMAKAIAN AVERAGE\n (Kbps)", null, 'TRAFFIK TOTAL (GB)', null, 'DURASI DOWNTIME', 'SLA', null, 'AVAILABILITY (Detik/bulan)', 'RESTITUSI (Rp)', null, 'HARGA (Rp)', null, null, null, null, null, 'TOTAL', 'HARGA SETELAH RESTITUSI', 'SUB TOTAL PER CABANG', 'MIKROTIK/NONMIKROTIK', 'MIKROTIK/NONMIKROTIK'];
        $row4 = [null, null, null, null, null, null, 'SID SEPTEMBER 2026', null, 'Eksisting BW', 'Upgrade BW', 'Downgrade BW', null, null, null, 'Eksisting', 'Upgrade', 'Eksisting', 'Upgrade', '(Detik)', 'SLA ACTUAL', 'SLA PKS', null, 'Restitusi Over SLA', 'Restitusi Tagihan', 'Eksisting', 'Upgrade (Prorate)', 'Downgrade (Prorate)', 'Cabut (Prorate)', 'PSB', 'Relokasi', 'TOTAL', 'HARGA SETELAH RESTITUSI', 'SUB TOTAL PER CABANG', 'MIKROTIK/NONMIKROTIK', 'KETERANGAN'];
        $letters = array_map(Coordinate::stringFromColumnIndex(...), range(1, 35));
        $row5 = array_replace($letters, [30 => null, 31 => null]);
        $this->assertSame($row3, array_slice($rows[3], 0, 35));
        $this->assertSame($row4, array_slice($rows[4], 0, 35));
        $this->assertSame($row5, array_slice($rows[5], 0, 35));
        $this->assertSame(['AE', 'AF', 'AI'], [$letters[30], $letters[31], $letters[34]]);
        $this->assertSame('AI', $detail->getHighestColumn(), 'Without a special column the sheet ends at KETERANGAN in AI.');
        $this->assertNotContains(self::SPECIAL, $rows[4]);

        $this->assertEqualsCanonicalizing(['A3:A4', 'B3:B4', 'C3:C4', 'D3:D4', 'E3:E4', 'F3:F4', 'H3:H4', 'I3:K3', 'L3:L4', 'M3:M4', 'N3:N4', 'O3:P3', 'Q3:R3', 'T3:U3', 'V3:V4', 'W3:X3', 'Y3:AD3'], array_values($detail->getMergeCells()), 'ID, DURASI DOWNTIME and TOTAL to KETERANGAN are not merged.');
        foreach ([1 => 15, 2 => 15.75, 3 => 33.75, 4 => 26.25, 5 => 18.75] as $row => $height) {
            $this->assertEqualsWithDelta($height, $detail->getRowDimension($row)->getRowHeight(), 0.001, 'Row '.$row);
        }
        $this->assertSame('D6', $detail->getFreezePane());
        $this->assertSame('A5:AI7', $detail->getAutoFilter()->getRange(), 'The filter covers the SID rows, not the TOTAL row.');
        $this->assertSame([3, 5], $detail->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertSame(PageSetup::PAPERSIZE_A3, $detail->getPageSetup()->getPaperSize());
        $this->assertSame('landscape', $detail->getPageSetup()->getOrientation());
        $book->disconnectWorksheets();
    }

    public function test_detail_layanan_header_fills_fonts_and_borders_follow_the_customer_sheet(): void
    {
        $book = $this->issuedWorkbook($this->issuedRevision());
        $detail = $book->getSheetByName('Detail Layanan');

        // Every header cell: Arial 12 bold black, centred, thin border, no wrap, on the fill of its row, unless listed here.
        $style = fn (string $fill, string $font = 'Arial', int $size = 12, bool $bold = true, bool $wrap = false, string $color = 'FF000000') => [$fill, $font, $size, $bold, $wrap, $color];
        $blue = 'FFD9E7FD';
        $yellow = 'FFFDD868';
        $expected = [
            'A3' => $style('FFDAE9F8'), 'A4' => $style('FFDAE9F8'), 'B3' => $style($blue), 'C3' => $style($blue),
            'D3' => $style('FFC9DAF8', 'Calibri'), 'E3' => $style($blue), 'F3' => $style($blue), 'G3' => $style($blue), 'H3' => $style($blue), 'I3' => $style($blue),
            'L3' => $style($blue, 'Calibri'), 'M3' => $style($blue, 'Calibri', wrap: true), 'N3' => $style($blue, wrap: true),
            'O3' => $style($blue, size: 11, wrap: true), 'Q3' => $style($blue, size: 11), 'S3' => $style($blue, wrap: true), 'T3' => $style($blue),
            'V3' => $style('FFCFE2F3', 'Calibri', wrap: true), 'W3' => $style($blue), 'Y3' => $style($blue),
            'AE3' => $style($blue), 'AF3' => $style($blue, wrap: true), 'AG3' => $style($blue, wrap: true), 'AH3' => $style($blue, wrap: true), 'AI3' => $style($blue, wrap: true),
            'G4' => $style($yellow), 'I4' => $style($yellow), 'K4' => $style($yellow),
            'O4' => $style($yellow, 'Calibri', 11), 'P4' => $style($yellow, size: 11), 'Q4' => $style($yellow, size: 11), 'R4' => $style($yellow, size: 11),
            'S4' => $style($yellow), 'T4' => $style($yellow), 'U4' => $style($yellow),
            'W4' => $style('FFFFD966', size: 11, wrap: true), 'X4' => $style($yellow, 'Calibri', wrap: true), 'Y4' => $style($yellow), 'AD4' => $style($yellow),
            'AE4' => $style($yellow), 'AF4' => $style($yellow, wrap: true), 'AG4' => $style($yellow, wrap: true), 'AH4' => $style($yellow), 'AI4' => $style($yellow),
            'A5' => $style('FFFFC000'), 'B5' => $style($yellow), 'D5' => $style($yellow, 'Calibri'), 'F5' => $style($yellow, bold: false),
            'L5' => $style($yellow, 'Calibri'), 'M5' => $style($yellow, 'Calibri'), 'N5' => $style($yellow, bold: false, color: 'FF0000FF'),
            'O5' => $style($yellow, 'Calibri', 11), 'P5' => $style($yellow, size: 11), 'Q5' => $style($yellow, size: 11), 'R5' => $style($yellow, size: 11),
            'V5' => $style('FFFFD966'), 'Y5' => $style($yellow), 'AE5' => $style($yellow), 'AF5' => $style($yellow), 'AG5' => $style($yellow), 'AI5' => $style($yellow),
        ];
        foreach ($expected as $cell => $want) {
            $look = $detail->getStyle($cell);
            $this->assertEquals($want, [$look->getFill()->getStartColor()->getARGB(), $look->getFont()->getName(), $look->getFont()->getSize(), $look->getFont()->getBold(), $look->getAlignment()->getWrapText(), $look->getFont()->getColor()->getARGB()], $cell);
            $this->assertSame('solid', $look->getFill()->getFillType(), $cell);
        }
        // All header cells (rows 3 to 5, A to AI) are centred both ways and have a thin border on every side.
        foreach (range(3, 5) as $row) {
            foreach (range(1, 35) as $column) {
                $cell = Coordinate::stringFromColumnIndex($column).$row;
                $look = $detail->getStyle($cell);
                $this->assertSame(['center', 'center'], [$look->getAlignment()->getHorizontal(), $look->getAlignment()->getVertical()], $cell);
                $this->assertSame(['thin', 'thin', 'thin', 'thin'], [$look->getBorders()->getTop()->getBorderStyle(), $look->getBorders()->getBottom()->getBorderStyle(), $look->getBorders()->getLeft()->getBorderStyle(), $look->getBorders()->getRight()->getBorderStyle()], $cell);
            }
        }
        $this->assertSame('FFDDEBF7', $book->getSheetByName('Harga')->getStyle('K3')->getFill()->getStartColor()->getARGB(), 'The Harga header keeps its own look.');
        $book->disconnectWorksheets();
    }

    public function test_detail_layanan_column_widths_follow_the_customer_sheet(): void
    {
        $book = $this->issuedWorkbook($this->issuedRevision());
        $detail = $book->getSheetByName('Detail Layanan');
        $widths = [
            'A' => 6.46, 'B' => 17.6, 'C' => 96.46, 'D' => 24.6, 'E' => 26.73, 'F' => 14.4, 'G' => 24, 'H' => 11.13, 'I' => 14.46, 'J' => 14.46, 'K' => 16.4,
            'L' => 22.86, 'M' => 17.27, 'N' => 25.86, 'O' => 12.86, 'P' => 12.86, 'Q' => 11.46, 'R' => 12.13, 'S' => 13.46, 'T' => 13.86, 'U' => 14.4, 'V' => 14.4,
            'W' => 17.46, 'X' => 18.13, 'Y' => 16.86, 'Z' => 17, 'AA' => 19.46, 'AB' => 16.73, 'AC' => 12.46, 'AD' => 12.46, 'AE' => 16.86, 'AF' => 22.13, 'AG' => 17.86, 'AH' => 25.4, 'AI' => 19.6,
        ];
        foreach ($widths as $column => $width) {
            $this->assertEqualsWithDelta($width, $detail->getColumnDimension($column)->getWidth(), 0.001, $column);
        }
        $book->disconnectWorksheets();
    }

    public function test_a_special_column_sits_after_eksisting_and_shifts_the_later_ones(): void
    {
        $revision = $this->issuedRevision(true);
        $snapshot = $revision->snapshot;
        $this->assertSame('70000.00', $snapshot['totals']['special_total']);
        $this->assertSame(['70000.00', '0.00'], array_column($snapshot['lines'], 'special_total'));
        $book = $this->issuedWorkbook($revision);
        $detail = $book->getSheetByName('Detail Layanan');
        $rows = $this->rows($detail);
        $value = fn (string $cell) => $detail->getCell($cell)->getValue();
        $this->assertSame('AJ', $detail->getHighestColumn());

        // Y to AJ: the special column sits in Z, everything from Upgrade on is one letter further right.
        $this->assertSame(['HARGA (Rp)', null, null, null, null, null, null, 'TOTAL', 'HARGA SETELAH RESTITUSI', 'SUB TOTAL PER CABANG', 'MIKROTIK/NONMIKROTIK', 'MIKROTIK/NONMIKROTIK'], array_slice($rows[3], 24, 12));
        $this->assertSame(['Eksisting', self::SPECIAL, 'Upgrade (Prorate)', 'Downgrade (Prorate)', 'Cabut (Prorate)', 'PSB', 'Relokasi', 'TOTAL', 'HARGA SETELAH RESTITUSI', 'SUB TOTAL PER CABANG', 'MIKROTIK/NONMIKROTIK', 'KETERANGAN'], array_slice($rows[4], 24, 12));
        $this->assertSame(['Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', null, null, 'AH', 'AI', 'AJ'], array_slice($rows[5], 24, 12));
        $merged = array_values($detail->getMergeCells());
        $this->assertContains('Y3:AE3', $merged, 'HARGA (Rp) covers the special column too.');
        $this->assertNotContains('Y3:AD3', $merged);
        $this->assertNotContains('AF3:AF4', $merged);
        $this->assertSame('A5:AJ7', $detail->getAutoFilter()->getRange());
        foreach (['AA' => 17, 'AB' => 19.46, 'AC' => 16.73, 'AD' => 12.46, 'AE' => 12.46, 'AF' => 16.86, 'AG' => 22.13, 'AH' => 17.86, 'AI' => 25.4, 'AJ' => 19.6, 'Z' => 29] as $column => $width) {
            $this->assertEqualsWithDelta($width, $detail->getColumnDimension($column)->getWidth(), 0.001, $column);
        }
        $label = $detail->getStyle('Z4');
        $this->assertEquals(['FF00FFFF', 'Arial', 9, true, true], [$label->getFill()->getStartColor()->getARGB(), $label->getFont()->getName(), $label->getFont()->getSize(), $label->getFont()->getBold(), $label->getAlignment()->getWrapText()]);
        $this->assertSame('FF00FFFF', $detail->getStyle('Z5')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFDD868', $detail->getStyle('AA4')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFDD868', $detail->getStyle('AG5')->getFill()->getStartColor()->getARGB(), 'HARGA SETELAH RESTITUSI stays filled on row 5, without a letter.');

        // The first SID is in the special column; the second is not, so its cell is empty.
        [$first, $second] = $snapshot['lines'];
        $this->assertSame('0012-ABC', $first['sid']);
        $this->assertEquals(70000.0, $value('Z6'));
        $this->assertNull($value('Z7'));
        $this->assertSame(['#,##0', 'right'], [$detail->getStyle('Z6')->getNumberFormat()->getFormatCode(), $detail->getStyle('Z6')->getAlignment()->getHorizontal()]);
        $this->assertSame('right', $detail->getStyle('Z7')->getAlignment()->getHorizontal());
        $this->assertEquals((float) $first['charges']['existing'], $value('Y6'));
        $this->assertEquals((float) $first['gross'], $value('AF6'), 'TOTAL includes the special column.');
        $this->assertEqualsWithDelta($value('Y6') + $value('Z6'), $value('AF6'), 0.005);
        $this->assertEquals((float) $first['net'], $value('AG6'));
        $this->assertGreaterThan(0, $value('AA7'), 'Upgrade is one letter right of where it is without a special column.');
        $this->assertNull($value('AA6'));
        $this->assertEquals((float) $second['net'], $value('AG7'));
        $this->assertEquals((float) $snapshot['totals']['branch_totals']['Cabang Test']['net'], $value('AH6'));
        $this->assertNull($value('AH7'));
        foreach (['AI6', 'AJ6', 'AI7', 'AJ7'] as $cell) {
            $this->assertNull($value($cell), $cell.': the system has no Mikrotik data.');
        }
        $this->assertSame(['#,##0.00', '#,##0', '#,##0'], [$detail->getStyle('AF6')->getNumberFormat()->getFormatCode(), $detail->getStyle('AG6')->getNumberFormat()->getFormatCode(), $detail->getStyle('AH6')->getNumberFormat()->getFormatCode()]);
        $this->assertSame('thin', $detail->getStyle('AJ7')->getBorders()->getBottom()->getBorderStyle());

        // TOTAL row.
        $t = $snapshot['totals'];
        $this->assertEquals((float) $t['special_total'], $value('Z8'));
        $this->assertEquals((float) $t['components']['existing'], $value('Y8'));
        $this->assertEquals((float) $t['components']['upgrade'], $value('AA8'));
        $this->assertEquals((float) $t['components']['relocation'], $value('AE8'));
        $this->assertEquals((float) $t['gross'], $value('AF8'));
        $this->assertEquals((float) $t['net_exact'], $value('AG8'));
        $this->assertEquals((float) $t['net_exact'], $value('AH8'));
        $this->assertEquals((float) $t['sla_credit'], $value('W8'));
        $this->assertEqualsWithDelta($value('AG6') + $value('AG7'), $value('AG8'), 0.005);
        $book->disconnectWorksheets();

        // The saved file is checked with the shifted letters: the net column is AG, and the total of the special column must agree.
        $this->assertNull($this->validationError($revision, $snapshot));
        $tampered = $snapshot;
        $tampered['totals']['net_exact'] = (string) BigDecimal::of($snapshot['totals']['net_exact'])->plus('1.00');
        $this->assertSame('Total invoice rinci tidak sama dengan snapshot.', $this->validationError($revision, $tampered));
        $tampered = $snapshot;
        $tampered['totals']['special_columns'][0]['amount'] = '70001.00';
        $this->assertSame('Total kolom spesial tidak sama dengan snapshot.', $this->validationError($revision, $tampered));
    }

    public function test_two_special_columns_sit_side_by_side_with_their_own_totals_and_summary_rows(): void
    {
        $snapshot = $this->issuedRevision(true)->snapshot;
        $snapshot['lines'][1]['special']['Biaya kedua'] = '5000.00';
        $snapshot['totals']['special_columns'][] = ['name' => 'Biaya kedua', 'summary' => 'Biaya kedua', 'amount' => '5000.00'];
        $book = $this->reissue($snapshot);
        $detail = $book->getSheetByName('Detail Layanan');
        $value = fn (string $cell) => $detail->getCell($cell)->getValue();

        $this->assertSame('AK', $detail->getHighestColumn());
        $this->assertSame(['Eksisting', self::SPECIAL, 'Biaya kedua', 'Upgrade (Prorate)'], array_slice($this->rows($detail)[4], 24, 4));
        $this->assertContains('Y3:AF3', array_values($detail->getMergeCells()));
        $this->assertSame([70000.0, null, 70000.0], [$value('Z6'), $value('Z7'), $value('Z8')]);
        $this->assertSame([null, 5000.0, 5000.0], [$value('AA6'), $value('AA7'), $value('AA8')]);
        foreach (['Z4', 'AA4', 'Z5', 'AA5'] as $cell) {
            $this->assertSame('FF00FFFF', $detail->getStyle($cell)->getFill()->getStartColor()->getARGB(), $cell);
        }
        $this->assertEqualsWithDelta(29, $detail->getColumnDimension('AA')->getWidth(), 0.001);

        $labels = array_filter(array_column($this->rows($book->getSheetByName('Summary Invoice')), 1), fn ($label) => is_string($label) && preg_match('/^[KL]\. /', $label));
        $this->assertSame(['K. Total Nilai Biaya contoh', 'L. Total Nilai Biaya kedua'], array_values($labels));
        $book->disconnectWorksheets();
    }

    public function test_a_month_without_special_columns_has_none_and_ends_at_ai(): void
    {
        $snapshot = $this->issuedRevision()->snapshot;
        $this->assertSame('0.00', $snapshot['totals']['special_total']);
        // A snapshot frozen before special columns existed has none of these keys.
        $older = $snapshot;
        unset($older['totals']['special_total'], $older['totals']['special_columns']);
        foreach ($older['lines'] as $i => $line) {
            unset($older['lines'][$i]['special'], $older['lines'][$i]['special_total']);
        }

        foreach ([$snapshot, $older] as $s) {
            $book = $this->reissue($s);
            $detail = $book->getSheetByName('Detail Layanan');
            $rows = $this->rows($detail);
            $this->assertSame('AI', $detail->getHighestColumn());
            $this->assertSame(['Eksisting', 'Upgrade (Prorate)', 'Downgrade (Prorate)', 'Cabut (Prorate)', 'PSB', 'Relokasi', 'TOTAL', 'HARGA SETELAH RESTITUSI', 'SUB TOTAL PER CABANG', 'MIKROTIK/NONMIKROTIK', 'KETERANGAN'], array_slice($rows[4], 24, 11));
            $this->assertNotContains(self::SPECIAL, $rows[4]);
            $this->assertContains('Y3:AD3', array_values($detail->getMergeCells()));
            $this->assertSame('A5:AI7', $detail->getAutoFilter()->getRange());
            $this->assertEquals((float) $s['totals']['net_exact'], $detail->getCell('AF8')->getValue());
            $this->assertEquals((float) $s['totals']['gross'], $detail->getCell('AE8')->getValue());
            $book->disconnectWorksheets();
        }
    }

    public function test_detail_layanan_has_a_row_per_sid_then_a_total_row_that_ties_to_the_snapshot(): void
    {
        $revision = $this->issuedRevision();
        $snapshot = $revision->snapshot;
        $book = $this->issuedWorkbook($revision);
        $detail = $book->getSheetByName('Detail Layanan');
        $value = fn (string $cell) => $detail->getCell($cell)->getValue();
        $this->assertSame(8, $detail->getHighestRow(), 'Rows 1-5 header, one row per SID (6, 7), TOTAL (8) and nothing below.');

        $components = ['Y' => 'existing', 'Z' => 'upgrade', 'AA' => 'downgrade', 'AB' => 'termination', 'AC' => 'installation', 'AD' => 'relocation'];
        foreach ($snapshot['lines'] as $i => $line) {
            $r = 6 + $i;
            $this->assertEquals($line['number'], $value('A'.$r));
            foreach (['B' => 'branch', 'C' => 'address', 'D' => 'category', 'E' => 'service', 'I' => 'bandwidth'] as $column => $key) {
                $this->assertSame($line[$key], $value($column.$r), $column);
            }
            $this->assertSame($line['sid'], $value('G'.$r));
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('G'.$r)->getDataType(), 'SIDs are text, so leading zeros survive.');
            $this->assertEquals(202609, $value('H'.$r));
            $this->assertEquals($line['service_snapshot']['ip'] ?? null, $value('L'.$r));
            $this->assertEquals($this->number($line['traffic']['average_kbps'] ?? null), $value('O'.$r));
            $this->assertEquals($this->number($line['traffic']['volume_gb'] ?? null), $value('Q'.$r));
            $this->assertEquals($line['downtime_seconds'], $value('S'.$r));
            $this->assertEquals((float) $line['sla'], $value('T'.$r));
            $this->assertEquals((float) $line['sla_target'], $value('U'.$r));
            $this->assertEquals($line['billing_seconds'], $value('V'.$r));
            $this->assertEquals((float) $line['sla_credit'], $value('W'.$r));
            $this->assertEquals((float) $line['manual_credit'], $value('X'.$r));
            foreach ($components as $column => $component) {
                $amount = (float) $line['charges'][$component];
                if ($column === 'Y' || $amount !== 0.0) {
                    $this->assertEquals($amount, $value($column.$r), $column.$r);
                } else {
                    $this->assertNull($value($column.$r), $column.$r.' stays empty when nothing is charged.');
                }
            }
            $this->assertEquals((float) $line['gross'], $value('AE'.$r));
            $this->assertEquals((float) $line['net'], $value('AF'.$r));
        }
        $this->assertGreaterThan(0, (float) $snapshot['totals']['sla_credit'], 'The fixture has an SLA credit.');

        // Existing SID: no bandwidth change, no change date.
        $this->assertSame(['EKSISTING', '0012-ABC', 'Cabang Test'], [$value('F6'), $value('G6'), $value('B6')]);
        $this->assertNull($value('J6'));
        $this->assertNull($value('K6'));
        $this->assertNull($value('N6'));
        $this->assertNull($value('Z6'));
        $this->assertSame([null, null], [$value('AH6'), $value('AI6')], 'MIKROTIK/NONMIKROTIK and KETERANGAN stay empty: the system has no such data.');
        // Upgraded SID: new bandwidth under Upgrade BW, effective date of the change, prorated upgrade charge.
        $this->assertSame(['UPGRADE', '00123456', '512 Kbps', '1 Mbps'], [$value('F7'), $value('G7'), $value('I7'), $value('J7')]);
        $this->assertNull($value('K7'));
        $this->assertSame('22/08/2026', $detail->getCell('N7')->getFormattedValue());
        $this->assertGreaterThan(0, $value('Z7'));
        $this->assertNull($value('AA7'));
        $this->assertSame(53568, (int) $value('S7'));

        // The branch subtotal sits on the first row of each branch only.
        $branchNet = (float) $snapshot['totals']['branch_totals']['Cabang Test']['net'];
        $this->assertEquals($branchNet, $value('AG6'));
        $this->assertNull($value('AG7'));
        $this->assertEqualsWithDelta($value('AF6') + $value('AF7'), $branchNet, 0.005);

        // Formats and styling of the data rows.
        $format = fn (string $cell) => $detail->getStyle($cell)->getNumberFormat()->getFormatCode();
        $this->assertSame(['@', '#,##0', '0.00%', '0.00%', 'dd/mm/yyyy', 'dd/mm/yyyy', '#,##0.00', '#,##0.00', '#,##0', '#,##0.00', '#,##0', '#,##0'], array_map($format, ['G6', 'Y6', 'T6', 'U6', 'M6', 'N7', 'O6', 'Q6', 'W6', 'X6', 'AF6', 'AG6']));
        $this->assertSame('#,##0.00', $format('AE6'));
        $this->assertSame('0', $format('S6'));
        $this->assertTrue($detail->getStyle('B6')->getFont()->getBold());
        $this->assertSame('FFC6EFCE', $detail->getStyle('B7')->getFill()->getStartColor()->getARGB());
        $this->assertTrue($detail->getStyle('C6')->getAlignment()->getWrapText());
        $this->assertSame('thin', $detail->getStyle('AG7')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame('thin', $detail->getStyle('AI7')->getBorders()->getBottom()->getBorderStyle());

        // TOTAL row: the totals of the snapshot, not recomputed.
        $t = $snapshot['totals'];
        $this->assertSame('TOTAL', $value('C8'));
        $this->assertTrue($detail->getStyle('C8')->getFont()->getBold());
        $this->assertSame('medium', $detail->getStyle('C8')->getBorders()->getTop()->getBorderStyle());
        foreach ($components as $column => $component) {
            $this->assertEquals((float) $t['components'][$component], $value($column.'8'), $column.'8');
        }
        $this->assertEquals((float) $t['sla_credit'], $value('W8'));
        $this->assertEquals((float) $t['manual_credit'], $value('X8'));
        $this->assertEquals((float) $t['gross'], $value('AE8'));
        $this->assertEquals((float) $t['net_exact'], $value('AF8'));
        $this->assertEquals((float) $t['net_exact'], $value('AG8'));
        $this->assertEqualsWithDelta($value('AF6') + $value('AF7'), $value('AF8'), 0.005, 'The TOTAL row is the sum of the SID rows.');
        $book->disconnectWorksheets();
    }

    public function test_the_type_order_and_bandwidth_columns_follow_the_events(): void
    {
        $snapshot = $this->issuedRevision()->snapshot;
        $snapshot['lines'][0]['order'] = 'PASANG_BARU';
        $snapshot['lines'][0]['events'] = [['event_type' => 'pasang_baru', 'effective_date' => '2026-08-10', 'attributes' => []]];
        $snapshot['lines'][1]['order'] = 'MULTIPLE';
        $snapshot['lines'][1]['bandwidth_change'] = '2 Mbps';
        $snapshot['lines'][1]['events'] = [
            ['event_type' => 'upgrade', 'effective_date' => '2026-08-05', 'attributes' => []],
            ['event_type' => 'relokasi', 'effective_date' => null, 'attributes' => []],
            ['event_type' => 'downgrade', 'effective_date' => '2026-08-20 00:00:00', 'attributes' => []],
        ];
        $book = $this->reissue($snapshot);
        $detail = $book->getSheetByName('Detail Layanan');
        $this->assertSame('PSB', $detail->getCell('F6')->getValue());
        $this->assertNull($detail->getCell('N6')->getValue(), 'A PSB has no upgrade/downgrade date.');
        $this->assertSame('UPGRADE + RELOKASI + DOWNGRADE', $detail->getCell('F7')->getValue());
        $this->assertSame(['2 Mbps', '2 Mbps'], [$detail->getCell('J7')->getValue(), $detail->getCell('K7')->getValue()]);
        $this->assertSame('20/08/2026', $detail->getCell('N7')->getFormattedValue(), 'The last upgrade/downgrade date.');
        $book->disconnectWorksheets();

        foreach (['RELOKASI' => 'RELOKASI', 'DOWNGRADE' => 'DOWNGRADE', 'PEMUTUSAN' => 'CABUT', 'EXISTING' => 'EKSISTING'] as $order => $label) {
            $snapshot['lines'][0]['order'] = $order;
            $snapshot['lines'][0]['events'] = [];
            $book = $this->reissue($snapshot);
            $this->assertSame($label, $book->getSheetByName('Detail Layanan')->getCell('F6')->getValue(), $order);
            $book->disconnectWorksheets();
        }
    }

    public function test_a_draft_is_marked_on_the_detail_and_summary_sheets(): void
    {
        $snapshot = $this->issuedRevision()->snapshot;
        $book = $this->reissue($snapshot);
        $this->assertNull($book->getSheetByName('Detail Layanan')->getCell('C1')->getValue());
        $this->assertStringStartsNotWith('DRAFT', $book->getSheetByName('Summary Invoice')->getCell('B2')->getValue());
        $book->disconnectWorksheets();

        $snapshot['metadata']['is_preview'] = true;
        $book = $this->reissue($snapshot);
        $detail = $book->getSheetByName('Detail Layanan');
        $this->assertSame('DRAFT - BELUM DIFINALISASI', $detail->getCell('C1')->getValue());
        $this->assertTrue($detail->getStyle('C1')->getFont()->getBold());
        $this->assertSame('FFE6002D', $detail->getStyle('C1')->getFont()->getColor()->getARGB());
        $this->assertSame('DRAFT - BELUM DIFINALISASI - '.SummaryImage::rows($snapshot)['title'], $book->getSheetByName('Summary Invoice')->getCell('B2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_harga_lists_the_tariffs_valid_in_the_usage_period(): void
    {
        $revision = $this->issuedRevision();
        $book = $this->issuedWorkbook($revision);
        $harga = $book->getSheetByName('Harga');
        $rows = $this->rows($harga);

        $this->assertSame("DAFTAR HARGA LAYANAN \u{2013} September 2026", $rows[1][0]);
        $this->assertTrue($harga->getStyle('A1')->getFont()->getBold());
        $this->assertSame(['KATEGORI LOKASI', 'LAYANAN', 'BANDWIDTH', 'ROUTER', 'HARGA BULANAN', 'BIAYA INSTALASI', 'BIAYA RELOKASI', 'SID KHUSUS', 'MULAI BERLAKU', 'BERLAKU SAMPAI', 'KONTRAK'], $rows[3]);
        $this->assertTrue($harga->getStyle('A3')->getFont()->getBold());
        $this->assertSame('FFDDEBF7', $harga->getStyle('K3')->getFill()->getStartColor()->getARGB());
        $this->assertSame('thin', $harga->getStyle('K7')->getBorders()->getBottom()->getBorderStyle());
        $this->assertSame('A4', $harga->getFreezePane());
        $this->assertSame('A3:K7', $harga->getAutoFilter()->getRange());
        $this->assertSame(7, $harga->getHighestRow(), 'Four listed tariffs: the expired, the not yet valid and the inactive one are left out.');

        // Sorted by the category name as shown ("Semua kategori" sorts by that name, so after KK), then service, bandwidth by speed, router, SID.
        $this->assertEquals(['KK', 'TELKOM VPN IP', '512 Kbps', 'Tanpa router', 2319819.0, 1981982.0, 0.0], array_slice($rows[4], 0, 7));
        $this->assertEquals(['KK', 'TELKOM VPN IP', '1 Mbps', 'Tanpa router', 1300000.0, 1981982.0, 0.0], array_slice($rows[5], 0, 7));
        $this->assertEquals(['KK', 'TELKOM VPN IP', '1 Mbps', 'Tanpa router', 1200000.0], array_slice($rows[6], 0, 5));
        $this->assertEquals(['Semua kategori', 'TELKOM VPN IP', '1 Mbps', 'Dengan router', 1350000.0, 2000000.0, 0.0], array_slice($rows[7], 0, 7));
        $this->assertSame([null, null, '0012-ABC'], [$rows[4][7], $rows[7][7], $rows[6][7]]);
        $this->assertSame('01/01/2020', $harga->getCell('I4')->getFormattedValue());
        $this->assertSame('01/01/2026', $harga->getCell('I7')->getFormattedValue());
        $this->assertSame('#,##0', $harga->getStyle('E4')->getNumberFormat()->getFormatCode());
        $this->assertEmpty(array_filter(array_column($rows, 2), fn ($bandwidth) => $bandwidth === '2 Mbps'));
        $this->assertEmpty(array_filter(array_column($rows, 4), fn ($price) => in_array($price, [999001.0, 999002.0, 999003.0], true)));
        $book->disconnectWorksheets();
    }

    public function test_harga_rows_are_sorted_by_category_and_coloured_per_category(): void
    {
        $snapshot = $this->issuedRevision()->snapshot;
        $kk = LocationCategory::where('location_category_name', 'KK')->firstOrFail();
        $alpha = LocationCategory::create(['location_category_code' => 'AL', 'location_category_name' => 'alpha']);
        $zulu = LocationCategory::create(['location_category_code' => 'ZU', 'location_category_name' => 'Zulu']);
        // A category id one full palette round after KK has no name in the database, so it is shown as "Kategori #id".
        $wrapped = $kk->id + count(self::CATEGORY_COLORS);
        $template = collect($snapshot['tariffs'])->first(fn ($t) => $t['location_category_id'] !== null && empty($t['sid']) && empty($t['effective_end_date']) && $t['is_active']);
        foreach ([$zulu->id, $alpha->id, $wrapped] as $id) {
            $snapshot['tariffs'][] = [...$template, 'location_category_id' => $id];
        }
        $book = $this->reissue($snapshot);
        $harga = $book->getSheetByName('Harga');
        $rows = $this->rows($harga);
        $color = fn (int $id) => 'FF'.self::CATEGORY_COLORS[($id - 1) % count(self::CATEGORY_COLORS)];
        $fill = fn (string $cell) => $harga->getStyle($cell)->getFill()->getStartColor()->getARGB();

        // Case-insensitive by the shown name ("alpha" before "Kategori #n" before "KK"); inside KK the old order stays.
        $this->assertSame(10, $harga->getHighestRow());
        $this->assertSame(['alpha', 'Kategori #'.$wrapped, 'KK', 'KK', 'KK', 'Semua kategori', 'Zulu'], array_column(array_slice($rows, 3, 7), 0));
        $this->assertEquals([2319819.0, 1300000.0, 1200000.0], array_column(array_slice($rows, 5, 3), 4));

        // Every column of a row has the colour of its category; the same category keeps one colour, different ones differ.
        $expected = [4 => $color($alpha->id), 5 => $color($wrapped), 6 => $color($kk->id), 7 => $color($kk->id), 8 => $color($kk->id), 9 => 'FFEFEFEF', 10 => $color($zulu->id)];
        foreach ($expected as $row => $argb) {
            foreach (range('A', 'K') as $column) {
                $this->assertSame($argb, $fill($column.$row), $column.$row);
            }
            $this->assertSame('solid', $harga->getStyle('A'.$row)->getFill()->getFillType());
        }
        $this->assertCount(4, array_unique([$fill('A4'), $fill('A6'), $fill('A9'), $fill('A10')]), 'alpha, KK, Semua kategori and Zulu differ.');
        $this->assertSame($fill('A6'), $fill('A5'), 'A category id one palette round on has the same colour again.');
        $this->assertSame('FFDDEBF7', $fill('A3'), 'The header keeps its own colour.');

        // The colour follows the category id, not the position: without the alpha tariff KK keeps its colour.
        $book->disconnectWorksheets();
        $snapshot['tariffs'] = array_values(array_filter($snapshot['tariffs'], fn ($t) => $t['location_category_id'] !== $alpha->id));
        $book = $this->reissue($snapshot);
        $harga = $book->getSheetByName('Harga');
        $this->assertSame('KK', $harga->getCell('A5')->getValue());
        $this->assertSame($color($kk->id), $harga->getStyle('A5')->getFill()->getStartColor()->getARGB());
        $book->disconnectWorksheets();
    }

    public function test_the_category_decides_the_harga_order_before_the_service(): void
    {
        $snapshot = $this->issuedRevision()->snapshot;
        $alpha = LocationCategory::create(['location_category_code' => 'AL', 'location_category_name' => 'alpha']);
        $zulu = LocationCategory::create(['location_category_code' => 'ZU', 'location_category_name' => 'Zulu']);
        $first = Service::create(['service_code' => 'AAA', 'service_name' => 'AAA LAYANAN']);
        $last = Service::create(['service_code' => 'ZZZ', 'service_name' => 'ZZZ LAYANAN']);
        $template = collect($snapshot['tariffs'])->first(fn ($t) => $t['location_category_id'] !== null && empty($t['sid']) && empty($t['effective_end_date']) && $t['is_active']);
        // Sorted by service first, these two rows would come out the other way round.
        $snapshot['tariffs'] = [
            [...$template, 'location_category_id' => $zulu->id, 'service_id' => $first->id],
            [...$template, 'location_category_id' => $alpha->id, 'service_id' => $last->id],
        ];
        $book = $this->reissue($snapshot);
        $rows = $this->rows($book->getSheetByName('Harga'));
        $book->disconnectWorksheets();

        $this->assertSame([['alpha', 'ZZZ LAYANAN'], ['Zulu', 'AAA LAYANAN']], array_map(fn ($row) => array_slice($row, 0, 2), array_slice($rows, 3, 2)));
    }

    public function test_harga_follows_the_calculation_snapshot_and_falls_back_to_master_harga(): void
    {
        $revision = $this->issuedRevision();
        $snapshot = $revision->snapshot;
        $this->assertNotEmpty($snapshot['tariffs']);

        // Master Harga changes after the invoice was calculated: a reissued file still shows the calculated tariffs.
        PriceMaster::query()->update(['monthly_price' => '1']);
        $book = $this->reissue($snapshot);
        $this->assertEquals(2319819.0, $book->getSheetByName('Harga')->getCell('E4')->getValue());
        $this->assertSame(7, $book->getSheetByName('Harga')->getHighestRow());
        $book->disconnectWorksheets();

        // A snapshot that holds no tariff list reads Master Harga.
        unset($snapshot['tariffs']);
        $book = $this->reissue($snapshot);
        $this->assertEquals(1.0, $book->getSheetByName('Harga')->getCell('E4')->getValue());
        $this->assertSame(7, $book->getSheetByName('Harga')->getHighestRow());
        $book->disconnectWorksheets();

        // A snapshot with an empty list lists nothing.
        $snapshot['tariffs'] = [];
        $book = $this->reissue($snapshot);
        $this->assertSame(3, $book->getSheetByName('Harga')->getHighestRow());
        $book->disconnectWorksheets();
    }

    public function test_summary_invoice_carries_the_same_figures_as_the_picture(): void
    {
        $revision = $this->issuedRevision();
        $snapshot = $revision->snapshot;
        $book = $this->issuedWorkbook($revision);
        $summary = $book->getSheetByName('Summary Invoice');
        $rows = $this->rows($summary);
        $picture = SummaryImage::rows($snapshot);

        $this->assertNotNull($revision->outputs()->where('output_type', 'summary_png')->first(), 'The picture is issued next to the workbook.');
        $this->assertEquals([4, 58, 22, 26], array_map(fn (string $column) => $summary->getColumnDimension($column)->getWidth(), ['A', 'B', 'C', 'D']));
        $this->assertSame($picture['title'], $rows[2][1]);
        $this->assertContains('B2:D2', $summary->getMergeCells());
        $this->assertSame(['16', true], [(string) $summary->getStyle('B2')->getFont()->getSize(), $summary->getStyle('B2')->getFont()->getBold()]);
        $this->assertSame($picture['subtitle'], $rows[4][1]);
        $this->assertSame('single', $summary->getStyle('B4')->getFont()->getUnderline());
        $this->assertSame(['KATEGORI', 'TOTAL TITIK', 'TOTAL NILAI'], array_slice($rows[6], 1, 3));
        $this->assertSame(6 + count($picture['rows']) - 1, $summary->getHighestRow(), 'The table is compact: no info block and no branch section.');
        $this->assertNull($summary->getCell('A6')->getValue());

        $rupiah = '"Rp"#,##0;-"Rp"#,##0';
        foreach ($picture['rows'] as $i => $row) {
            $r = 6 + $i;
            $this->assertSame(($row['key'] ?? null) === 'tax' ? 'PPN 11%' : $row['label'], $rows[$r][1], 'B'.$r);
            switch ($row['type']) {
                case 'band':
                    $this->assertContains('B'.$r.':D'.$r, $summary->getMergeCells());
                    $this->assertSame('FFFF0000', $summary->getStyle('B'.$r)->getFill()->getStartColor()->getARGB());
                    $this->assertSame('FFFFFFFF', $summary->getStyle('B'.$r)->getFont()->getColor()->getARGB());
                    break;
                case 'item':
                case 'total':
                    $this->assertEquals($row['points'], $rows[$r][2], 'C'.$r);
                    $this->assertEquals((float) $row['amount'], $rows[$r][3], 'D'.$r);
                    $this->assertSame([$row['type'] === 'total', $rupiah, '0'], [$summary->getStyle('D'.$r)->getFont()->getBold(), $summary->getStyle('D'.$r)->getNumberFormat()->getFormatCode(), $summary->getStyle('C'.$r)->getNumberFormat()->getFormatCode()]);
                    break;
                case 'sla':
                    $this->assertContains('B'.$r.':C'.$r, $summary->getMergeCells());
                    $this->assertEquals((float) ($row['points'] ?? $row['amount']), $rows[$r][3], 'D'.$r);
                    $this->assertSame(isset($row['points']) ? '0' : $rupiah, $summary->getStyle('D'.$r)->getNumberFormat()->getFormatCode());
                    $this->assertSame('FFFF0000', $summary->getStyle('D'.$r)->getFill()->getStartColor()->getARGB());
                    break;
                case 'final':
                    $this->assertEquals((float) $row['amount'], $rows[$r][3], 'D'.$r);
                    $this->assertSame('FFFF0000', $summary->getStyle('B'.$r)->getFill()->getStartColor()->getARGB());
                    $this->assertSame(isset($row['bold']), $summary->getStyle('D'.$r)->getFont()->getBold());
                    $this->assertNull($rows[$r][2]);
                    break;
            }
        }

        $byLabel = collect($rows)->keyBy(1);
        $grandRow = $this->summaryRow($snapshot, 'grand_total');
        $grand = collect($picture['rows'])->firstWhere('key', 'grand_total');
        $this->assertSame('Grand Total Nilai Tagihan', $rows[$grandRow][1]);
        $this->assertSame((int) preg_replace('/\D/', '', $grand['value']), (int) $rows[$grandRow][3], 'The grand total equals the one drawn in the picture.');
        $this->assertEquals((float) $snapshot['totals']['grand_total'], $rows[$grandRow][3]);
        $this->assertLessThan(0, $byLabel['I.  Total Nilai Restitusi SLA Yang Tidak Terpenuhi'][3], 'Credits are negative, as in the picture.');
        $this->assertSame(0.0, (float) $byLabel['J. Total Nilai Restitusi Tagihan'][3]);
        $known = count(array_filter($snapshot['lines'], fn ($line) => $line['sla'] !== null));
        $this->assertEquals($known, $byLabel['G. Total Titik Lokasi SLA Yang Terpenuhi'][3] + $byLabel['H. Total Titik Lokasi SLA Yang Tidak Terpenuhi'][3], 'G and H count the locations with a known SLA.');

        // The Summary Invoice subtotal is the Detail Layanan total after restitution plus the rounding.
        $detail = $book->getSheetByName('Detail Layanan');
        $this->assertEqualsWithDelta($detail->getCell('AF8')->getValue() + (float) $snapshot['totals']['rounding'], $rows[$this->summaryRow($snapshot, 'subtotal')][3], 0.005);
        $this->assertSame('"Rp"#,##0;-"Rp"#,##0', $summary->getStyle('D'.$grandRow)->getNumberFormat()->getFormatCode());
        $this->assertSame(PageSetup::PAPERSIZE_A4, $summary->getPageSetup()->getPaperSize());
        $this->assertSame('portrait', $summary->getPageSetup()->getOrientation());
        $book->disconnectWorksheets();
    }

    public function test_the_ppn_label_comes_from_the_snapshot_tax_rate(): void
    {
        $snapshot = $this->issuedRevision()->snapshot;
        $labels = function (Spreadsheet $book) use ($snapshot) {
            $summary = $this->rows($book->getSheetByName('Summary Invoice'));

            return [$summary[$this->summaryRow($snapshot, 'tax')][1]];
        };

        $withRate = fn (string $rate) => array_replace_recursive($snapshot, ['profile' => ['settings' => ['tax_rate' => $rate]], 'metadata' => ['tax_rate' => $rate]]);
        foreach (['0.11' => 'PPN 11%', '0.12' => 'PPN 12%', '0.115' => 'PPN 11,5%', '0.1' => 'PPN 10%', '0' => 'PPN 0%'] as $rate => $expected) {
            $book = $this->reissue($withRate($rate));
            $this->assertSame([$expected], $labels($book), 'Rate '.$rate);
            $book->disconnectWorksheets();
        }

        // No rate recorded in the snapshot: the standard 11 % applies.
        unset($snapshot['profile']['settings']['tax_rate'], $snapshot['metadata']['tax_rate']);
        $book = $this->reissue($snapshot);
        $this->assertSame(['PPN 11%'], $labels($book));
        $book->disconnectWorksheets();
    }
}
