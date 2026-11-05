<?php

namespace App\Services\Billing;

use App\Models\BandwidthOption;
use App\Models\InvoiceOutput;
use App\Models\InvoicePeriod;
use App\Models\InvoiceRevision;
use App\Models\LocationCategory;
use App\Models\PriceMaster;
use App\Models\Service;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class InvoiceDocumentGenerator
{
    /** Sheets of the invoice workbook, in order. */
    private const SHEETS = ['Harga', 'Detail Layanan', 'Summary Invoice'];

    /** Colour of the draft marker; the summary table uses the pure red of the customer's own workbook. */
    private const RED = 'FFE6002D';

    private const SUMMARY_RED = 'FFFF0000';

    private const HEADER_BLUE = 'FFDDEBF7';

    private const BRANCH_GREEN = 'FFC6EFCE';

    /** Fills of the Detail Layanan header, as in the customer's sheet (RGB; the header code adds the alpha). */
    private const DETAIL_HEAD_BLUE = 'D9E7FD';

    private const DETAIL_HEAD_YELLOW = 'FDD868';

    /** Row fills of Harga, one per location category (picked by category id, cycling), and the one for "Semua kategori". */
    private const CATEGORY_COLORS = [
        'FFF2CC', 'D9EAD3', 'CFE2F3', 'F4CCCC', 'D9D2E9', 'FCE5CD', 'D0E0E3', 'EAD1DC', 'C9DAF8', 'B6D7A8', 'FFE599', 'F9CB9C',
        'A2C4C9', 'B4A7D6', 'D5A6BD', 'EA9999', '9FC5E8', 'A4C2F4', '93C47D', 'FFD966', 'F6B26B', '76A5AF', 'C27BA0', 'E6B8AF',
    ];

    private const NO_CATEGORY_COLOR = 'EFEFEF';

    /** Number formats (Excel shows them with the viewer's own separators, so Indonesian users see 1.234.567). */
    private const RUPIAH = '#,##0';

    private const RUPIAH_CENTS = '#,##0.00';

    private const PERCENT = '0.00%';

    private const DATE = 'dd/mm/yyyy';

    private const SUMMARY_RUPIAH = '"Rp"#,##0;-"Rp"#,##0';

    /** Header row of the Harga table (the title is on row 1). */
    private const TARIFF_HEADER_ROW = 3;

    /** First data row of the Detail Layanan sheet (header rows 3-4, column letters on row 5). */
    private const DETAIL_FIRST_ROW = 6;

    /** First row of the table on the Summary Invoice sheet (columns B to D). */
    private const SUMMARY_TABLE_ROW = 6;

    /**
     * Harga columns: key => [header, width, number format].
     *
     * @var array<string, array{0: string, 1: int, 2: ?string}>
     */
    private const TARIFF_COLUMNS = [
        'category' => ['KATEGORI LOKASI', 22, null],
        'service' => ['LAYANAN', 28, null],
        'bandwidth' => ['BANDWIDTH', 16, null],
        'router' => ['ROUTER', 16, null],
        'monthly' => ['HARGA BULANAN', 18, self::RUPIAH],
        'installation' => ['BIAYA INSTALASI', 18, self::RUPIAH],
        'relocation' => ['BIAYA RELOKASI', 18, self::RUPIAH],
        'sid' => ['SID KHUSUS', 16, '@'],
        'start' => ['MULAI BERLAKU', 15, self::DATE],
        'end' => ['BERLAKU SAMPAI', 15, self::DATE],
        'contract' => ['KONTRAK', 22, null],
    ];

    /**
     * Detail Layanan columns, in the order of the customer's sheet: key => [width, number format, horizontal alignment].
     * The letters follow from the position (see detailColumns()), because the special columns exist only on some invoices.
     * The keys of the price columns are the InvoiceCalculator components they show.
     *
     * @var array<string, array{0: float|int, 1: ?string, 2: ?string}>
     */
    private const DETAIL_COLUMNS = [
        'number' => [6.46, '0', Alignment::HORIZONTAL_CENTER],
        'branch' => [17.6, null, null],
        'address' => [96.46, null, Alignment::HORIZONTAL_LEFT],
        'category' => [24.6, null, Alignment::HORIZONTAL_CENTER],
        'service' => [26.73, null, Alignment::HORIZONTAL_CENTER],
        'order' => [14.4, null, Alignment::HORIZONTAL_CENTER],
        'sid' => [24, '@', Alignment::HORIZONTAL_CENTER],
        'period' => [11.13, '0', Alignment::HORIZONTAL_CENTER],
        'bandwidth' => [14.46, null, Alignment::HORIZONTAL_CENTER],
        'bandwidth_up' => [14.46, null, Alignment::HORIZONTAL_CENTER],
        'bandwidth_down' => [16.4, null, Alignment::HORIZONTAL_CENTER],
        'ip' => [22.86, null, null],
        'activation' => [17.27, self::DATE, Alignment::HORIZONTAL_CENTER],
        'change_date' => [25.86, self::DATE, Alignment::HORIZONTAL_CENTER],
        'average' => [12.86, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'average_up' => [12.86, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'traffic' => [11.46, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'traffic_up' => [12.13, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'downtime' => [13.46, '0', Alignment::HORIZONTAL_CENTER],
        'sla' => [13.86, self::PERCENT, Alignment::HORIZONTAL_CENTER],
        'sla_target' => [14.4, self::PERCENT, Alignment::HORIZONTAL_CENTER],
        'billing_seconds' => [14.4, '0', Alignment::HORIZONTAL_CENTER],
        'sla_credit' => [17.46, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'manual_credit' => [18.13, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'existing' => [16.86, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'upgrade' => [17, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'downgrade' => [19.46, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'termination' => [16.73, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'installation' => [12.46, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        // The customer's width here (9.27) would show #### for a total.
        'relocation' => [12.46, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'gross' => [16.86, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'net' => [22.13, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'branch_total' => [17.86, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'mikrotik' => [25.4, null, null],
        'note' => [19.6, null, null],
    ];

    /** Width, number format and alignment of a special column; they sit right after the Eksisting price, keyed special_0, special_1, ... */
    private const SPECIAL_COLUMN = [29, self::RUPIAH, Alignment::HORIZONTAL_RIGHT];

    /** The InvoiceCalculator components shown as price columns of Detail Layanan (the keys of DETAIL_COLUMNS). */
    private const CHARGE_COMPONENTS = ['existing', 'upgrade', 'downgrade', 'termination', 'installation', 'relocation'];

    private const ORDER_LABELS = ['EXISTING' => 'EKSISTING', 'PASANG_BARU' => 'PSB', 'RELOKASI' => 'RELOKASI', 'UPGRADE' => 'UPGRADE', 'DOWNGRADE' => 'DOWNGRADE', 'PEMUTUSAN' => 'CABUT'];

    private const EVENT_LABELS = ['pasang_baru' => 'PSB', 'relokasi' => 'RELOKASI', 'upgrade' => 'UPGRADE', 'downgrade' => 'DOWNGRADE', 'pemutusan' => 'CABUT'];

    public function generate(InvoiceRevision $revision): void
    {
        $s = $revision->snapshot;
        $disk = Storage::disk('local');
        $folder = 'billing/invoices/'.$revision->invoice_id.'/v'.$revision->version;
        $disk->makeDirectory($folder);
        $token = bin2hex(random_bytes(6));
        $xlsxTmp = $disk->path($folder.'/'.$token.'.xlsx');
        $pdfTmp = $disk->path($folder.'/'.$token.'.pdf');
        try {
            $this->excel($s, $xlsxTmp, $revision->version);
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => resource_path()]);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('billing.invoice-pdf', ['snapshot' => $s, 'version' => $revision->version])->render(), 'UTF-8');
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            $pdf->getCanvas()->page_text(800, 566, '{PAGE_NUM}', $pdf->getFontMetrics()->getFont('DejaVu Sans', 'bold'), 10, [0, 0, 0]);
            file_put_contents($pdfTmp, $pdf->output());
            $this->validateFiles($xlsxTmp, $pdfTmp, $s);
            $xlsx = $folder.'/invoice.xlsx';
            $pdfPath = $folder.'/invoice.pdf';
            $disk->move($folder.'/'.$token.'.xlsx', $xlsx);
            $disk->move($folder.'/'.$token.'.pdf', $pdfPath);
            // The summary picture is a convenience: failing to draw it must not block the invoice documents.
            $pngPath = $this->renderSummary($s, $folder, $token);
            // So is the Word version: Excel and PDF are still issued, and it can be created later from the snapshot.
            $docxPath = $this->renderWord($s, $revision->version, $folder, $token);
            DB::transaction(function () use ($revision, $s, $disk, $xlsx, $pdfPath, $pngPath, $docxPath) {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::update('UPDATE invoice_periods SET input_version = input_version WHERE id = ?', [$revision->invoice_period_id]);
                }
                $period = InvoicePeriod::whereKey($revision->invoice_period_id)->lockForUpdate()->firstOrFail();
                $r = InvoiceRevision::whereKey($revision->id)->lockForUpdate()->firstOrFail();
                $name = fn (string $prefix, string $extension) => $prefix.'_'.$s['period']['code'].'_v'.$r->version.'.'.$extension;
                $files = ['excel_invoice' => [$xlsx, $name('Invoice', 'xlsx')], 'pdf_cetak' => [$pdfPath, $name('Invoice', 'pdf')]];
                if ($docxPath) {
                    $files['docx_cetak'] = [$docxPath, $name('Invoice', 'docx')];
                }
                if ($pngPath) {
                    $files['summary_png'] = [$pngPath, $name('Ringkasan', 'png')];
                }
                foreach ($files as $type => [$path, $fileName]) {
                    InvoiceOutput::updateOrCreate(['invoice_revision_id' => $r->id, 'output_type' => $type], ['invoice_period_id' => $r->invoice_period_id, 'file_name' => $fileName, 'file_path' => $path, 'file_hash' => hash_file('sha256', $disk->path($path)), 'file_size' => $disk->size($path), 'status' => 'final', 'version' => $r->version, 'generated_by' => $r->approved_by]);
                }
                $r->update(['status' => 'ready', 'generation_error' => null]);
                $latestReady = $r->invoice->revisions()->where('status', 'ready')->max('version');
                if ($latestReady === $r->version) {
                    $r->invoice->update(['total_amount' => $s['totals']['grand_total'], 'total_locations' => $s['totals']['service_count'], 'file_path' => $xlsx, 'generated_by' => $r->approved_by, 'generated_at' => now()]);
                }
                if ($period->input_version === $r->input_version && $period->revisions()->max('version') === $r->version) {
                    $period->update(['status' => 'finalized', 'is_locked' => true, 'finalized_at' => now(), 'finalized_by' => $r->approved_by, 'progress_percentage' => 100]);
                }
            });
        } finally {
            if (is_file($xlsxTmp)) {
                unlink($xlsxTmp);
            }
            if (is_file($pdfTmp)) {
                unlink($pdfTmp);
            }
        }
    }

    /**
     * Draws the summary picture next to the other documents; returns its storage path, or null when it could not be made.
     */
    private function renderSummary(array $snapshot, string $folder, string $token): ?string
    {
        $disk = Storage::disk('local');
        $tmp = $disk->path($folder.'/'.$token.'.png');
        try {
            (new SummaryImage)->render($snapshot, $tmp);
            $this->validateImage($tmp);
            $final = $folder.'/summary.png';
            if ($disk->exists($final)) {
                $disk->delete($final);
            }
            $disk->move($folder.'/'.$token.'.png', $final);

            return $final;
        } catch (\Throwable $e) {
            Log::warning('Gambar ringkasan invoice gagal dibuat: '.$e->getMessage());

            return null;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * Creates the summary picture for an invoice version that is already issued (older versions have none).
     * The picture is drawn from the frozen snapshot, so it always agrees with the Excel and PDF files.
     */
    public function addSummaryImage(InvoiceRevision $revision): InvoiceOutput
    {
        if ($revision->status !== 'ready') {
            throw new \RuntimeException('Versi invoice belum siap.');
        }
        $disk = Storage::disk('local');
        $existing = $revision->outputs()->where('output_type', 'summary_png')->first();
        if ($existing && $disk->exists($existing->file_path) && hash_file('sha256', $disk->path($existing->file_path)) === $existing->file_hash) {
            return $existing;
        }
        $folder = 'billing/invoices/'.$revision->invoice_id.'/v'.$revision->version;
        $disk->makeDirectory($folder);
        $path = $this->renderSummary($revision->snapshot, $folder, bin2hex(random_bytes(6)));
        if (! $path) {
            throw new \RuntimeException('Gambar ringkasan tidak dapat dibuat. Periksa ekstensi GD dan FreeType PHP.');
        }

        return InvoiceOutput::updateOrCreate(['invoice_revision_id' => $revision->id, 'output_type' => 'summary_png'], [
            'invoice_period_id' => $revision->invoice_period_id, 'file_name' => 'Ringkasan_'.$revision->snapshot['period']['code'].'_v'.$revision->version.'.png',
            'file_path' => $path, 'file_hash' => hash_file('sha256', $disk->path($path)), 'file_size' => $disk->size($path),
            'status' => 'final', 'version' => $revision->version, 'generated_by' => $revision->approved_by,
        ]);
    }

    /**
     * Writes the Word version next to the other documents; returns its storage path, or null when it could not be made.
     */
    private function renderWord(array $snapshot, int $version, string $folder, string $token): ?string
    {
        $disk = Storage::disk('local');
        $tmp = $disk->path($folder.'/'.$token.'.docx');
        try {
            (new InvoiceWordDocument)->render($snapshot, $version, $tmp);
            $final = $folder.'/invoice.docx';
            if ($disk->exists($final)) {
                $disk->delete($final);
            }
            $disk->move($folder.'/'.$token.'.docx', $final);

            return $final;
        } catch (\Throwable $e) {
            Log::warning('Dokumen Word invoice gagal dibuat: '.$e->getMessage());

            return null;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * Creates the Word file for an invoice version that is already issued (older versions have none).
     * It is drawn from the frozen snapshot, so it always agrees with the Excel and PDF files, which stay as they are.
     */
    public function addWordDocument(InvoiceRevision $revision): InvoiceOutput
    {
        if ($revision->status !== 'ready') {
            throw new \RuntimeException('Versi invoice belum siap.');
        }
        $disk = Storage::disk('local');
        $existing = $revision->outputs()->where('output_type', 'docx_cetak')->first();
        if ($existing && $disk->exists($existing->file_path) && hash_file('sha256', $disk->path($existing->file_path)) === $existing->file_hash) {
            return $existing;
        }
        if (hash('sha256', json_encode($revision->snapshot, JSON_THROW_ON_ERROR)) !== $revision->snapshot_hash) {
            throw new \RuntimeException('Hash snapshot invoice tidak cocok.');
        }
        $folder = 'billing/invoices/'.$revision->invoice_id.'/v'.$revision->version;
        $disk->makeDirectory($folder);
        $path = $this->renderWord($revision->snapshot, $revision->version, $folder, bin2hex(random_bytes(6)));
        if (! $path) {
            throw new \RuntimeException('Dokumen Word tidak dapat dibuat. Lihat log aplikasi untuk penyebabnya.');
        }

        return InvoiceOutput::updateOrCreate(['invoice_revision_id' => $revision->id, 'output_type' => 'docx_cetak'], [
            'invoice_period_id' => $revision->invoice_period_id, 'file_name' => 'Invoice_'.$revision->snapshot['period']['code'].'_v'.$revision->version.'.docx',
            'file_path' => $path, 'file_hash' => hash_file('sha256', $disk->path($path)), 'file_size' => $disk->size($path),
            'status' => 'final', 'version' => $revision->version, 'generated_by' => $revision->approved_by,
        ]);
    }

    private function validateImage(string $path): void
    {
        $info = @getimagesize($path);
        if (! $info || $info[2] !== IMAGETYPE_PNG || $info[0] < 1000 || $info[1] < 600) {
            throw new \RuntimeException('Gambar ringkasan tidak valid.');
        }
    }

    /**
     * Writes the invoice workbook, laid out like the customer's own Telkom workbook. It has exactly three sheets,
     * all typed values (no formulas) taken from the frozen snapshot: Harga (the tariffs of the usage period),
     * Detail Layanan (one row per SID plus the TOTAL row) and Summary Invoice (the figures of the summary picture).
     */
    public function excel(array $s, string $path, int $version = 1): void
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator('SIJATIM')->setTitle('Invoice '.$s['period']['name'].' / v'.$version);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
        $this->tariffSheet($book, $s);
        $this->detailSheet($book, $s, $version);
        $this->summarySheet($book, $s);
        $book->setActiveSheetIndex(2);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /**
     * Harga: the price of every service as in Master Harga, for the tariffs valid in the usage period.
     */
    private function tariffSheet(Spreadsheet $book, array $s): void
    {
        $rows = $this->tariffRows($s);
        $sheet = new Worksheet($book, self::SHEETS[0]);
        $book->addSheet($sheet);
        $headerRow = self::TARIFF_HEADER_ROW;
        $lastRow = $headerRow + count($rows);
        $last = Coordinate::stringFromColumnIndex(count(self::TARIFF_COLUMNS));

        $this->cell($sheet, 1, 1, "DAFTAR HARGA LAYANAN \u{2013} ".$s['period']['name']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $keys = array_keys(self::TARIFF_COLUMNS);
        foreach (array_values(self::TARIFF_COLUMNS) as $c => [$header, $width, $format]) {
            $letter = Coordinate::stringFromColumnIndex($c + 1);
            $this->cell($sheet, $c + 1, $headerRow, $header);
            $sheet->getColumnDimension($letter)->setWidth($width);
            if ($format !== null && $rows) {
                $sheet->getStyle($letter.($headerRow + 1).':'.$letter.$lastRow)->getNumberFormat()->setFormatCode($format);
            }
        }
        foreach ($rows as $i => $row) {
            $r = $headerRow + 1 + $i;
            foreach ($keys as $c => $key) {
                $this->cell($sheet, $c + 1, $r, $row[$key] ?? null);
            }
            $sheet->getStyle('A'.$r.':'.$last.$r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF'.self::categoryColor($row['category_id']));
        }
        $sheet->getStyle('A'.$headerRow.':'.$last.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        if ($rows) {
            $sheet->getStyle('A'.($headerRow + 1).':'.$last.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        }
        $this->headerStyle($sheet, 'A'.$headerRow.':'.$last.$headerRow, self::HEADER_BLUE);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter('A'.$headerRow.':'.$last.$lastRow);
    }

    /**
     * The tariffs that were calculated with (the snapshot keeps them, so a reissued file matches the calculation);
     * Master Harga is read only for a snapshot that predates that. Sorted by the category name as shown, then service,
     * bandwidth, router, start date and SID.
     *
     * @return list<array<string, mixed>>
     */
    private function tariffRows(array $s): array
    {
        $tariffs = isset($s['tariffs']) && is_array($s['tariffs']) ? $s['tariffs'] : PriceMaster::with('contract')->where('is_active', true)->get()->toArray();
        $from = $s['period']['usage_start'];
        $to = $s['period']['usage_end'];
        $categories = LocationCategory::pluck('location_category_name', 'id')->all();
        $services = Service::pluck('service_name', 'id')->all();
        $bandwidths = BandwidthOption::get(['id', 'bandwidth_name', 'value_kbps'])->keyBy('id');
        $rows = [];
        foreach ($tariffs as $t) {
            $start = substr((string) $t['effective_start_date'], 0, 10);
            $end = empty($t['effective_end_date']) ? null : substr((string) $t['effective_end_date'], 0, 10);
            if ((array_key_exists('is_active', $t) && ! $t['is_active']) || $start > $to || ($end !== null && $end < $from)) {
                continue;
            }
            $category = $t['location_category_id'] === null ? null : ($categories[$t['location_category_id']] ?? 'Kategori #'.$t['location_category_id']);
            $service = $services[$t['service_id']] ?? 'Layanan #'.$t['service_id'];
            $bandwidth = ($t['bandwidth_option_id'] ?? null) === null ? null : $bandwidths->get($t['bandwidth_option_id']);
            $router = $t['router_mode'] ?? 'none';
            $category ??= 'Semua kategori';
            $rows[] = [
                'category' => $category, 'category_id' => $t['location_category_id'], 'service' => $service,
                'bandwidth' => ($t['bandwidth_option_id'] ?? null) === null ? 'Tidak berlaku' : ($bandwidth?->bandwidth_name ?? 'Bandwidth #'.$t['bandwidth_option_id']),
                'router' => self::routerLabel($router),
                'monthly' => self::numeric($t['monthly_price'] ?? null), 'installation' => self::numeric($t['installation_price'] ?? null), 'relocation' => self::numeric($t['relocation_price'] ?? null),
                'sid' => $t['sid'] ?? null, 'start' => self::date($start), 'end' => self::date($end),
                'contract' => $t['contract']['contract_number'] ?? $t['addendum_reference'] ?? null,
                'order' => [mb_strtolower($category), mb_strtolower($service), (int) ($bandwidth?->value_kbps ?? -1), $router === 'none' ? 0 : 1, $start, (string) ($t['sid'] ?? '')],
            ];
        }
        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $rows;
    }

    /**
     * Row colour of a location category on Harga (RGB): the same category keeps its colour in every month.
     */
    private static function categoryColor(?int $categoryId): string
    {
        if ($categoryId === null) {
            return self::NO_CATEGORY_COLOR;
        }
        $count = count(self::CATEGORY_COLORS);

        return self::CATEGORY_COLORS[(($categoryId - 1) % $count + $count) % $count];
    }

    /**
     * Detail Layanan: one row per SID (type order, bandwidth changes, dates, traffic, downtime, SLA, restitution, every price,
     * the total after restitution and, on the first row of each branch, the branch subtotal), then one TOTAL row.
     */
    private function detailSheet(Spreadsheet $book, array $s, int $version): void
    {
        $sheet = new Worksheet($book, self::SHEETS[1]);
        $book->addSheet($sheet);
        $lines = array_values($s['lines']);
        $first = self::DETAIL_FIRST_ROW;
        $lastData = $first + count($lines) - 1;
        $totalRow = self::detailTotalRow(count($lines));
        $col = self::detailColumns($s);
        $last = Coordinate::stringFromColumnIndex(count($col));

        if (! empty($s['metadata']['is_preview'])) {
            $this->cell($sheet, 3, 1, 'DRAFT - BELUM DIFINALISASI');
            $sheet->getStyle('C1')->getFont()->setBold(true)->getColor()->setARGB(self::RED);
        }
        $this->cell($sheet, 2, 2, "\u{2022} TELKOM - MONTHLY RECURRING CHARGES");
        $sheet->getStyle('B2')->getFont()->setBold(true)->setName('Calibri')->setSize(12);
        $sheet->getStyle('B2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $this->detailHeader($sheet, $s, $col);
        foreach ($col as $key => $letter) {
            $sheet->getColumnDimension($letter)->setWidth((self::DETAIL_COLUMNS[$key] ?? self::SPECIAL_COLUMN)[0]);
        }

        $seen = [];
        foreach ($lines as $i => $line) {
            $branchStart = ! isset($seen[$line['branch']]);
            $seen[$line['branch']] = true;
            foreach ($this->detailValues($line, $s, $branchStart) as $key => $value) {
                if (isset($col[$key])) {
                    $this->cell($sheet, Coordinate::columnIndexFromString($col[$key]), $first + $i, $value);
                }
            }
        }

        // The TOTAL row repeats the totals of the snapshot (rounding, PPN and grand total are on Summary Invoice).
        $t = $s['totals'];
        $totals = ['sla_credit' => $t['sla_credit'], 'manual_credit' => $t['manual_credit'], 'gross' => $t['gross'], 'net' => $t['net_exact'], 'branch_total' => $t['net_exact']];
        foreach (self::CHARGE_COMPONENTS as $component) {
            $totals[$component] = $t['components'][$component] ?? null;
        }
        foreach (array_values($t['special_columns'] ?? []) as $i => $column) {
            $totals['special_'.$i] = $column['amount'];
        }
        $this->cell($sheet, 3, $totalRow, 'TOTAL');
        foreach ($totals as $key => $value) {
            if (isset($col[$key])) {
                $this->cell($sheet, Coordinate::columnIndexFromString($col[$key]), $totalRow, self::numeric($value));
            }
        }

        $body = 'A'.$first.':'.$last.$totalRow;
        $sheet->getStyle($body)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($body)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach ($col as $key => $letter) {
            [, $format, $align] = self::DETAIL_COLUMNS[$key] ?? self::SPECIAL_COLUMN;
            $column = $sheet->getStyle($letter.$first.':'.$letter.$totalRow);
            if ($format !== null) {
                $column->getNumberFormat()->setFormatCode($format);
            }
            if ($align !== null) {
                $column->getAlignment()->setHorizontal($align);
            }
        }
        $sheet->getStyle('C'.$first.':C'.$totalRow)->getAlignment()->setWrapText(true);
        if ($lines) {
            $branch = $sheet->getStyle('B'.$first.':B'.$lastData);
            $branch->getFont()->setBold(true);
            $branch->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BRANCH_GREEN);
        }
        $sheet->getStyle('A'.$totalRow.':'.$last.$totalRow)->getFont()->setBold(true);
        $sheet->getStyle('A'.$totalRow.':'.$last.$totalRow)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

        $sheet->freezePane('D'.$first);
        $sheet->setAutoFilter('A5:'.$last.max(5, $lastData));
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A3)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(3, 5);
        $sheet->getHeaderFooter()->setOddFooter('&L'.($s['metadata']['invoice_number'] ?? '').' / v'.$version.'&RHalaman &P / &N');
    }

    /**
     * The header rows 1 to 5 as in the customer's sheet: row 3 holds the titles (merged down over row 4, or across the
     * columns of a group), row 4 the sub-headers and row 5 the column letters. Style options per cell: see headerCellStyle().
     *
     * @param  array<string, string>  $col  column key => letter
     */
    private function detailHeader(Worksheet $sheet, array $s, array $col): void
    {
        foreach ([1 => 15, 2 => 15.75, 3 => 33.75, 4 => 26.25, 5 => 18.75] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
        $wrap = ['wrap' => true];
        $calibri = ['font' => 'Calibri'];

        // Row 3: [first column, last column, title, merged down over row 4, style]; a group of several columns is merged across row 3.
        $titles = [
            ['number', 'number', 'NO.', true, ['fill' => 'DAE9F8']],
            ['branch', 'branch', 'JENIS KANTOR', true, []],
            ['address', 'address', 'ALAMAT', true, []],
            ['category', 'category', 'KATEGORI LOKASI', true, ['fill' => 'C9DAF8'] + $calibri],
            ['service', 'service', 'LAYANAN', true, []],
            ['order', 'order', 'TYPE ORDER', true, []],
            ['sid', 'sid', 'ID', false, []],
            ['period', 'period', 'PERIOD', true, []],
            ['bandwidth', 'bandwidth_down', 'Bandwidth', false, []],
            ['ip', 'ip', 'IP Host Telkom', true, $calibri],
            ['activation', 'activation', 'TANGGAL AKTIVASI', true, $calibri + $wrap],
            ['change_date', 'change_date', 'TANGGAL MULAI AKTIF LAYANAN  (UPGRADE/DOWNGRADE/ MANAGE SERVICE)', true, $wrap],
            ['average', 'average_up', "PEMAKAIAN AVERAGE\n (Kbps)", false, ['size' => 11] + $wrap],
            // The customer's title says Kbps, but the numbers in these columns are GB.
            ['traffic', 'traffic_up', 'TRAFFIK TOTAL (GB)', false, ['size' => 11]],
            ['downtime', 'downtime', 'DURASI DOWNTIME', false, $wrap],
            ['sla', 'sla_target', 'SLA', false, []],
            ['billing_seconds', 'billing_seconds', 'AVAILABILITY (Detik/bulan)', true, ['fill' => 'CFE2F3'] + $calibri + $wrap],
            ['sla_credit', 'manual_credit', 'RESTITUSI (Rp)', false, []],
            ['existing', 'relocation', 'HARGA (Rp)', false, []],
            ['gross', 'gross', 'TOTAL', false, []],
            ['net', 'net', 'HARGA SETELAH RESTITUSI', false, $wrap],
            ['branch_total', 'branch_total', 'SUB TOTAL PER CABANG', false, $wrap],
            ['mikrotik', 'mikrotik', 'MIKROTIK/NONMIKROTIK', false, $wrap],
            ['note', 'note', 'MIKROTIK/NONMIKROTIK', false, $wrap],
        ];
        foreach ($titles as [$from, $to, $title, $down, $style]) {
            [$from, $to] = [$col[$from], $col[$to]];
            $this->cell($sheet, Coordinate::columnIndexFromString($from), 3, $title);
            if ($down) {
                $sheet->mergeCells($from.'3:'.$to.'4');
            } elseif ($from !== $to) {
                $sheet->mergeCells($from.'3:'.$to.'3');
            }
            $this->headerCellStyle($sheet, $from.'3:'.$to.($down ? '4' : '3'), self::DETAIL_HEAD_BLUE, $style);
        }

        // Row 4: [column, text, style], only for the cells that are not part of a title merged down.
        $size = ['size' => 11];
        $subHeaders = [
            ['sid', 'SID '.mb_strtoupper($s['period']['name']), []],
            ['bandwidth', 'Eksisting BW', []],
            ['bandwidth_up', 'Upgrade BW', []],
            ['bandwidth_down', 'Downgrade BW', []],
            ['average', 'Eksisting', $calibri + $size],
            ['average_up', 'Upgrade', $size],
            ['traffic', 'Eksisting', $size],
            ['traffic_up', 'Upgrade', $size],
            ['downtime', '(Detik)', []],
            ['sla', 'SLA ACTUAL', []],
            ['sla_target', 'SLA PKS', []],
            ['sla_credit', 'Restitusi Over SLA', ['fill' => 'FFD966'] + $size + $wrap],
            ['manual_credit', 'Restitusi Tagihan', $calibri + $wrap],
            ['existing', 'Eksisting', []],
            ['upgrade', 'Upgrade (Prorate)', []],
            ['downgrade', 'Downgrade (Prorate)', []],
            ['termination', 'Cabut (Prorate)', []],
            ['installation', 'PSB', []],
            ['relocation', 'Relokasi', []],
            ['gross', 'TOTAL', []],
            ['net', 'HARGA SETELAH RESTITUSI', $wrap],
            ['branch_total', 'SUB TOTAL PER CABANG', $wrap],
            ['mikrotik', 'MIKROTIK/NONMIKROTIK', []],
            ['note', 'KETERANGAN', []],
        ];
        foreach (array_values($s['totals']['special_columns'] ?? []) as $i => $column) {
            $subHeaders[] = ['special_'.$i, $column['name'], ['fill' => '00FFFF', 'size' => 9] + $wrap];
        }
        foreach ($subHeaders as [$key, $text, $style]) {
            if (isset($col[$key])) {
                $this->cell($sheet, Coordinate::columnIndexFromString($col[$key]), 4, $text);
                $this->headerCellStyle($sheet, $col[$key].'4', self::DETAIL_HEAD_YELLOW, $style);
            }
        }

        // Row 5: the column letters; TOTAL and HARGA SETELAH RESTITUSI keep the fill but no letter.
        $letterStyles = [
            'number' => ['fill' => 'FFC000'], 'category' => $calibri, 'order' => ['bold' => false], 'ip' => $calibri, 'activation' => $calibri,
            'change_date' => ['bold' => false, 'color' => '0000FF'], 'average' => $calibri + $size, 'average_up' => $size, 'traffic' => $size, 'traffic_up' => $size,
            'billing_seconds' => ['fill' => 'FFD966'],
        ];
        foreach ($col as $key => $letter) {
            $this->headerCellStyle($sheet, $letter.'5', self::DETAIL_HEAD_YELLOW, $letterStyles[$key] ?? (isset(self::DETAIL_COLUMNS[$key]) ? [] : ['fill' => '00FFFF']));
            if ($key !== 'gross' && $key !== 'net') {
                $this->cell($sheet, Coordinate::columnIndexFromString($letter), 5, $letter);
            }
        }
    }

    /**
     * A Detail Layanan header cell (or range): Arial 12 bold black on a solid fill, centred, thin border all round, no wrap.
     * The options change this per cell: fill, color (RGB), font (name), size, bold, wrap.
     *
     * @param  array{fill?: string, color?: string, font?: string, size?: int, bold?: bool, wrap?: bool}  $options
     */
    private function headerCellStyle(Worksheet $sheet, string $range, string $fill, array $options): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['name' => $options['font'] ?? 'Arial', 'size' => $options['size'] ?? 12, 'bold' => $options['bold'] ?? true, 'color' => ['argb' => 'FF'.($options['color'] ?? '000000')]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.($options['fill'] ?? $fill)]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => $options['wrap'] ?? false],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /**
     * Letter of every Detail Layanan column of this snapshot. The special columns of the invoice sit right after the Eksisting
     * price; each one moves the columns after it one place to the right.
     *
     * @return array<string, string> column key => letter
     */
    private static function detailColumns(array $s): array
    {
        $keys = [];
        foreach (array_keys(self::DETAIL_COLUMNS) as $key) {
            $keys[] = $key;
            if ($key === 'existing') {
                foreach (array_keys(array_values($s['totals']['special_columns'] ?? [])) as $i) {
                    $keys[] = 'special_'.$i;
                }
            }
        }

        return array_combine($keys, array_map(Coordinate::stringFromColumnIndex(...), range(1, count($keys))));
    }

    /**
     * @return array<string, mixed> values of one SID row by column key
     */
    private function detailValues(array $l, array $s, bool $branchStart): array
    {
        $service = $l['service_snapshot'] ?? [];
        $traffic = $l['traffic'] ?? [];
        $events = $l['events'] ?? [];
        $types = array_column($events, 'event_type');
        $newBandwidth = $l['bandwidth_change'] ?? null;
        // A relocation carries no date of its own, so only the last upgrade/downgrade date is shown.
        $changeDate = null;
        foreach ($events as $e) {
            if (in_array($e['event_type'], ['upgrade', 'downgrade'], true) && ! empty($e['effective_date'])) {
                $changeDate = $e['effective_date'];
            }
        }
        $values = [
            'number' => $l['number'], 'branch' => $l['branch'], 'address' => $l['address'], 'category' => $l['category'], 'service' => $l['service'], 'order' => self::orderLabel($l),
            'sid' => (string) $l['sid'], 'period' => (int) $s['period']['year'] * 100 + (int) $s['period']['month'],
            'bandwidth' => $l['bandwidth'], 'bandwidth_up' => in_array('upgrade', $types, true) ? $newBandwidth : null, 'bandwidth_down' => in_array('downgrade', $types, true) ? $newBandwidth : null,
            'ip' => $service['ip'] ?? null, 'activation' => self::date($service['activation_date'] ?? null), 'change_date' => self::date($changeDate),
            'average' => self::numeric($traffic['average_kbps'] ?? null), 'traffic' => self::numeric($traffic['volume_gb'] ?? null),
            'downtime' => $l['downtime_seconds'] ?? null, 'sla' => self::numeric($l['sla'] ?? null), 'sla_target' => self::numeric($l['sla_target'] ?? null), 'billing_seconds' => $l['billing_seconds'] ?? null,
            'sla_credit' => self::numeric($l['sla_credit'] ?? null), 'manual_credit' => self::numeric($l['manual_credit'] ?? null),
        ];
        foreach (self::CHARGE_COMPONENTS as $component) {
            $amount = self::numeric($l['charges'][$component] ?? null);
            // Only the existing charge always shows its number; the others stay empty when nothing is charged.
            $values[$component] = $component === 'existing' || ($amount !== null && $amount != 0.0) ? $amount : null;
        }
        // A line that is not in a special column leaves its cell empty.
        foreach (array_values($s['totals']['special_columns'] ?? []) as $i => $column) {
            $values['special_'.$i] = self::numeric($l['special'][$column['name']] ?? null);
        }
        $values['gross'] = self::numeric($l['gross'] ?? null);
        $values['net'] = self::numeric($l['net'] ?? null);
        $values['branch_total'] = $branchStart ? self::numeric($s['totals']['branch_totals'][$l['branch']]['net'] ?? null) : null;

        return $values;
    }

    /**
     * Type order as the customer writes it: EKSISTING, PSB, RELOKASI, UPGRADE, DOWNGRADE, CABUT; a line with several
     * changes lists its event types, e.g. "UPGRADE + RELOKASI".
     */
    private static function orderLabel(array $l): string
    {
        $order = (string) ($l['order'] ?? '');
        if ($order === 'MULTIPLE') {
            $labels = array_values(array_unique(array_map(fn ($e) => self::EVENT_LABELS[$e['event_type']] ?? strtoupper((string) $e['event_type']), $l['events'] ?? [])));

            return $labels ? implode(' + ', $labels) : $order;
        }

        return self::ORDER_LABELS[$order] ?? $order;
    }

    /**
     * Summary Invoice: the lines of the summary picture (same source, SummaryImage::rows()) in columns B to D.
     */
    private function summarySheet(Spreadsheet $book, array $s): void
    {
        $sheet = new Worksheet($book, self::SHEETS[2]);
        $book->addSheet($sheet);
        $data = SummaryImage::rows($s);
        foreach (['A' => 4, 'B' => 58, 'C' => 22, 'D' => 26] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $draft = ! empty($s['metadata']['is_preview']);
        $this->cell($sheet, 2, 2, ($draft ? 'DRAFT - BELUM DIFINALISASI - ' : '').$data['title']);
        $sheet->mergeCells('B2:D2');
        $sheet->getStyle('B2:D2')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('B2:D2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $this->cell($sheet, 2, 4, $data['subtitle']);
        $sheet->getStyle('B4')->getFont()->setBold(true)->setSize(13)->setUnderline(Font::UNDERLINE_SINGLE);

        $first = self::SUMMARY_TABLE_ROW;
        $last = $first + count($data['rows']) - 1;
        $table = $sheet->getStyle('B'.$first.':D'.$last);
        $table->getFont()->setSize(12);
        $table->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $table->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $red = fn (string $range, bool $bold) => $sheet->getStyle($range)->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::SUMMARY_RED]], 'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => $bold]]);
        $align = fn (string $range, string $horizontal) => $sheet->getStyle($range)->getAlignment()->setHorizontal($horizontal);
        foreach ($data['rows'] as $i => $row) {
            $r = $first + $i;
            $this->cell($sheet, 2, $r, ($row['key'] ?? null) === 'tax' ? self::taxLabel($s) : $row['label']);
            switch ($row['type']) {
                case 'header':
                    $this->cell($sheet, 3, $r, $row['count']);
                    $this->cell($sheet, 4, $r, $row['value']);
                    $sheet->getStyle('B'.$r.':D'.$r)->getFont()->setBold(true);
                    $align('B'.$r.':D'.$r, Alignment::HORIZONTAL_CENTER);
                    break;
                case 'band':
                    $sheet->mergeCells('B'.$r.':D'.$r);
                    $red('B'.$r.':D'.$r, false);
                    break;
                case 'item':
                case 'total':
                    $this->cell($sheet, 3, $r, $row['points']);
                    $this->cell($sheet, 4, $r, self::numeric($row['amount']));
                    $sheet->getStyle('C'.$r)->getNumberFormat()->setFormatCode('0');
                    $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode(self::SUMMARY_RUPIAH);
                    $align('C'.$r.':D'.$r, Alignment::HORIZONTAL_CENTER);
                    if ($row['type'] === 'total') {
                        $sheet->getStyle('B'.$r.':D'.$r)->getFont()->setBold(true);
                        $sheet->getStyle('B'.$r.':D'.$r)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
                    }
                    break;
                case 'sla':
                    // G and H count locations, I and J are amounts.
                    $sheet->mergeCells('B'.$r.':C'.$r);
                    // The name of a special column comes from the operator and can be long.
                    if (str_starts_with($row['key'] ?? '', 'special_')) {
                        $sheet->getStyle('B'.$r)->getAlignment()->setShrinkToFit(true);
                    }
                    $isCount = isset($row['points']);
                    $this->cell($sheet, 4, $r, $isCount ? $row['points'] : self::numeric($row['amount']));
                    $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode($isCount ? '0' : self::SUMMARY_RUPIAH);
                    $red('B'.$r.':D'.$r, true);
                    $align('D'.$r, Alignment::HORIZONTAL_CENTER);
                    break;
                case 'final':
                    $bold = isset($row['bold']);
                    $this->cell($sheet, 4, $r, self::numeric($row['amount']));
                    $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode(self::SUMMARY_RUPIAH);
                    $red('B'.$r, $bold);
                    $sheet->getStyle('D'.$r)->getFont()->setBold($bold);
                    $align('D'.$r, Alignment::HORIZONTAL_RIGHT);
                    break;
            }
        }
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setFitToWidth(1)->setFitToHeight(0);
    }

    /**
     * Fill, bold, centred and bordered header cells.
     */
    private function headerStyle(Worksheet $sheet, string $range, string $argb): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $argb]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /**
     * Strings are always written as text (SIDs keep leading zeros and dashes, nothing is read as a formula);
     * numbers as numbers. Nothing is written for null or empty values.
     */
    private function cell(Worksheet $sheet, int $c, int $r, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $sheet->setCellValueExplicit([$c, $r], $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
    }

    private static function numeric(mixed $v): int|float|null
    {
        return $v === null ? null : (float) $v;
    }

    /**
     * Excel date serial (shown as dd/mm/yyyy) of a "YYYY-MM-DD..." string.
     */
    private static function date(?string $iso): ?float
    {
        $serial = $iso ? Date::stringToExcel(substr($iso, 0, 10)) : null;

        return $serial === false ? null : $serial;
    }

    private static function routerLabel(string $mode): string
    {
        return match ($mode) {
            'none' => 'Tanpa router',
            'managed' => 'Dengan router',
            default => $mode,
        };
    }

    /**
     * The PPN rate of the snapshot (0.11 when it records none), e.g. "PPN 11%" or "PPN 11,5%".
     */
    private static function taxLabel(array $s): string
    {
        return 'PPN '.TaxRate::label(TaxRate::ofSnapshot($s));
    }

    /** Row of the TOTAL line on Detail Layanan: right below the one row per SID that starts on DETAIL_FIRST_ROW. */
    private static function detailTotalRow(int $serviceCount): int
    {
        return self::DETAIL_FIRST_ROW + $serviceCount;
    }

    /** Row of a total key of SummaryImage::rows() on the Summary Invoice sheet (the amount is in column D). */
    private static function summaryRow(array $snapshot, string $key): int
    {
        foreach (SummaryImage::rows($snapshot)['rows'] as $i => $row) {
            if (($row['key'] ?? null) === $key) {
                return self::SUMMARY_TABLE_ROW + $i;
            }
        }
        throw new \LogicException('Baris ringkasan '.$key.' tidak ditemukan.');
    }

    /**
     * Reads the saved files back: sheet names, no external links or formulas, and the money cells against the snapshot
     * (Detail Layanan: the HARGA SETELAH RESTITUSI cell of the TOTAL row and the sum of the cells above it equal net_exact,
     * and the TOTAL row of each special column equals its total; Summary Invoice:
     * the grand total in column D equals the one in the summary picture).
     */
    private function validateFiles(string $xlsx, string $pdf, array $snapshot): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($xlsx) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw new \RuntimeException('Workbook invoice tidak valid.');
        }
        $xml = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        if (array_map('strval', $xml->xpath('//x:sheet/@name')) !== self::SHEETS) {
            throw new \RuntimeException('Sheet invoice belum lengkap.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_contains($zip->getNameIndex($i), 'externalLinks')) {
                throw new \RuntimeException('Workbook memiliki tautan eksternal.');
            }
        }
        // Raw values of the cells that have one, by reference (A1 style), of sheet number $sheet.
        $cells = function (int $sheet) use ($zip): array {
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.$sheet.'.xml'));
            $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $values = [];
            foreach ($xml->xpath('//x:c[x:v]') as $c) {
                $values[(string) $c['r']] = (string) $c->v;
            }

            return $values;
        };
        // Excel stores a number as a binary float ("4313544.36000000033528"); money has two decimals, so read it back to the cent.
        $money = fn (string $value): BigDecimal => BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
        $totals = $snapshot['totals'];
        if ($totals['grand_total'] !== null) {
            $detail = $cells(2);
            $totalRow = self::detailTotalRow(count($snapshot['lines']));
            $col = self::detailColumns($snapshot);
            $net = $col['net'];
            if (! isset($detail[$net.$totalRow]) || ! $money($detail[$net.$totalRow])->isEqualTo($totals['net_exact'])) {
                throw new \RuntimeException('Total invoice rinci tidak sama dengan snapshot.');
            }
            foreach (array_values($totals['special_columns'] ?? []) as $i => $column) {
                $cell = $col['special_'.$i].$totalRow;
                if (! isset($detail[$cell]) || ! $money($detail[$cell])->isEqualTo($column['amount'])) {
                    throw new \RuntimeException('Total kolom spesial tidak sama dengan snapshot.');
                }
            }
            $sum = BigDecimal::of(0);
            for ($r = self::DETAIL_FIRST_ROW; $r < $totalRow; $r++) {
                $sum = $sum->plus($money($detail[$net.$r] ?? '0'));
            }
            if (! $sum->isEqualTo($totals['net_exact'])) {
                throw new \RuntimeException('Jumlah baris rinci tidak sama dengan total snapshot.');
            }
            $summary = $cells(3);
            $summaryCell = 'D'.self::summaryRow($snapshot, 'grand_total');
            if (! isset($summary[$summaryCell]) || ! $money($summary[$summaryCell])->isEqualTo(SummaryImage::wholeRupiah($totals['grand_total']))) {
                throw new \RuntimeException('Total ringkasan tidak sama dengan snapshot.');
            }
            if (! BigDecimal::of(BillingMath::sum(array_column($snapshot['lines'], 'net')))->isEqualTo($totals['net_exact'])) {
                throw new \RuntimeException('Detail tidak sama dengan subtotal snapshot.');
            }
        }
        foreach (range(1, count(self::SHEETS)) as $i) {
            if (preg_match('/<f[\s>]/', $zip->getFromName('xl/worksheets/sheet'.$i.'.xml'))) {
                throw new \RuntimeException('Workbook ekspor berisi formula yang tidak diharapkan.');
            }
        }
        $zip->close();
        if (filesize($pdf) < 500 || file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
            throw new \RuntimeException('PDF invoice tidak valid.');
        }
    }
}
