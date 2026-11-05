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
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDocumentGenerator;
use App\Services\Billing\InvoiceFinalizer;
use App\Services\Billing\InvoicePrintColumns;
use App\Services\Billing\InvoiceWordDocument;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Tests\TestCase;

/**
 * The invoice as a Word file: issued with every new version, created later for older versions, and always
 * showing the same cells as the PDF, both leaving out every column that has no data.
 */
class InvoiceWordDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const DRAFT = 'DRAFT - BELUM DIFINALISASI';

    private const SPECIAL = 'Biaya contoh usage agst Inv Sept';

    private const SPECIAL_SUMMARY = 'Biaya contoh';

    /** Calculated snapshot of the fixture, kept for the tests that only need data. */
    private static ?array $baseSnapshot = null;

    /** Temporary files and folders, removed after each test. */
    private array $cleanup = [];

    /** View locations before a broken view was put in front of them. */
    private ?array $viewPaths = null;

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            is_dir($path) ? File::deleteDirectory($path) : @unlink($path);
        }
        $this->restoreViews();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** September 2026 (usage August) with one service, "0012-ABC", 53,568 s of downtime; inputs are ready, nothing is calculated yet. */
    private function preparedPeriod(): InvoicePeriod
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'T', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $master = BankJatimLink::create(['ssid' => '0012-ABC', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
        $period = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);
        InvoiceLine::create(['invoice_period_id' => $period->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
        PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        $period->outages()->create(['sid' => $master->ssid, 'kind' => 'aggregate', 'duration_seconds' => 53568, 'review_status' => 'eligible', 'reason' => 'Test', 'coverage_start' => '2026-08-01 00:00:00', 'coverage_end' => '2026-09-01 00:00:00']);
        $period->markInputsChanged('test');

        return $period->fresh();
    }

    /** The locked version, with its snapshot, before any file is made (status "approved"). */
    private function finalizedRevision(): InvoiceRevision
    {
        $period = $this->preparedPeriod();
        app(InvoiceCalculator::class)->calculate($period->fresh());
        $revision = app(InvoiceFinalizer::class)->finalize($period->fresh(), $period->fresh()->input_version, auth()->id());

        return $revision->fresh();
    }

    private function generate(InvoiceRevision $revision): InvoiceRevision
    {
        (new GenerateInvoiceDocuments($revision->id))->handle(app(InvoiceDocumentGenerator::class));

        return $revision->fresh();
    }

    private function issuedRevision(): InvoiceRevision
    {
        return $this->generate($this->finalizedRevision());
    }

    private function folder(InvoiceRevision $revision): string
    {
        return 'billing/invoices/'.$revision->invoice_id.'/v'.$revision->version;
    }

    private function baseSnapshot(): array
    {
        return self::$baseSnapshot ??= $this->finalizedRevision()->snapshot;
    }

    /**
     * A snapshot of the fixture with one line per entry of $sets (each entry changes that line; the numbering, SID
     * and address are generic), and totals worked out from the lines again so the Word file passes its own check.
     */
    private function snapshot(?array $sets = null): array
    {
        $base = $this->baseSnapshot();
        $sets ??= [
            [],
            ['sla' => '1.00000000', 'sla_credit' => '0.00', 'charges' => ['existing' => '1234567.89']],
            ['sla' => '1.00000000', 'sla_credit' => '0.00', 'bandwidth' => '1 Mbps', 'charges' => ['existing' => '1000000.00', 'termination' => '250000.00']],
        ];
        $snapshot = $base;
        $snapshot['lines'] = [];
        foreach (array_values($sets) as $i => $set) {
            $snapshot['lines'][] = $this->lineOf($base['lines'][0], $i + 1, $set);
        }

        return $this->retotal($snapshot);
    }

    private function lineOf(array $base, int $number, array $set): array
    {
        $line = array_replace_recursive($base, ['number' => $number, 'sid' => sprintf('0012-%03d', $number), 'address' => 'Jl. Contoh No. '.$number], $set);
        // As in the calculator, the gross includes the special columns; the charges do not.
        $gross = BigDecimal::zero();
        foreach ($line['special'] ?? [] as $amount) {
            $gross = $gross->plus($amount);
        }
        foreach ($line['charges'] as $charge) {
            $gross = $gross->plus($charge);
        }
        $line['gross'] = (string) $gross->toScale(2);
        $line['net'] = (string) $gross->minus($line['sla_credit'])->minus($line['manual_credit'])->toScale(2);

        return $line;
    }

    private function retotal(array $snapshot): array
    {
        $sum = fn (array $values): BigDecimal => array_reduce($values, fn (BigDecimal $carry, $value) => $carry->plus($value), BigDecimal::zero());
        $money = fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HalfUp);
        $lines = $snapshot['lines'];
        foreach (array_keys($snapshot['totals']['components']) as $component) {
            $snapshot['totals']['components'][$component] = $money($sum(array_column(array_column($lines, 'charges'), $component)));
        }
        $special = [];
        foreach ($lines as $line) {
            foreach ($line['special'] ?? [] as $name => $amount) {
                $special[$name] = ($special[$name] ?? BigDecimal::zero())->plus($amount);
            }
        }
        $net = $sum(array_column($lines, 'net'));
        $subtotal = $net->toScale(0, RoundingMode::HalfUp);
        $tax = $subtotal->multipliedBy('0.11')->toScale(0, RoundingMode::HalfUp);
        $snapshot['totals'] = array_merge($snapshot['totals'], [
            'service_count' => count($lines),
            'special_columns' => array_map(fn (string $name) => ['name' => $name, 'summary' => $name === self::SPECIAL ? self::SPECIAL_SUMMARY : $name, 'amount' => $money($special[$name])], array_keys($special)),
            'special_total' => $money($sum(array_values($special))),
            'gross' => $money($sum(array_column($lines, 'gross'))),
            'sla_credit' => $money($sum(array_column($lines, 'sla_credit'))),
            'manual_credit' => $money($sum(array_column($lines, 'manual_credit'))),
            'net_exact' => $money($net),
            'rounding' => $money($subtotal->minus($net)),
            'subtotal' => (string) $subtotal,
            'tax' => (string) $tax,
            'grand_total' => $money($subtotal->plus($tax)),
        ]);

        return $snapshot;
    }

    // ---------------------------------------------------------------------------------------------
    // Reading the documents
    // ---------------------------------------------------------------------------------------------

    /** Writes the Word file of a snapshot to a temporary path (the renderer validates it as well). */
    private function renderWord(array $snapshot, int $version = 1): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'word-'.bin2hex(random_bytes(6)).'.docx';
        $this->cleanup[] = $path;
        (new InvoiceWordDocument)->render($snapshot, $version, $path);

        return $path;
    }

    /** Text as compared between the PDF and the Word file: control characters dropped, white space collapsed. */
    private function normalize(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    private function texts(\DOMNodeList $nodes): array
    {
        return array_map(fn (\DOMNode $node) => $this->normalize($node->textContent), iterator_to_array($nodes));
    }

    private function docxXPath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', self::W);

        return $xpath;
    }

    /** A part of the package, which must be well-formed XML. */
    private function docxPart(string $path, string $name): \DOMDocument
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The Word file opens as a zip package.');
        $xml = $zip->getFromName($name);
        $zip->close();
        $this->assertIsString($xml, 'The package has '.$name);

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $errors = array_map(fn (\LibXMLError $error) => trim($error->message).' (line '.$error->line.')', libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded, $name.' is well-formed XML: '.implode('; ', $errors));

        return $document;
    }

    /** @return list<string> names of all entries of the package */
    private function docxEntries(string $path): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    /** All text of the body, run after run. */
    private function docxText(string $path): string
    {
        return $this->docxXPath($this->docxPart($path, 'word/document.xml'))->evaluate('string(/w:document/w:body)');
    }

    /** The HTML of the same view Dompdf prints. */
    private function pdfXPath(array $snapshot, int $version): \DOMXPath
    {
        $html = view('billing.invoice-pdf', ['snapshot' => $snapshot, 'version' => $version])->render();
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /**
     * What the PDF's header markup says about the grid: how many columns the first header row spans, how many the
     * header cells account for (a cell spanning both rows counts once, with the second row's cells), the percentage
     * widths of the leaf columns, and how many colspan headers carry a width of their own.
     *
     * @return array{columns: int, covered: int, widths: list<float>, spanWidths: int, address: float}
     */
    private function pdfGrid(array $snapshot, int $version = 1): array
    {
        $xpath = $this->pdfXPath($snapshot, $version);
        $columns = 0;
        $widths = [];
        $spanWidths = 0;
        $address = 0.0;
        $addWidth = function (\DOMElement $cell) use (&$widths, &$address): void {
            if (preg_match('/width:([\d.]+)%/', $cell->getAttribute('style'), $match)) {
                $widths[] = (float) $match[1];
                if (trim($cell->textContent) === 'ALAMAT') {
                    $address = (float) $match[1];
                }
            }
        };
        foreach ($xpath->query("//table[@class='lines']/thead/tr[1]/th") as $cell) {
            $span = max(1, (int) $cell->getAttribute('colspan'));
            $columns += $span;
            if ($span > 1) {
                // A percentage width on a colspan header made Dompdf hang, so none may carry one.
                $spanWidths += (int) str_contains($cell->getAttribute('style'), 'width');
            } else {
                $addWidth($cell);
            }
        }
        $second = $xpath->query("//table[@class='lines']/thead/tr[2]/th");
        foreach ($second as $cell) {
            $addWidth($cell);
        }
        // Without sub-columns there is no second row (and nothing spans two rows), so every first-row cell is a column.
        $single = $xpath->query("//table[@class='lines']/thead/tr[1]/th".($second->length ? "[@rowspan='2']" : ''));

        return ['columns' => $columns, 'covered' => $second->length + $single->length, 'widths' => $widths, 'spanWidths' => $spanWidths, 'address' => $address];
    }

    /**
     * The tables of the PDF (rendered from the HTML of the same view Dompdf prints): header cells by row, the cells
     * of every service row, the cells of the summary one after the other, and the text of the page identity.
     */
    private function pdfLayout(array $snapshot, int $version): array
    {
        $xpath = $this->pdfXPath($snapshot, $version);

        $rows = [];
        foreach ($xpath->query("//table[@class='lines']/tbody/tr") as $row) {
            $rows[] = $this->texts($xpath->query('./td', $row));
        }

        return [
            'headers' => [$this->texts($xpath->query("//table[@class='lines']/thead/tr[1]/th")), $this->texts($xpath->query("//table[@class='lines']/thead/tr[2]/th"))],
            'rows' => $rows,
            'summary' => $this->texts($xpath->query("//table[@class='summary']//tr/td")),
            'identity' => $this->normalize($xpath->evaluate("string(//div[@class='page-identity'])")),
        ];
    }

    /**
     * The tables of the Word file in the same shape as pdfLayout(), plus the grid. The body has the borderless
     * meta layout, the lines table (2 header rows) and the summary table, in that order.
     */
    private function docxLayout(string $path): array
    {
        $xpath = $this->docxXPath($this->docxPart($path, 'word/document.xml'));
        $tables = $xpath->query('/w:document/w:body/w:tbl');
        $this->assertSame(3, $tables->length, 'Meta layout, lines table and summary table.');
        $lines = $tables->item(1);

        $grid = [];
        foreach ($xpath->query('./w:tblGrid/w:gridCol', $lines) as $column) {
            $grid[] = (int) $column->getAttributeNS(self::W, 'w');
        }
        $rows = $xpath->query('./w:tr', $lines);
        $spans = [];
        $misfits = [];
        $restarts = [];
        $continues = [];
        foreach ($rows as $r => $row) {
            $span = 0;
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $columns = $xpath->evaluate('string(./w:tcPr/w:gridSpan/@w:val)', $cell);
                $count = $columns === '' ? 1 : (int) $columns;
                // A cell is as wide as the grid columns it spans.
                if ((int) $xpath->evaluate('string(./w:tcPr/w:tcW/@w:w)', $cell) !== array_sum(array_slice($grid, $span, $count))) {
                    $misfits[] = [$r, $span];
                }
                if ($xpath->evaluate('count(./w:tcPr/w:vMerge[@w:val="restart"])', $cell) > 0) {
                    $restarts[$r][] = $span;
                } elseif ($xpath->evaluate('count(./w:tcPr/w:vMerge)', $cell) > 0) {
                    $continues[$r][] = $span;
                }
                $span += $count;
            }
            $spans[] = $span;
        }
        $data = [];
        for ($i = 2; $i < $rows->length; $i++) {
            $data[] = $this->texts($xpath->query('./w:tc', $rows->item($i)));
        }
        $footer = $this->docxXPath($this->docxPart($path, 'word/footer1.xml'));

        return [
            'grid' => $grid,
            'spans' => $spans,
            'misfits' => $misfits,
            // The cells that start a vertical merge in the first header row and the ones that continue it in the second.
            'merges' => [$restarts[0] ?? [], $continues[1] ?? []],
            // The second header row continues the merged cells of the first one; those carry no text of their own.
            'headers' => [$this->texts($xpath->query('./w:tc', $rows->item(0))), $this->texts($xpath->query('./w:tc[not(w:tcPr/w:vMerge[not(@w:val)])]', $rows->item(1)))],
            'rows' => $data,
            'summary' => $this->texts($xpath->query('./w:tr/w:tc', $tables->item(2))),
            'identity' => $this->normalize($footer->evaluate('string(/w:ftr/w:p/w:r[1]/w:t)')),
        ];
    }

    /**
     * Renders the Word file of a snapshot (or takes an existing one) and requires every header, data and summary
     * cell, in order, to equal the PDF's. Returns both layouts.
     *
     * @return array{pdf: array, docx: array}
     */
    private function assertSameAsPdf(array $snapshot, ?string $path = null, int $version = 1): array
    {
        $path ??= $this->renderWord($snapshot, $version);
        $pdf = $this->pdfLayout($snapshot, $version);
        $docx = $this->docxLayout($path);

        $this->assertNotEmpty($pdf['rows'], 'The comparison needs at least one service row.');
        $this->assertSame($pdf['headers'], $docx['headers'], 'Header cells differ from the PDF.');
        $this->assertCount(count($snapshot['lines']), $docx['rows'], 'One row per service.');
        $this->assertSame($pdf['rows'], $docx['rows'], 'Service cells differ from the PDF.');
        $this->assertSame($pdf['summary'], $docx['summary'], 'Summary cells differ from the PDF.');
        $this->assertSame($pdf['identity'], $docx['identity'], 'Page identity differs from the PDF.');

        // The grid: the columns add up to the text width and every row of the table fills it exactly.
        $columns = count($docx['grid']);
        $this->assertSame(20400, array_sum($docx['grid']));
        $this->assertSame(array_fill(0, count($docx['spans']), $columns), $docx['spans'], 'Every row of the lines table fills the grid.');
        foreach ($docx['rows'] as $row) {
            $this->assertCount($columns, $row);
        }
        $this->assertSame([], $docx['misfits'], 'Every cell is as wide as the grid columns it spans.');
        $this->assertSame($docx['merges'][0], $docx['merges'][1], 'Each merged header cell continues in the second header row, in the same column.');

        // The PDF: the header cells account for every column once, the rows have as many cells, the percentages add up
        // to 100 and no colspan header has a width.
        $markup = $this->pdfGrid($snapshot, $version);
        $this->assertSame($columns, $markup['columns']);
        $this->assertSame($columns, $markup['covered']);
        foreach ($pdf['rows'] as $row) {
            $this->assertCount($columns, $row);
        }
        $this->assertEqualsWithDelta(100, array_sum($markup['widths']), 0.001);
        $this->assertSame(0, $markup['spanWidths']);

        return ['pdf' => $pdf, 'docx' => $docx];
    }

    /** Summary rows as label => value. */
    private function summaryOf(array $layout): array
    {
        $pairs = array_chunk($layout['summary'], 2);

        return array_combine(array_column($pairs, 0), array_column($pairs, 1));
    }

    /** A line with only an existing charge, no credit and full availability, to which a test adds the one thing it looks at. */
    private function plain(array $with = []): array
    {
        return array_replace_recursive(['sla' => '1.00000000', 'sla_credit' => '0.00', 'charges' => ['existing' => '2000000.00']], $with);
    }

    /** A line that shows data in every optional column: bandwidth change, credit, a special column and every charge. */
    private function full(): array
    {
        return ['bandwidth_change' => '2 Mbps', 'sla' => '0.99000000', 'sla_credit' => '1000.00', 'special' => [self::SPECIAL => '70000.00'],
            'charges' => ['existing' => '2000000.00', 'upgrade' => '125000.00', 'termination' => '250000.00', 'installation' => '500000.00', 'relocation' => '300000.00']];
    }

    /** A line whose optional columns would hold only blanks, dashes or zeros. */
    private function bare(array $with = []): array
    {
        return array_replace_recursive(['branch' => '-', 'category' => '', 'service' => '—', 'bandwidth' => '-', 'bandwidth_change' => null, 'sla' => null, 'sla_credit' => '0.00',
            'charges' => array_fill_keys(['existing', 'upgrade', 'downgrade', 'termination', 'installation', 'relocation'], '0.00')], $with);
    }

    /** Every header text of both header rows. */
    private function headerTexts(array $layout): array
    {
        return array_merge(...$layout['headers']);
    }

    /** Position of a column among the cells of a printed row (the print order is not the order of InvoicePrintColumns). */
    private function columnIndex(array $snapshot, string $key): int
    {
        $special = array_map(fn (int $i) => 'special_'.$i, array_keys(InvoicePrintColumns::special($snapshot)));
        $order = ['no', 'branch', 'address', 'category', 'service', 'sid', 'bandwidth', 'bandwidth_change', 'sla', 'credit', 'existing', ...$special, 'change', 'termination', 'installation', 'relocation', 'gross', 'net'];
        $visible = InvoicePrintColumns::visible($snapshot) + array_fill_keys($special, true);
        $index = array_search($key, array_values(array_filter($order, fn (string $column) => $visible[$column])), true);
        $this->assertNotFalse($index, $key.' is printed.');

        return $index;
    }

    // ---------------------------------------------------------------------------------------------
    // Broken view, log and checks
    // ---------------------------------------------------------------------------------------------

    /** Puts a view whose XML is not well-formed in front of billing.invoice-docx. */
    private function installBrokenWordView(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'broken-views-'.bin2hex(random_bytes(4));
        mkdir($directory.DIRECTORY_SEPARATOR.'billing', 0777, true);
        $file = $directory.DIRECTORY_SEPARATOR.'billing'.DIRECTORY_SEPARATOR.'invoice-docx.blade.php';
        file_put_contents($file, '<w:document>');
        $this->cleanup[] = $directory;
        $this->cleanup[] = app('blade.compiler')->getCompiledPath($file);

        $this->viewPaths ??= View::getFinder()->getPaths();
        View::getFinder()->prependLocation($directory);
        View::getFinder()->flush();
    }

    /** Back to the application's own views (and deletes the broken one). */
    private function restoreViews(): void
    {
        if ($this->viewPaths === null) {
            return;
        }
        View::getFinder()->setPaths($this->viewPaths);
        View::getFinder()->flush();
        $this->viewPaths = null;
    }

    /** Sends the log to a handler that only remembers, so a test can read what was logged without writing storage/logs. */
    private function captureLog(): TestHandler
    {
        config(['logging.default' => 'word-test', 'logging.channels.word-test' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

        return Log::channel('word-test')->getLogger()->getHandlers()[0];
    }

    /** @return list<string> */
    private function warnings(TestHandler $log): array
    {
        return array_values(array_map(fn ($record) => $record->message, array_filter($log->getRecords(), fn ($record) => $record->level === Level::Warning)));
    }

    private function assertRejected(callable $check, string $message): void
    {
        try {
            $check();
        } catch (\RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail('Expected a RuntimeException: '.$message);
    }

    /** A zip package holding only the given entries, for the checks of files that are not Word files. */
    private function zipWith(array $entries): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'word-'.bin2hex(random_bytes(6)).'.docx';
        $this->cleanup[] = $path;
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    // ---------------------------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------------------------

    public function test_new_versions_get_the_word_file_as_a_download_and_it_is_shown_on_the_publish_page(): void
    {
        $revision = $this->issuedRevision();
        $this->assertSame('ready', $revision->status, (string) $revision->generation_error);
        $disk = Storage::disk('local');
        $this->assertSame(1, $revision->outputs()->where('output_type', 'docx_cetak')->count());
        $docx = $revision->outputs()->where('output_type', 'docx_cetak')->sole();
        $this->assertSame('Invoice_SEP26_v1.docx', $docx->file_name);
        $this->assertSame($this->folder($revision).'/invoice.docx', $docx->file_path);
        $this->assertSame('final', $docx->status);
        $this->assertSame(hash_file('sha256', $disk->path($docx->file_path)), $docx->file_hash);
        $this->assertSame($disk->size($docx->file_path), $docx->file_size);
        $this->assertSame(['docx_cetak', 'excel_invoice', 'pdf_cetak', 'summary_png'], $revision->outputs()->orderBy('output_type')->pluck('output_type')->all());

        $response = $this->get(route('billing.download', $docx))->assertOk();
        $this->assertStringContainsString('Invoice_SEP26_v1.docx', $response->headers->get('content-disposition'));
        $content = $response->streamedContent();
        $this->assertSame('PK', substr($content, 0, 2), 'A Word file is a zip package.');
        $this->assertSame(file_get_contents($disk->path($docx->file_path)), $content);

        $this->get(route('invoice-periods.step3', $revision->invoicePeriod))->assertOk()
            ->assertSee('Unduh Word')->assertSee('Unduh Excel')->assertSee('Unduh PDF')->assertDontSee('Buat file Word');
    }

    public function test_the_package_holds_the_parts_word_needs(): void
    {
        $path = $this->renderWord($this->snapshot());
        $entries = $this->docxEntries($path);
        $this->assertEqualsCanonicalizing([
            '[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'word/document.xml', 'word/_rels/document.xml.rels', 'word/styles.xml',
            'word/settings.xml', 'word/header1.xml', 'word/_rels/header1.xml.rels', 'word/footer1.xml', 'word/media/logo.png',
        ], $entries);
        $zip = new \ZipArchive;
        $zip->open($path);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($zip->getFromName('word/media/logo.png'))[2], 'The logo is a PNG.');
        $zip->close();

        $namespaces = ['pr' => 'http://schemas.openxmlformats.org/package/2006/relationships', 'ct' => 'http://schemas.openxmlformats.org/package/2006/content-types'];
        $xpath = function (string $part, array $extra = []) use ($path, $namespaces): \DOMXPath {
            $xpath = $this->docxXPath($this->docxPart($path, $part));
            foreach ($namespaces + $extra as $prefix => $uri) {
                $xpath->registerNamespace($prefix, $uri);
            }

            return $xpath;
        };

        // Every XML part is well-formed and every relationship leads to a part that exists.
        foreach (['_rels/.rels' => '', 'word/_rels/document.xml.rels' => 'word/', 'word/_rels/header1.xml.rels' => 'word/'] as $rels => $base) {
            $ids = [];
            foreach ($xpath($rels)->query('/pr:Relationships/pr:Relationship') as $relationship) {
                $this->assertContains($base.$relationship->getAttribute('Target'), $entries, $rels.' points to a missing part.');
                $ids[] = $relationship->getAttribute('Id');
            }
            $this->assertSame(array_unique($ids), $ids, 'Relationship ids are unique.');
        }
        $documentRelationships = $xpath('word/_rels/document.xml.rels')->query('/pr:Relationships/pr:Relationship/@Id');
        $relationshipIds = array_map(fn ($id) => $id->value, iterator_to_array($documentRelationships));
        $document = $xpath('word/document.xml', ['r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships']);
        foreach ($document->query('//w:sectPr/*/@r:id') as $reference) {
            $this->assertContains($reference->value, $relationshipIds, 'The page setup refers to a relationship that exists.');
        }
        $header = $xpath('word/header1.xml', ['a' => 'http://schemas.openxmlformats.org/drawingml/2006/main', 'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships']);
        $this->assertSame('rId1', $header->evaluate('string(//a:blip/@r:embed)'));
        $this->assertSame('media/logo.png', $xpath('word/_rels/header1.xml.rels')->evaluate('string(/pr:Relationships/pr:Relationship[@Id="rId1"]/@Target)'));

        // Every part has a content type, and every override names a part that exists.
        $types = $xpath('[Content_Types].xml');
        $defaults = array_map(fn ($node) => $node->value, iterator_to_array($types->query('/ct:Types/ct:Default/@Extension')));
        $overrides = array_map(fn ($node) => ltrim($node->value, '/'), iterator_to_array($types->query('/ct:Types/ct:Override/@PartName')));
        foreach ($entries as $entry) {
            $this->assertTrue(in_array($entry, $overrides, true) || in_array(pathinfo($entry, PATHINFO_EXTENSION), $defaults, true), $entry.' has no content type.');
        }
        foreach ($overrides as $override) {
            $this->assertContains($override, $entries);
        }

        // Every paragraph style the body uses is defined.
        $defined = array_map(fn ($node) => $node->value, iterator_to_array($xpath('word/styles.xml')->query('//w:style/@w:styleId')));
        foreach ($document->query('//w:pStyle/@w:val') as $style) {
            $this->assertContains($style->value, $defined, 'Paragraph style '.$style->value.' is not defined.');
        }
    }

    public function test_the_word_file_has_the_same_cells_as_the_pdf(): void
    {
        $snapshot = $this->snapshot();
        $layouts = $this->assertSameAsPdf($snapshot);

        $this->assertCount(13, $layouts['docx']['grid'], 'Without PSB, relocation or upgrades those columns are left out.');
        $this->assertSame(['NO.', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'LAYANAN', 'ID', 'BANDWIDTH', 'Availability', 'Nilai Restitusi', 'HARGA (Rp)', 'TOTAL', 'HARGA SETELAH RESTITUSI'], $layouts['docx']['headers'][0]);
        $this->assertSame(['Eksisting', 'Restitusi Over SLA', 'Eksisting', 'Cabut (Prorata)'], $layouts['docx']['headers'][1]);
        $this->assertNotContains('Total Biaya Upgrade / Downgrade', array_keys($this->summaryOf($layouts['docx'])));

        // A few absolute values, so the comparison cannot pass by both sides being empty or equally wrong.
        $this->assertSame(['1', 'Cabang Test', 'Jl. Contoh No. 1', 'KK', 'TELKOM VPN IP', '0012-001', '512 Kbps', '98.00%', 'Rp23,198.19', '2,319,819', '', '2,319,819', '2,296,620.81'], $layouts['docx']['rows'][0]);
        $this->assertSame(['3', 'Cabang Test', 'Jl. Contoh No. 3', 'KK', 'TELKOM VPN IP', '0012-003', '1 Mbps', '100.00%', 'Rp0', '1,000,000', '250,000', '1,250,000', '1,250,000'], $layouts['docx']['rows'][2]);
    }

    public function test_the_word_file_has_the_same_cells_as_the_pdf_when_services_are_upgraded_or_downgraded(): void
    {
        // Rendered from a consistent snapshot, so the Word file's own check passes as it does for an issued invoice.
        $snapshot = $this->snapshot([
            [],
            ['events' => [['event_type' => 'upgrade', 'effective_date' => '2026-08-15']], 'bandwidth_change' => '2 Mbps', 'sla_credit' => '0.00', 'charges' => ['existing' => '1000000.00', 'upgrade' => '125000.00']],
            ['events' => [['event_type' => 'downgrade', 'effective_date' => '2026-08-20']], 'bandwidth_change' => '256 Kbps', 'sla_credit' => '0.00', 'charges' => ['existing' => '500000.00', 'downgrade' => '45000.00']],
        ]);
        $layouts = $this->assertSameAsPdf($snapshot);
        $docx = $layouts['docx'];

        $this->assertCount(14, $docx['grid'], 'The bandwidth and the price each get an Upgrade / Downgrade column.');
        $this->assertSame(['NO.', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'LAYANAN', 'ID', 'BANDWIDTH', 'Availability', 'Nilai Restitusi', 'HARGA (Rp)', 'TOTAL', 'HARGA SETELAH RESTITUSI'], $docx['headers'][0]);
        $this->assertSame(['Eksisting', 'Upgrade / Downgrade', 'Restitusi Over SLA', 'Eksisting', 'Upgrade / Downgrade'], $docx['headers'][1]);
        $this->assertSame(['', '2 Mbps', '256 Kbps'], array_column($docx['rows'], 7), 'The new bandwidth.');
        $this->assertSame(['', '125,000', '45,000'], array_column($docx['rows'], 11), 'Upgrade and downgrade share one price column.');
        $this->assertSame('170,000', $this->summaryOf($docx)['Total Biaya Upgrade / Downgrade']);
        $this->assertSame(array_keys($this->summaryOf($docx)), array_keys($this->summaryOf($layouts['pdf'])));
        $this->assertContains('Total Biaya Upgrade / Downgrade', array_keys($this->summaryOf($layouts['pdf'])));

        // The header cells span the same grid columns as the cells below them: BANDWIDTH 2, HARGA (Rp) 2.
        $xpath = $this->docxXPath($this->docxPart($this->renderWord($snapshot), 'word/document.xml'));
        $this->assertSame(['2', '2'], array_map(fn ($node) => $node->value, iterator_to_array($xpath->query('/w:document/w:body/w:tbl[2]/w:tr[1]/w:tc/w:tcPr/w:gridSpan/@w:val'))));
    }

    public function test_a_month_without_psb_relocation_cabut_upgrade_or_restitution_has_none_of_those_columns(): void
    {
        $layouts = $this->assertSameAsPdf($this->snapshot([$this->plain(), $this->plain(), $this->plain()]));
        $gone = ['PSB', 'Relokasi', 'Cabut (Prorata)', 'Upgrade / Downgrade', 'Nilai Restitusi', 'Restitusi Over SLA', self::SPECIAL];

        $this->assertCount(11, $layouts['docx']['grid']);
        foreach (['pdf', 'docx'] as $kind) {
            $this->assertSame([['NO.', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'LAYANAN', 'ID', 'BANDWIDTH', 'Availability', 'HARGA (Rp)', 'TOTAL', 'HARGA SETELAH RESTITUSI'], ['Eksisting', 'Eksisting']], $layouts[$kind]['headers'], $kind);
            foreach ($gone as $header) {
                $this->assertNotContains($header, $this->headerTexts($layouts[$kind]), $kind.' still has '.$header);
            }
            $this->assertSame(['1', 'Cabang Test', 'Jl. Contoh No. 1', 'KK', 'TELKOM VPN IP', '0012-001', '512 Kbps', '100.00%', '2,000,000', '2,000,000', '2,000,000'], $layouts[$kind]['rows'][0], $kind);

            // Only the columns follow the new rule: PSB and Pemutusan stay in the summary at zero.
            $summary = $this->summaryOf($layouts[$kind]);
            $this->assertSame(['Total Biaya Layanan Eksisting', 'Total Biaya Pasang Baru Jaringan (PSB)', 'Total Biaya Pemutusan Jaringan', 'Total Seluruh Tagihan', 'PPN', 'Grand Total Invoice'], array_keys($summary), $kind);
            $this->assertSame(['0', '0'], [$summary['Total Biaya Pasang Baru Jaringan (PSB)'], $summary['Total Biaya Pemutusan Jaringan']], $kind);
        }
    }

    public function test_a_column_appears_as_soon_as_one_line_has_data(): void
    {
        $base = ['Eksisting', 'Eksisting'];
        $withCredit = ['Eksisting', 'Restitusi Over SLA', 'Eksisting'];
        $cases = [
            // name => [what the middle line gets, second header row, column, cell of the other lines, cell of the middle line, summary row]
            'PSB' => [['charges' => ['installation' => '500000.00']], [...$base, 'PSB'], 'installation', '', '500,000', null],
            'Cabut' => [['charges' => ['termination' => '250000.00']], [...$base, 'Cabut (Prorata)'], 'termination', '', '250,000', null],
            'Relokasi' => [['charges' => ['relocation' => '300000.00']], [...$base, 'Relokasi'], 'relocation', '', '300,000', ['Total Biaya Relokasi', '300,000']],
            'restitution from the SLA' => [['sla' => '0.99000000', 'sla_credit' => '5000.00'], $withCredit, 'credit', 'Rp0', 'Rp5,000', ['Total Restitusi', '-5,000']],
            'manual restitution' => [['manual_credit' => '5000.00'], $withCredit, 'credit', 'Rp0', 'Rp5,000', ['Total Restitusi', '-5,000']],
            'Upgrade' => [['charges' => ['upgrade' => '125000.00']], [...$base, 'Upgrade / Downgrade'], 'change', '', '125,000', ['Total Biaya Upgrade / Downgrade', '125,000']],
            'Downgrade' => [['charges' => ['downgrade' => '45000.00']], [...$base, 'Upgrade / Downgrade'], 'change', '', '45,000', ['Total Biaya Upgrade / Downgrade', '45,000']],
            'new bandwidth' => [['bandwidth_change' => '2 Mbps'], ['Eksisting', 'Upgrade / Downgrade', 'Eksisting'], 'bandwidth_change', '', '2 Mbps', null],
        ];
        foreach ($cases as $name => [$set, $secondRow, $key, $blank, $cell, $row]) {
            $snapshot = $this->snapshot([$this->plain(), $this->plain($set), $this->plain()]);
            $layouts = $this->assertSameAsPdf($snapshot);
            // The 11 columns of a plain month plus the one that has data now.
            $this->assertCount(12, $layouts['docx']['grid'], $name);
            $column = $this->columnIndex($snapshot, $key);
            foreach (['pdf', 'docx'] as $kind) {
                $this->assertSame($secondRow, $layouts[$kind]['headers'][1], $name.' ('.$kind.')');
                $this->assertSame([$blank, $cell, $blank], array_column($layouts[$kind]['rows'], $column), $name.' ('.$kind.')');
                if ($row !== null) {
                    $this->assertSame($row[1], $this->summaryOf($layouts[$kind])[$row[0]], $name.' ('.$kind.')');
                }
            }
        }
    }

    public function test_header_and_body_cells_match_the_grid_for_every_combination_of_columns(): void
    {
        $combinations = [
            // name => [snapshot, columns, ALAMAT in twips, ALAMAT in percent]
            'every column' => [$this->snapshot([$this->plain(), $this->full()]), 18, 1920, 11.8],
            'the usual month' => [$this->snapshot(), 13, 6870, 37.2],
            'nothing but the always-present columns' => [$this->snapshot([$this->bare(), $this->bare()]), 5, 15800, 78.6],
            'a credit and a PSB' => [$this->snapshot([$this->bare(), $this->bare(['sla' => '0.90000000', 'sla_credit' => '12.00', 'charges' => ['installation' => '1000.00']])]), 8, 12950, 65.8],
            'a new bandwidth and a relocation' => [$this->snapshot([$this->bare(), $this->bare(['bandwidth_change' => '4 Mbps', 'charges' => ['relocation' => '300000.00']])]), 7, 14000, 70.4],
        ];
        foreach ($combinations as $name => [$snapshot, $columns, $twips, $percent]) {
            $docx = $this->assertSameAsPdf($snapshot)['docx'];
            $this->assertCount($columns, $docx['grid'], $name);
            $this->assertSame(20400, array_sum($docx['grid']), $name);
            $this->assertSame($twips, $docx['grid'][array_search('ALAMAT', $docx['headers'][0], true)], $name.': ALAMAT takes what the others leave.');
            $this->assertEqualsWithDelta($percent, $this->pdfGrid($snapshot)['address'], 0.001, $name);
            foreach ($docx['rows'] as $row) {
                $this->assertCount($columns, $row, $name);
            }
        }
    }

    public function test_the_group_headers_follow_their_visible_sub_columns(): void
    {
        // No bandwidth and no price column: both group headers are gone, and the second header row has no text.
        $snapshot = $this->snapshot([$this->bare(), $this->bare()]);
        $layouts = $this->assertSameAsPdf($snapshot);
        foreach (['pdf', 'docx'] as $kind) {
            $this->assertSame([['NO.', 'ALAMAT', 'ID', 'TOTAL', 'HARGA SETELAH RESTITUSI'], []], $layouts[$kind]['headers'], $kind);
            $this->assertSame(['1', 'Jl. Contoh No. 1', '0012-001', '0', '0'], $layouts[$kind]['rows'][0], $kind);
        }
        $xpath = $this->docxXPath($this->docxPart($this->renderWord($snapshot), 'word/document.xml'));
        $this->assertSame(0, $xpath->query('/w:document/w:body/w:tbl[2]/w:tr[1]/w:tc/w:tcPr/w:gridSpan')->length, 'No spanning header is left.');

        // One of the two bandwidth columns and three of the price columns: the spans follow.
        $snapshot = $this->snapshot([$this->bare(), $this->bare(['bandwidth_change' => '4 Mbps', 'charges' => ['existing' => '1000000.00', 'installation' => '500000.00', 'relocation' => '300000.00']])]);
        $docx = $this->assertSameAsPdf($snapshot)['docx'];
        $this->assertSame(['Upgrade / Downgrade', 'Eksisting', 'PSB', 'Relokasi'], $docx['headers'][1]);
        $xpath = $this->docxXPath($this->docxPart($this->renderWord($snapshot), 'word/document.xml'));
        $this->assertSame(['3'], array_map(fn ($node) => $node->value, iterator_to_array($xpath->query('/w:document/w:body/w:tbl[2]/w:tr[1]/w:tc/w:tcPr/w:gridSpan/@w:val'))), 'HARGA (Rp) spans its three columns; BANDWIDTH has one and no span.');
        $pdf = $this->pdfXPath($snapshot, 1);
        $this->assertSame(['1', '3'], [
            $pdf->evaluate("string(//table[@class='lines']/thead/tr[1]/th[. = 'BANDWIDTH']/@colspan)"),
            $pdf->evaluate("string(//table[@class='lines']/thead/tr[1]/th[. = 'HARGA (Rp)']/@colspan)"),
        ]);
    }

    public function test_a_special_column_has_a_column_and_a_summary_row_only_when_the_invoice_has_it(): void
    {
        $label = self::SPECIAL;
        $total = 'Total '.self::SPECIAL_SUMMARY;

        // None: neither the column nor the row.
        $none = $this->snapshot([$this->plain(), $this->plain()]);
        $this->assertSame([], $none['totals']['special_columns']);
        $layouts = $this->assertSameAsPdf($none);
        foreach (['pdf', 'docx'] as $kind) {
            $this->assertNotContains($label, $this->headerTexts($layouts[$kind]), $kind);
            $this->assertArrayNotHasKey($total, $this->summaryOf($layouts[$kind]), $kind);
        }

        // One line has it, next to a relocation, a restitution and an upgrade.
        $snapshot = $this->snapshot([
            $this->plain(['special' => [self::SPECIAL => '70000.00']]),
            $this->plain(['charges' => ['upgrade' => '125000.00']]),
            $this->plain(['sla_credit' => '1000.00', 'charges' => ['relocation' => '300000.00']]),
        ]);
        $this->assertSame('70000.00', $snapshot['totals']['special_total']);
        $layouts = $this->assertSameAsPdf($snapshot);
        foreach (['pdf', 'docx'] as $kind) {
            // After HARGA Eksisting and before Upgrade / Downgrade.
            $this->assertSame(['Eksisting', 'Restitusi Over SLA', 'Eksisting', $label, 'Upgrade / Downgrade', 'Relokasi'], $layouts[$kind]['headers'][1], $kind);
            $this->assertSame(['70,000', '', ''], array_column($layouts[$kind]['rows'], $this->columnIndex($snapshot, 'special_0')), $kind);
            $this->assertSame(['2,070,000', '2,125,000', '2,300,000'], array_column($layouts[$kind]['rows'], $this->columnIndex($snapshot, 'gross')), $kind.': TOTAL includes the adjustment.');

            $summary = $this->summaryOf($layouts[$kind]);
            $keys = array_keys($summary);
            $this->assertSame(['Total Biaya Relokasi', $total, 'Total Restitusi'], array_slice($keys, array_search('Total Biaya Relokasi', $keys, true), 3), $kind.': after Relokasi, before Total Restitusi.');
            $this->assertSame('70,000', $summary[$total], $kind);

            // The summary adds up: the components and the adjustment, less the restitution, are the total of the invoice and of the net column.
            $number = fn (string $name): float => (float) str_replace(',', '', $summary[$name]);
            $components = $number('Total Biaya Layanan Eksisting') + $number('Total Biaya Upgrade / Downgrade') + $number('Total Biaya Pasang Baru Jaringan (PSB)')
                + $number('Total Biaya Pemutusan Jaringan') + $number('Total Biaya Relokasi') + $number($total);
            $this->assertEqualsWithDelta($number('Total Seluruh Tagihan'), $components + $number('Total Restitusi'), 0.001, $kind);
            $net = array_map(fn (string $cell) => (float) str_replace(',', '', $cell), array_column($layouts[$kind]['rows'], $this->columnIndex($snapshot, 'net')));
            $this->assertEqualsWithDelta($number('Total Seluruh Tagihan'), array_sum($net), 0.001, $kind);
        }
        $this->assertSame('6,494,000', $this->summaryOf($layouts['docx'])['Total Seluruh Tagihan']);

        // The header looks like the other yellow sub-headers; in Word it is 1300 wide with a small font.
        $pdf = $this->pdfXPath($snapshot, 1);
        $this->assertSame(1, $pdf->query("//table[@class='lines']/thead/tr[2]/th[@class='hl'][normalize-space(.) = '".$label."']")->length);
        $xpath = $this->docxXPath($this->docxPart($this->renderWord($snapshot), 'word/document.xml'));
        $cell = '/w:document/w:body/w:tbl[2]/w:tr[2]/w:tc[normalize-space(.) = "'.$label.'"]';
        $this->assertSame('1300', $xpath->evaluate('string('.$cell.'/w:tcPr/w:tcW/@w:w)'));
        $this->assertSame('FFC000', $xpath->evaluate('string('.$cell.'/w:tcPr/w:shd/@w:fill)'));
        $this->assertSame('14', $xpath->evaluate('string('.$cell.'//w:rPr/w:sz/@w:val)'));
    }

    public function test_several_special_columns_print_side_by_side_and_the_widths_still_fit(): void
    {
        $names = ['Biaya pertama', 'Biaya kedua', 'Biaya ketiga', 'Biaya keempat'];
        $full = $this->full();
        $full['special'] = array_fill_keys($names, '1000.00');
        // Every other column is printed too, so ALAMAT would have nothing left without the shrinking.
        $snapshot = $this->snapshot([$this->plain(), $full]);
        $this->assertSame($names, array_column($snapshot['totals']['special_columns'], 'name'));
        $layouts = $this->assertSameAsPdf($snapshot);
        foreach (['pdf', 'docx'] as $kind) {
            $this->assertSame(['Eksisting', ...$names, 'Upgrade / Downgrade'], array_slice($layouts[$kind]['headers'][1], 3, 6), $kind);
            foreach ($names as $name) {
                $this->assertSame('1,000', $this->summaryOf($layouts[$kind])['Total '.$name], $kind);
            }
        }
        $grid = $layouts['docx']['grid'];
        $this->assertSame(20400, array_sum($grid));
        $this->assertEqualsWithDelta(1900, $grid[array_search('ALAMAT', $layouts['docx']['headers'][0], true)], 10, 'Rounding the others may move ALAMAT a few twips.');
        $this->assertEqualsWithDelta(11, $this->pdfGrid($snapshot)['address'], 0.5);
    }

    public function test_column_widths_shrink_together_only_when_alamat_would_be_too_narrow(): void
    {
        $this->assertSame(['no' => 500, 'address' => 18900, 'net' => 1000], InvoicePrintColumns::fit(['no' => 500, 'address' => 0, 'net' => 1000], 20400, 1900, 0));
        $this->assertSame(['no' => 6167, 'address' => 1900, 'net' => 12333], InvoicePrintColumns::fit(['no' => 10000, 'address' => 0, 'net' => 20000], 20400, 1900, 0));
        $this->assertSame(['no' => 4.6, 'net' => 5.6, 'address' => 89.8], InvoicePrintColumns::fit(['no' => 4.6, 'net' => 5.6], 100, 11, 1));
        $this->assertSame(['no' => 44.5, 'net' => 44.5, 'address' => 11.0], InvoicePrintColumns::fit(['no' => 60, 'net' => 60], 100, 11, 1));
    }

    public function test_a_snapshot_from_before_special_columns_and_the_bandwidth_change_existed_still_renders(): void
    {
        $snapshot = $this->snapshot([$this->plain(), $this->plain(['charges' => ['upgrade' => '125000.00']])]);
        foreach ($snapshot['lines'] as &$line) {
            unset($line['bandwidth_change']);
        }
        unset($line, $snapshot['totals']['special_columns'], $snapshot['totals']['special_total']);

        $layouts = $this->assertSameAsPdf($snapshot);
        foreach (['pdf', 'docx'] as $kind) {
            $this->assertSame(['Eksisting', 'Eksisting', 'Upgrade / Downgrade'], $layouts[$kind]['headers'][1], $kind.': one Upgrade / Downgrade column, for the price.');
            $this->assertNotContains(self::SPECIAL, $this->headerTexts($layouts[$kind]), $kind);
            $summary = $this->summaryOf($layouts[$kind]);
            $this->assertArrayNotHasKey('Total '.self::SPECIAL_SUMMARY, $summary, $kind);
            $this->assertSame('125,000', $summary['Total Biaya Upgrade / Downgrade'], $kind);
        }
    }

    public function test_the_column_rules_treat_an_sla_of_zero_as_data_and_blank_text_as_empty(): void
    {
        $line = fn (array $with = []) => $with + ['number' => 1, 'branch' => 'Cabang Test', 'address' => 'Jl. Contoh', 'category' => 'KK', 'service' => 'VPN', 'sid' => '0012-001', 'bandwidth' => '512 Kbps',
            'sla' => '1.00000000', 'sla_credit' => '0.00', 'manual_credit' => '0.00', 'gross' => '1000.00', 'net' => '1000.00',
            'charges' => ['existing' => '1000.00', 'upgrade' => '0.00', 'downgrade' => '0.00', 'termination' => '0.00', 'installation' => '0.00', 'relocation' => '0.00']];
        $visible = fn (array ...$lines): array => InvoicePrintColumns::visible(['lines' => $lines]);

        $this->assertSame(['no', 'branch', 'address', 'category', 'service', 'sid', 'bandwidth', 'bandwidth_change', 'sla', 'credit', 'existing', 'change', 'termination', 'installation', 'relocation', 'gross', 'net'], array_keys($visible($line())));
        $empty = ['sla' => null, 'branch' => '-', 'category' => '', 'service' => ' — ', 'bandwidth' => ' - ', 'sla_credit' => null, 'charges' => array_fill_keys(['existing', 'upgrade', 'downgrade', 'termination', 'installation', 'relocation'], null)];
        $this->assertSame(['no' => true, 'address' => true, 'sid' => true, 'gross' => true, 'net' => true], array_filter($visible($line($empty), $line($empty))), 'Only the always-present columns are left when every optional cell is blank, a dash or zero.');

        // An availability of 0 is data; all lines without one is not.
        $this->assertTrue($visible($line(['sla' => null]), $line(['sla' => '0.00000000']))['sla']);
        $this->assertTrue($visible($line(['sla' => 0]))['sla']);
        $this->assertFalse($visible($line(['sla' => null]), $line(['sla' => null]))['sla']);

        // Text: one line with real text is enough.
        $this->assertTrue($visible($line($empty), $line(['branch' => 'Cabang B', 'bandwidth_change' => '2 Mbps']))['bandwidth_change']);
        $this->assertTrue($visible($line($empty), $line())['branch']);
        $this->assertFalse($visible($line(['bandwidth_change' => null]), $line(['bandwidth_change' => '-']))['bandwidth_change']);
        $this->assertFalse($visible($line(['branch' => '  ']))['branch']);

        // Amounts: a non-zero value on one line, from either credit or either change charge.
        $this->assertFalse($visible($line())['credit']);
        $this->assertTrue($visible($line(), $line(['manual_credit' => '5.00']))['credit']);
        $this->assertTrue($visible($line(['sla_credit' => '5.00']))['credit']);
        $this->assertFalse($visible($line())['change']);
        $this->assertTrue($visible($line(['charges' => ['upgrade' => '1.00']]))['change']);
        $this->assertTrue($visible($line(['charges' => ['downgrade' => '1.00']]))['change']);
        $this->assertSame([], InvoicePrintColumns::special(['totals' => []]), 'An old snapshot has no special columns.');
        foreach (['existing', 'termination', 'installation', 'relocation'] as $key) {
            $this->assertTrue($visible($line(['charges' => [$key => '1.00']]))[$key], $key);
        }
        $this->assertFalse($visible($line(['charges' => ['termination' => '0.00']]))['termination']);
        $this->assertFalse($visible($line(['charges' => ['installation' => null]]))['installation']);
    }

    public function test_conditional_summary_rows_show_and_hide_like_the_pdf(): void
    {
        $always = ['Total Biaya Layanan Eksisting', 'Total Biaya Pasang Baru Jaringan (PSB)', 'Total Biaya Pemutusan Jaringan'];
        $end = ['Total Seluruh Tagihan', 'PPN', 'Grand Total Invoice'];

        // Relocation, rounding and restitution all present.
        $snapshot = $this->snapshot([[], ['sla_credit' => '0.00', 'order' => 'RELOKASI', 'charges' => ['existing' => '1000000.00', 'relocation' => '300000.00']]]);
        $this->assertNotSame('0.00', $snapshot['totals']['rounding']);
        $layouts = $this->assertSameAsPdf($snapshot);
        $summary = $this->summaryOf($layouts['docx']);
        $this->assertSame([...$always, 'Total Biaya Relokasi', 'Total Restitusi', 'Pembulatan', ...$end], array_keys($summary));
        $this->assertSame(['300,000', '-23,198.19', '0.19'], [$summary['Total Biaya Relokasi'], $summary['Total Restitusi'], $summary['Pembulatan']]);
        $this->assertSame('3,596,621', $summary['Total Seluruh Tagihan']);

        // None of the three: whole amounts and no credits.
        $plain = ['sla' => '1.00000000', 'sla_credit' => '0.00', 'charges' => ['existing' => '2000000.00']];
        $layouts = $this->assertSameAsPdf($this->snapshot([$plain, $plain]));
        $this->assertSame([...$always, ...$end], array_keys($this->summaryOf($layouts['docx'])));
        $this->assertSame('4,000,000', $this->summaryOf($layouts['docx'])['Total Seluruh Tagihan']);

        // Only a manual credit, and a rounding that goes down.
        $layouts = $this->assertSameAsPdf($this->snapshot([['sla' => '1.00000000', 'sla_credit' => '0.00', 'manual_credit' => '5000.00', 'charges' => ['existing' => '1000000.40']]]));
        $summary = $this->summaryOf($layouts['docx']);
        $this->assertSame([...$always, 'Total Restitusi', 'Pembulatan', ...$end], array_keys($summary));
        $this->assertSame(['-5,000', '-0.40'], [$summary['Total Restitusi'], $summary['Pembulatan']]);
    }

    public function test_an_unknown_sla_is_shown_as_a_dash_like_in_the_pdf(): void
    {
        $snapshot = $this->snapshot([[], ['sla' => null, 'sla_credit' => '0.00', 'charges' => ['existing' => '1000000.00']]]);
        $layouts = $this->assertSameAsPdf($snapshot);
        $this->assertSame(['98.00%', '—'], array_column($layouts['docx']['rows'], 7));
        $this->assertSame(['98.00%', '—'], array_column($layouts['pdf']['rows'], 7));
    }

    public function test_unknown_prices_are_shown_as_dashes_like_in_the_pdf(): void
    {
        // A draft before every price is known: unknown amounts are dashes and there is no grand total to check.
        $snapshot = $this->snapshot();
        foreach ($snapshot['lines'] as &$line) {
            $line['charges'] = array_fill_keys(array_keys($line['charges']), null);
            $line['gross'] = $line['net'] = $line['sla_credit'] = null;
        }
        unset($line);
        $snapshot['totals']['components'] = array_fill_keys(array_keys($snapshot['totals']['components']), null);
        foreach (['gross', 'sla_credit', 'net_exact', 'rounding', 'subtotal', 'tax', 'grand_total'] as $key) {
            $snapshot['totals'][$key] = null;
        }

        $layouts = $this->assertSameAsPdf($snapshot);
        // Nothing but dashes would be left in the credit and price columns, so they are not generated.
        $this->assertSame(['1', 'Cabang Test', 'Jl. Contoh No. 1', 'KK', 'TELKOM VPN IP', '0012-001', '512 Kbps', '98.00%', '—', '—'], $layouts['docx']['rows'][0]);
        $this->assertSame([['NO.', 'JENIS KANTOR', 'ALAMAT', 'KATEGORI LOKASI', 'LAYANAN', 'ID', 'BANDWIDTH', 'Availability', 'TOTAL', 'HARGA SETELAH RESTITUSI'], ['Eksisting']], $layouts['docx']['headers']);
        $summary = $this->summaryOf($layouts['docx']);
        $this->assertSame(['—', '—', '—'], [$summary['Total Restitusi'], $summary['Total Seluruh Tagihan'], $summary['Grand Total Invoice']]);
        $this->assertArrayNotHasKey('Pembulatan', $summary);
    }

    public function test_special_and_control_characters_keep_every_part_well_formed(): void
    {
        $address = "Jl. Mawar & Melati <Blok A> \"Lt. 2\" 'Kiri'\x0B No. 5";
        $snapshot = $this->snapshot([['address' => $address], []]);
        $snapshot['metadata']['customer_name'] = 'PT Maju & Mundur <Persero>';
        $snapshot['metadata']['invoice_number'] = "INV-<1>&\x0B2";
        $snapshot['period']['name'] = 'Sep "26" & \'27\'';
        $path = $this->renderWord($snapshot);

        // Every XML part loads (docxPart() fails the test otherwise), none holds the control character.
        foreach ($this->docxEntries($path) as $entry) {
            if (str_ends_with($entry, '.xml') || str_ends_with($entry, '.rels')) {
                $this->assertStringNotContainsString("\x0B", $this->docxPart($path, $entry)->saveXML(), $entry);
            }
        }

        $document = $this->docxPart($path, 'word/document.xml');
        $xpath = $this->docxXPath($document);
        $this->assertSame("Jl. Mawar & Melati <Blok A> \"Lt. 2\" 'Kiri' No. 5", $xpath->evaluate('string(/w:document/w:body/w:tbl[2]/w:tr[3]/w:tc[3])'), 'The address survives exactly, without the control character.');
        $text = $this->docxText($path);
        $this->assertStringContainsString('PT Maju & Mundur <Persero>', $text);
        $this->assertStringContainsString(': INV-<1>&2', $text);
        $this->assertStringContainsString(': Sep "26" & \'27\'', $text);

        $zip = new \ZipArchive;
        $zip->open($path);
        $raw = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertStringContainsString('Mawar &amp; Melati', $raw);
        $this->assertStringContainsString('&lt;Blok A&gt;', $raw);
        $this->assertStringNotContainsString('<Blok', $raw, 'Markup characters in data are escaped.');

        $core = $this->docxPart($path, 'docProps/core.xml');
        $coreXPath = new \DOMXPath($core);
        $coreXPath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $this->assertSame('Invoice Sep "26" & \'27\' / v1', $coreXPath->evaluate('string(//dc:title)'));

        // And it is still what the PDF shows: the footer identity ("INV-<1>&2 · Sep ... · v1") and every cell.
        $layouts = $this->assertSameAsPdf($snapshot, $path);
        $this->assertSame("INV-<1>&2 · Sep \"26\" & '27' · v1", $layouts['docx']['identity']);
        $this->assertSame("Jl. Mawar & Melati <Blok A> \"Lt. 2\" 'Kiri' No. 5", $layouts['pdf']['rows'][0][2]);
    }

    public function test_the_draft_marker_is_shown_for_previews_only(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['metadata']['is_preview'] = true;
        $draft = $this->renderWord($snapshot);
        $this->assertStringContainsString(self::DRAFT, $this->docxText($draft));
        $first = $this->docxXPath($this->docxPart($draft, 'word/document.xml'))->evaluate('string(/w:document/w:body/w:p[1])');
        $this->assertSame(self::DRAFT, $first, 'The marker is the first line, above the title.');
        $this->assertSameAsPdf($snapshot, $draft);

        $final = $this->renderWord($this->snapshot());
        $this->assertStringNotContainsString(self::DRAFT, $this->docxText($final));
        $this->assertStringNotContainsString('DRAFT', $this->docxText($final));

        $snapshot['metadata']['is_preview'] = false;
        $this->assertStringNotContainsString(self::DRAFT, $this->docxText($this->renderWord($snapshot)));
    }

    public function test_the_figures_are_checked_against_the_snapshot(): void
    {
        $snapshot = $this->snapshot();
        $path = $this->renderWord($snapshot);
        $word = new InvoiceWordDocument;
        $this->assertNull($word->validate($path, $snapshot), 'The file that was rendered is accepted.');

        $moreLines = $snapshot;
        $moreLines['lines'][] = $snapshot['lines'][0];
        $fewerLines = $snapshot;
        array_pop($fewerLines['lines']);
        foreach ([$moreLines, $fewerLines] as $changed) {
            $this->assertRejected(fn () => $word->validate($path, $changed), 'Jumlah baris dokumen Word tidak sama dengan snapshot.');
        }

        foreach (['0.01', '-0.01'] as $difference) {
            $changed = $snapshot;
            $changed['totals']['net_exact'] = (string) BigDecimal::of($snapshot['totals']['net_exact'])->plus($difference);
            $this->assertRejected(fn () => $word->validate($path, $changed), 'Jumlah baris dokumen Word tidak sama dengan total snapshot.');
        }

        $changed = $snapshot;
        $changed['totals']['grand_total'] = (string) BigDecimal::of($snapshot['totals']['grand_total'])->plus('1');
        $this->assertRejected(fn () => $word->validate($path, $changed), 'Grand total dokumen Word tidak sama dengan snapshot.');

        // Without a grand total (prices still unknown) only the number of rows is checked.
        $changed = $snapshot;
        $changed['totals']['grand_total'] = null;
        $this->assertNull($word->validate($path, $changed));
    }

    public function test_a_file_that_is_not_a_word_document_is_rejected(): void
    {
        $snapshot = $this->snapshot();
        $word = new InvoiceWordDocument;
        $invalid = 'Dokumen Word tidak valid.';

        $notAZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'word-'.bin2hex(random_bytes(6)).'.docx';
        $this->cleanup[] = $notAZip;
        file_put_contents($notAZip, 'This is plain text, not a zip package.');
        $this->assertRejected(fn () => $word->validate($notAZip, $snapshot), $invalid);

        $this->assertRejected(fn () => $word->validate($this->zipWith(['word/other.xml' => '<x/>']), $snapshot), $invalid);
        $this->assertRejected(fn () => $word->validate($this->zipWith(['word/document.xml' => '<w:document>']), $snapshot), $invalid);
        $this->assertRejected(fn () => $word->validate($this->zipWith(['word/document.xml' => '<w:document xmlns:w="'.self::W.'"><w:body/></w:document>']), $snapshot), $invalid);
    }

    public function test_a_version_issued_before_the_feature_can_get_its_word_file_once(): void
    {
        $revision = $this->issuedRevision();
        $disk = Storage::disk('local');
        $period = $revision->invoicePeriod;
        $docx = $revision->outputs()->where('output_type', 'docx_cetak')->sole();
        $docx->delete();
        $disk->delete($docx->file_path);
        $this->assertFalse($disk->exists($this->folder($revision).'/invoice.docx'));

        $others = fn () => $revision->outputs()->whereIn('output_type', ['excel_invoice', 'pdf_cetak', 'summary_png'])->orderBy('output_type')->get()
            ->mapWithKeys(fn ($output) => [$output->output_type => [$output->file_path, $output->file_hash, hash_file('sha256', $disk->path($output->file_path))]])->all();
        $before = $others();
        $this->assertCount(3, $before);

        $this->get(route('invoice-periods.step3', $period))->assertOk()->assertSee('Buat file Word')->assertDontSee('Unduh Word');

        $this->post(route('billing.word-document', $revision))->assertRedirect()->assertSessionHas('success');
        $docx = $revision->outputs()->where('output_type', 'docx_cetak')->sole();
        $this->assertSame('Invoice_SEP26_v1.docx', $docx->file_name);
        $this->assertSame($this->folder($revision).'/invoice.docx', $docx->file_path);
        $this->assertTrue($disk->exists($docx->file_path));
        $this->assertSame(hash_file('sha256', $disk->path($docx->file_path)), $docx->file_hash);
        $this->assertSame('final', $docx->status);
        $this->get(route('invoice-periods.step3', $period))->assertOk()->assertSee('Unduh Word')->assertDontSee('Buat file Word');
        $this->get(route('billing.download', $docx))->assertOk();
        $this->assertSameAsPdf($revision->snapshot, $disk->path($docx->file_path));

        // Asking again changes nothing; the issued Excel/PDF/picture and their hashes stay as they were.
        $hash = $docx->file_hash;
        $this->post(route('billing.word-document', $revision))->assertRedirect();
        $this->assertSame(1, $revision->outputs()->where('output_type', 'docx_cetak')->count());
        $this->assertSame($hash, $revision->outputs()->where('output_type', 'docx_cetak')->value('file_hash'));
        $this->assertSame($before, $others());
        $this->assertSame(4, $revision->outputs()->count());
    }

    public function test_a_word_file_is_refused_for_a_version_whose_snapshot_was_altered(): void
    {
        $revision = $this->issuedRevision();
        $disk = Storage::disk('local');
        $revision->outputs()->where('output_type', 'docx_cetak')->delete();
        $disk->delete($this->folder($revision).'/invoice.docx');
        // The model refuses to change the snapshot hash, so the tampering goes straight to the table.
        DB::table('invoice_revisions')->where('id', $revision->id)->update(['snapshot_hash' => str_repeat('0', 64)]);

        $this->post(route('billing.word-document', $revision))->assertRedirect()->assertSessionHasErrors('word');
        $this->assertStringContainsString('Hash snapshot invoice tidak cocok', session('errors')->first('word'));
        $this->assertSame(0, $revision->outputs()->where('output_type', 'docx_cetak')->count());
        $this->assertFalse($disk->exists($this->folder($revision).'/invoice.docx'));
        $this->assertSame(3, $revision->outputs()->count(), 'The other files are untouched.');
    }

    public function test_the_word_file_is_refused_for_a_version_whose_files_are_not_ready(): void
    {
        $revision = $this->finalizedRevision();
        $this->assertSame('approved', $revision->status);
        foreach (['approved', 'generating', 'failed'] as $status) {
            $revision->update(['status' => $status]);
            $this->post(route('billing.word-document', $revision))->assertStatus(422);
        }
        $this->assertSame(0, $revision->outputs()->count());
        $this->assertSame([], Storage::disk('local')->allFiles($this->folder($revision)));
    }

    public function test_a_failure_in_the_word_file_does_not_block_the_invoice(): void
    {
        $revision = $this->finalizedRevision();
        $disk = Storage::disk('local');
        $log = $this->captureLog();
        $this->installBrokenWordView();

        $revision = $this->generate($revision);
        $this->assertSame('ready', $revision->status, (string) $revision->generation_error);
        $this->assertSame(['excel_invoice', 'pdf_cetak', 'summary_png'], $revision->outputs()->orderBy('output_type')->pluck('output_type')->all());
        $this->assertSame(['Dokumen Word invoice gagal dibuat: Dokumen Word tidak valid.'], $this->warnings($log));
        $this->assertSame(['invoice.pdf', 'invoice.xlsx', 'summary.png'], collect($disk->files($this->folder($revision)))->map(fn ($file) => basename($file))->sort()->values()->all(), 'No Word file and no temporary file is left behind.');

        $period = $revision->invoicePeriod;
        $this->get(route('invoice-periods.step3', $period))->assertOk()->assertSee('Buat file Word')->assertSee('Unduh Excel')->assertSee('Unduh PDF')->assertDontSee('Unduh Word');

        // While the view is still broken the button reports the problem and leaves nothing behind.
        $this->post(route('billing.word-document', $revision))->assertRedirect()->assertSessionHasErrors('word');
        $this->assertStringStartsWith('File Word gagal dibuat: ', session('errors')->first('word'));
        $this->assertSame(0, $revision->outputs()->where('output_type', 'docx_cetak')->count());
        $this->assertCount(2, $this->warnings($log));
        $this->assertSame([], array_values(array_filter($disk->files($this->folder($revision)), fn ($file) => str_ends_with($file, '.docx'))));

        // With the view repaired, the same button creates the file.
        $this->restoreViews();
        $this->post(route('billing.word-document', $revision))->assertRedirect()->assertSessionHas('success');
        $docx = $revision->outputs()->where('output_type', 'docx_cetak')->sole();
        $this->assertSame('Invoice_SEP26_v1.docx', $docx->file_name);
        $this->assertSame(hash_file('sha256', $disk->path($docx->file_path)), $docx->file_hash);
        $this->assertSame(4, $revision->outputs()->count());
        $this->get(route('invoice-periods.step3', $period))->assertOk()->assertSee('Unduh Word')->assertDontSee('Buat file Word');
        $this->assertCount(2, $this->warnings($log), 'Nothing more was logged.');
    }

    public function test_the_preview_command_writes_a_draft_word_file(): void
    {
        $period = $this->preparedPeriod();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preview-'.bin2hex(random_bytes(4));
        $this->cleanup[] = $directory;

        $this->artisan('billing:preview-documents', ['period' => $period->period_code, '--directory' => $directory])->assertSuccessful();

        $this->assertFileExists($directory.'/draft.docx');
        $this->assertFileExists($directory.'/draft.xlsx');
        $this->assertFileExists($directory.'/draft.pdf');
        $this->assertStringContainsString(self::DRAFT, $this->docxText($directory.'/draft.docx'));
        $this->assertSame('PK', file_get_contents($directory.'/draft.docx', false, null, 0, 2));
        $this->assertSame(0, InvoiceRevision::count(), 'A preview issues no invoice.');
    }
}
