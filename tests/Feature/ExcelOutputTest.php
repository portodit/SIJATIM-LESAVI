<?php

namespace Tests\Feature;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
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
     * that must not be listed: expired before the usage month, starting after it, and inactive.
     */
    private function issuedRevision(): InvoiceRevision
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
        $this->assertSame(3, $revision->outputs()->count(), 'Excel, PDF and the summary picture are still issued.');
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
        $this->assertTrue($detail->getStyle('B2')->getFont()->getBold());
        $this->assertSame('Arial', $detail->getStyle('A6')->getFont()->getName());

        // Row 3: group header; row 4: sub-header; row 5: the column letters.
        $row3 = ['NO.', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'LAYANAN', 'TYPE ORDER', 'ID', 'PERIOD', 'Bandwidth', null, null, 'IP Host Telkom', 'TANGGAL AKTIVASI', 'TANGGAL MULAI AKTIF LAYANAN (UPGRADE/DOWNGRADE)', 'PEMAKAIAN AVERAGE (Kbps)', null, 'TRAFFIK TOTAL (GB)', null, 'DURASI DOWNTIME (Detik)', 'SLA', null, 'AVAILABILITY (Detik/bulan)', 'RESTITUSI (Rp)', null, 'HARGA (Rp)', null, null, null, null, null, 'TOTAL', 'HARGA SETELAH RESTITUSI', 'SUB TOTAL PER CABANG'];
        $row4 = [null, null, null, null, null, null, 'SID SEPTEMBER 2026', null, 'Eksisting BW', 'Upgrade BW', 'Downgrade BW', null, null, null, 'Eksisting', 'Upgrade', 'Eksisting', 'Upgrade', null, 'SLA ACTUAL', 'SLA PKS', null, 'Restitusi Over SLA', 'Restitusi Tagihan', 'Eksisting', 'Upgrade (Prorate)', 'Downgrade (Prorate)', 'Cabut (Prorate)', 'PSB', 'Relokasi', null, null, null];
        $letters = array_map(Coordinate::stringFromColumnIndex(...), range(1, 33));
        $this->assertSame($row3, array_slice($rows[3], 0, 33));
        $this->assertSame($row4, array_slice($rows[4], 0, 33));
        $this->assertSame($letters, array_slice($rows[5], 0, 33));
        $this->assertSame('AG', $letters[32]);

        $merged = array_values($detail->getMergeCells());
        foreach (['A3:A4', 'B3:B4', 'F3:F4', 'H3:H4', 'I3:K3', 'L3:L4', 'N3:N4', 'O3:P3', 'Q3:R3', 'S3:S4', 'T3:U3', 'V3:V4', 'W3:X3', 'Y3:AD3', 'AE3:AE4', 'AF3:AF4', 'AG3:AG4'] as $range) {
            $this->assertContains($range, $merged, $range);
        }
        $this->assertNotContains('G3:G4', $merged, 'ID and the SID heading are two rows.');

        $fill = fn (string $cell) => $detail->getStyle($cell)->getFill()->getStartColor()->getARGB();
        $this->assertSame('FFDDEBF7', $fill('A3'));
        $this->assertSame('FFDDEBF7', $fill('Y3'));
        $this->assertSame('FFFFD966', $fill('G4'));
        $this->assertSame('FFFFD966', $fill('AD4'));
        $this->assertSame('FFFFD966', $fill('A5'));
        $this->assertSame('FFFFD966', $fill('AG5'));
        foreach (['A3', 'Y4', 'A5'] as $cell) {
            $this->assertTrue($detail->getStyle($cell)->getFont()->getBold(), $cell);
            $this->assertSame('thin', $detail->getStyle($cell)->getBorders()->getTop()->getBorderStyle(), $cell);
        }
        $this->assertSame('D6', $detail->getFreezePane());
        $this->assertSame('A5:AG7', $detail->getAutoFilter()->getRange(), 'The filter covers the SID rows, not the TOTAL row.');
        $this->assertSame([3, 5], $detail->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertSame(PageSetup::PAPERSIZE_A3, $detail->getPageSetup()->getPaperSize());
        $this->assertSame('landscape', $detail->getPageSetup()->getOrientation());
        $book->disconnectWorksheets();
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

        // Sorted by service, category ("Semua kategori" first), bandwidth by speed, router.
        $this->assertEquals(['Semua kategori', 'TELKOM VPN IP', '1 Mbps', 'Dengan router', 1350000.0, 2000000.0, 0.0], array_slice($rows[4], 0, 7));
        $this->assertEquals(['KK', 'TELKOM VPN IP', '512 Kbps', 'Tanpa router', 2319819.0, 1981982.0, 0.0], array_slice($rows[5], 0, 7));
        $this->assertEquals(['KK', 'TELKOM VPN IP', '1 Mbps', 'Tanpa router', 1300000.0, 1981982.0, 0.0], array_slice($rows[6], 0, 7));
        $this->assertEquals(['KK', 'TELKOM VPN IP', '1 Mbps', 'Tanpa router', 1200000.0], array_slice($rows[7], 0, 5));
        $this->assertSame([null, null, '0012-ABC'], [$rows[4][7], $rows[5][7], $rows[7][7]]);
        $this->assertSame('01/01/2026', $harga->getCell('I4')->getFormattedValue());
        $this->assertSame('01/01/2020', $harga->getCell('I5')->getFormattedValue());
        $this->assertSame('#,##0', $harga->getStyle('E4')->getNumberFormat()->getFormatCode());
        $this->assertEmpty(array_filter(array_column($rows, 2), fn ($bandwidth) => $bandwidth === '2 Mbps'));
        $this->assertEmpty(array_filter(array_column($rows, 4), fn ($price) => in_array($price, [999001.0, 999002.0, 999003.0], true)));
        $book->disconnectWorksheets();
    }

    public function test_harga_follows_the_calculation_snapshot_and_falls_back_to_master_harga(): void
    {
        $revision = $this->issuedRevision();
        $snapshot = $revision->snapshot;
        $this->assertNotEmpty($snapshot['tariffs']);

        // Master Harga changes after the invoice was calculated: a reissued file still shows the calculated tariffs.
        PriceMaster::query()->update(['monthly_price' => '1']);
        $book = $this->reissue($snapshot);
        $this->assertEquals(2319819.0, $book->getSheetByName('Harga')->getCell('E5')->getValue());
        $this->assertSame(7, $book->getSheetByName('Harga')->getHighestRow());
        $book->disconnectWorksheets();

        // A snapshot that holds no tariff list reads Master Harga.
        unset($snapshot['tariffs']);
        $book = $this->reissue($snapshot);
        $this->assertEquals(1.0, $book->getSheetByName('Harga')->getCell('E5')->getValue());
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
