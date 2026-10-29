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

    private const HEADER_YELLOW = 'FFFFD966';

    private const BRANCH_GREEN = 'FFC6EFCE';

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
     * Detail Layanan columns, in order: letter => [width, number format, horizontal alignment].
     *
     * @var array<string, array{0: int, 1: ?string, 2: ?string}>
     */
    private const DETAIL_COLUMNS = [
        'A' => [6, '0', Alignment::HORIZONTAL_CENTER],
        'B' => [18, null, null],
        'C' => [60, null, Alignment::HORIZONTAL_LEFT],
        'D' => [20, null, Alignment::HORIZONTAL_CENTER],
        'E' => [18, null, Alignment::HORIZONTAL_CENTER],
        'F' => [14, null, Alignment::HORIZONTAL_CENTER],
        'G' => [22, '@', Alignment::HORIZONTAL_CENTER],
        'H' => [9, '0', Alignment::HORIZONTAL_CENTER],
        'I' => [13, null, Alignment::HORIZONTAL_CENTER],
        'J' => [13, null, Alignment::HORIZONTAL_CENTER],
        'K' => [13, null, Alignment::HORIZONTAL_CENTER],
        'L' => [22, null, null],
        'M' => [14, self::DATE, Alignment::HORIZONTAL_CENTER],
        'N' => [22, self::DATE, Alignment::HORIZONTAL_CENTER],
        'O' => [12, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'P' => [12, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'Q' => [12, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'R' => [12, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'S' => [12, '0', Alignment::HORIZONTAL_CENTER],
        'T' => [11, self::PERCENT, Alignment::HORIZONTAL_CENTER],
        'U' => [11, self::PERCENT, Alignment::HORIZONTAL_CENTER],
        'V' => [14, '0', Alignment::HORIZONTAL_CENTER],
        'W' => [14, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'X' => [14, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'Y' => [15, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'Z' => [15, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'AA' => [15, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'AB' => [15, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'AC' => [15, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'AD' => [15, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'AE' => [16, self::RUPIAH_CENTS, Alignment::HORIZONTAL_RIGHT],
        'AF' => [16, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
        'AG' => [16, self::RUPIAH, Alignment::HORIZONTAL_RIGHT],
    ];

    /** The price columns of Detail Layanan and the InvoiceCalculator component each one shows. */
    private const CHARGE_COLUMNS = ['Y' => 'existing', 'Z' => 'upgrade', 'AA' => 'downgrade', 'AB' => 'termination', 'AC' => 'installation', 'AD' => 'relocation'];

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
            DB::transaction(function () use ($revision, $s, $disk, $xlsx, $pdfPath, $pngPath) {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    DB::update('UPDATE invoice_periods SET input_version = input_version WHERE id = ?', [$revision->invoice_period_id]);
                }
                $period = InvoicePeriod::whereKey($revision->invoice_period_id)->lockForUpdate()->firstOrFail();
                $r = InvoiceRevision::whereKey($revision->id)->lockForUpdate()->firstOrFail();
                $name = fn (string $prefix, string $extension) => $prefix.'_'.$s['period']['code'].'_v'.$r->version.'.'.$extension;
                $files = ['excel_invoice' => [$xlsx, $name('Invoice', 'xlsx')], 'pdf_cetak' => [$pdfPath, $name('Invoice', 'pdf')]];
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
            foreach ($keys as $c => $key) {
                $this->cell($sheet, $c + 1, $headerRow + 1 + $i, $row[$key] ?? null);
            }
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
     * Master Harga is read only for a snapshot that predates that. Sorted by service, category, bandwidth and router.
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
            $rows[] = [
                'category' => $category ?? 'Semua kategori', 'service' => $service,
                'bandwidth' => ($t['bandwidth_option_id'] ?? null) === null ? 'Tidak berlaku' : ($bandwidth?->bandwidth_name ?? 'Bandwidth #'.$t['bandwidth_option_id']),
                'router' => self::routerLabel($router),
                'monthly' => self::numeric($t['monthly_price'] ?? null), 'installation' => self::numeric($t['installation_price'] ?? null), 'relocation' => self::numeric($t['relocation_price'] ?? null),
                'sid' => $t['sid'] ?? null, 'start' => self::date($start), 'end' => self::date($end),
                'contract' => $t['contract']['contract_number'] ?? $t['addendum_reference'] ?? null,
                'order' => [mb_strtolower($service), mb_strtolower($category ?? ''), (int) ($bandwidth?->value_kbps ?? -1), $router === 'none' ? 0 : 1, $start, (string) ($t['sid'] ?? '')],
            ];
        }
        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $rows;
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
        $last = array_key_last(self::DETAIL_COLUMNS);
        $letters = array_keys(self::DETAIL_COLUMNS);

        if (! empty($s['metadata']['is_preview'])) {
            $this->cell($sheet, 3, 1, 'DRAFT - BELUM DIFINALISASI');
            $sheet->getStyle('C1')->getFont()->setBold(true)->getColor()->setARGB(self::RED);
        }
        $this->cell($sheet, 2, 2, "\u{2022} TELKOM - MONTHLY RECURRING CHARGES");
        $sheet->getStyle('B2')->getFont()->setBold(true);
        $this->detailHeader($sheet, $s);
        foreach ($letters as $c => $letter) {
            $this->cell($sheet, $c + 1, 5, $letter);
            $sheet->getColumnDimension($letter)->setWidth(self::DETAIL_COLUMNS[$letter][0]);
        }
        $this->headerStyle($sheet, 'A5:'.$last.'5', self::HEADER_YELLOW);

        $seen = [];
        foreach ($lines as $i => $line) {
            $branchStart = ! isset($seen[$line['branch']]);
            $seen[$line['branch']] = true;
            foreach ($this->detailValues($line, $s, $branchStart) as $letter => $value) {
                $this->cell($sheet, Coordinate::columnIndexFromString($letter), $first + $i, $value);
            }
        }

        // The TOTAL row repeats the totals of the snapshot (rounding, PPN and grand total are on Summary Invoice).
        $t = $s['totals'];
        $totals = ['W' => $t['sla_credit'], 'X' => $t['manual_credit'], 'AE' => $t['gross'], 'AF' => $t['net_exact'], 'AG' => $t['net_exact']];
        foreach (self::CHARGE_COLUMNS as $letter => $component) {
            $totals[$letter] = $t['components'][$component] ?? null;
        }
        $this->cell($sheet, 3, $totalRow, 'TOTAL');
        foreach ($totals as $letter => $value) {
            $this->cell($sheet, Coordinate::columnIndexFromString($letter), $totalRow, self::numeric($value));
        }

        $body = 'A'.$first.':'.$last.$totalRow;
        $sheet->getStyle($body)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($body)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach (self::DETAIL_COLUMNS as $letter => [, $format, $align]) {
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
     * The two header rows (3 and 4): a group title merged across its sub-columns, or down both rows when it has no sub-header.
     * The column letters follow on row 5.
     */
    private function detailHeader(Worksheet $sheet, array $s): void
    {
        $last = array_key_last(self::DETAIL_COLUMNS);
        $this->headerStyle($sheet, 'A3:'.$last.'4', self::HEADER_BLUE);
        $sheet->getRowDimension(3)->setRowHeight(30);
        $sheet->getRowDimension(4)->setRowHeight(30);
        $groups = [
            ['A', 'A', 'NO.', null],
            ['B', 'B', 'JENIS KANTOR', null],
            ['C', 'C', 'ALAMAT', null],
            ['D', 'D', 'KATEGORI LOKASI', null],
            ['E', 'E', 'LAYANAN', null],
            ['F', 'F', 'TYPE ORDER', null],
            ['G', 'G', 'ID', ['SID '.mb_strtoupper($s['period']['name'])]],
            ['H', 'H', 'PERIOD', null],
            ['I', 'K', 'Bandwidth', ['Eksisting BW', 'Upgrade BW', 'Downgrade BW']],
            ['L', 'L', 'IP Host Telkom', null],
            ['M', 'M', 'TANGGAL AKTIVASI', null],
            ['N', 'N', 'TANGGAL MULAI AKTIF LAYANAN (UPGRADE/DOWNGRADE)', null],
            ['O', 'P', 'PEMAKAIAN AVERAGE (Kbps)', ['Eksisting', 'Upgrade']],
            ['Q', 'R', 'TRAFFIK TOTAL (GB)', ['Eksisting', 'Upgrade']],
            ['S', 'S', 'DURASI DOWNTIME (Detik)', null],
            ['T', 'U', 'SLA', ['SLA ACTUAL', 'SLA PKS']],
            ['V', 'V', 'AVAILABILITY (Detik/bulan)', null],
            ['W', 'X', 'RESTITUSI (Rp)', ['Restitusi Over SLA', 'Restitusi Tagihan']],
            ['Y', 'AD', 'HARGA (Rp)', ['Eksisting', 'Upgrade (Prorate)', 'Downgrade (Prorate)', 'Cabut (Prorate)', 'PSB', 'Relokasi']],
            ['AE', 'AE', 'TOTAL', null],
            ['AF', 'AF', 'HARGA SETELAH RESTITUSI', null],
            ['AG', 'AG', 'SUB TOTAL PER CABANG', null],
        ];
        foreach ($groups as [$from, $to, $title, $subHeaders]) {
            $column = Coordinate::columnIndexFromString($from);
            $this->cell($sheet, $column, 3, $title);
            if ($subHeaders === null) {
                $sheet->mergeCells($from.'3:'.$to.'4');

                continue;
            }
            if ($from !== $to) {
                $sheet->mergeCells($from.'3:'.$to.'3');
            }
            foreach ($subHeaders as $i => $subHeader) {
                $this->cell($sheet, $column + $i, 4, $subHeader);
            }
            $sheet->getStyle($from.'4:'.$to.'4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_YELLOW);
        }
    }

    /**
     * @return array<string, mixed> values of one SID row by column letter
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
            'A' => $l['number'], 'B' => $l['branch'], 'C' => $l['address'], 'D' => $l['category'], 'E' => $l['service'], 'F' => self::orderLabel($l),
            'G' => (string) $l['sid'], 'H' => (int) $s['period']['year'] * 100 + (int) $s['period']['month'],
            'I' => $l['bandwidth'], 'J' => in_array('upgrade', $types, true) ? $newBandwidth : null, 'K' => in_array('downgrade', $types, true) ? $newBandwidth : null,
            'L' => $service['ip'] ?? null, 'M' => self::date($service['activation_date'] ?? null), 'N' => self::date($changeDate),
            'O' => self::numeric($traffic['average_kbps'] ?? null), 'Q' => self::numeric($traffic['volume_gb'] ?? null),
            'S' => $l['downtime_seconds'] ?? null, 'T' => self::numeric($l['sla'] ?? null), 'U' => self::numeric($l['sla_target'] ?? null), 'V' => $l['billing_seconds'] ?? null,
            'W' => self::numeric($l['sla_credit'] ?? null), 'X' => self::numeric($l['manual_credit'] ?? null),
        ];
        foreach (self::CHARGE_COLUMNS as $letter => $component) {
            $amount = self::numeric($l['charges'][$component] ?? null);
            // Only the existing charge always shows its number; the others stay empty when nothing is charged.
            $values[$letter] = $letter === 'Y' || ($amount !== null && $amount != 0.0) ? $amount : null;
        }
        $values['AE'] = self::numeric($l['gross'] ?? null);
        $values['AF'] = self::numeric($l['net'] ?? null);
        $values['AG'] = $branchStart ? self::numeric($s['totals']['branch_totals'][$l['branch']]['net'] ?? null) : null;

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
     * (Detail Layanan: AF of the TOTAL row and the sum of the AF cells above it equal net_exact; Summary Invoice:
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
            if (! isset($detail['AF'.$totalRow]) || ! $money($detail['AF'.$totalRow])->isEqualTo($totals['net_exact'])) {
                throw new \RuntimeException('Total invoice rinci tidak sama dengan snapshot.');
            }
            $sum = BigDecimal::of(0);
            for ($r = self::DETAIL_FIRST_ROW; $r < $totalRow; $r++) {
                $sum = $sum->plus($money($detail['AF'.$r] ?? '0'));
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
