<?php

namespace App\Exports;

use App\Models\InvoiceLine;
use App\Models\TypeOrder;
use App\Models\BandwidthOption;
use App\Models\InvoicePeriod;
use App\Models\BankJatimLink;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class Step2TemplateExport
{
    protected $invoicePeriod;
    protected $invoiceLines;
    protected $allBankJatimLinks;
    protected $selectedLinkIds;
    protected $typeOrders;
    protected $bandwidthOptions;

    /**
     * @param \Illuminate\Support\Collection $invoiceLines    Baris InvoiceLine milik periode ini (subset yang dipakai)
     * @param \Illuminate\Support\Collection $bankJatimLinks   SEMUA master BankJatimLink (untuk sheet DATABASE)
     */
    public function __construct($invoicePeriod, $invoiceLines, $typeOrders, $bandwidthOptions, $bankJatimLinks = null)
    {
        $this->invoicePeriod    = $invoicePeriod;
        $this->invoiceLines     = $invoiceLines;
        $this->allBankJatimLinks = $bankJatimLinks ?? BankJatimLink::with(['officeType', 'locationCategory', 'service', 'currentBandwidth'])
            ->orderBy('office_type_id')
            ->orderBy('sequence_in_office_type')
            ->get();
        $this->selectedLinkIds  = $invoiceLines->pluck('bank_jatim_link_id')->filter()->unique()->values()->all();
        $this->typeOrders       = $typeOrders;
        $this->bandwidthOptions = $bandwidthOptions;
    }

    /**
     * Generate and download the Excel file
     */
    public function download()
    {
        $spreadsheet = new Spreadsheet();

        // Hapus sheet default yang auto-created oleh PhpSpreadsheet (nama "Worksheet")
        // supaya total cuma 2 sheet: DATABASE + period_code
        $defaultSheet = $spreadsheet->getSheet(0);
        $spreadsheet->removeSheetByIndex(0);

        // Sheet 1: DATABASE
        $databaseSheet = $spreadsheet->createSheet();
        $this->createDatabaseSheet($databaseSheet);

        // Sheet 2: NAMAPERIODETAHUN (Input)
        $inputSheet = $spreadsheet->createSheet();
        $this->createInputSheet($inputSheet);

        // Set active sheet to the input sheet (second sheet)
        $spreadsheet->setActiveSheetIndex(1);

        $fileName = 'SUB_TAHAP_2_' . $this->invoicePeriod->period_code . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Create DATABASE sheet (master read-only reference, semua BankJatimLink)
     */
    private function createDatabaseSheet(Worksheet $sheet)
    {
        $sheet->setTitle('DATABASE');

        // 9 kolom: tambah kolom "DIPAKAI" di akhir sebagai penanda SSID yang dipakai periode ini
        $headers = ['NO', 'JENIS KANTOR', 'KATEGORI', 'LAYANAN', 'SSID', 'ALAMAT', 'IP HOST', 'TGL MULAI', 'DIPAKAI ' . $this->invoicePeriod->period_code];
        $sheet->fromArray($headers, null, 'A1');

        // Header styling
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
        $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(25);
        $sheet->getColumnDimension('F')->setWidth(45);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(12);

        // Freeze header
        $sheet->freezePane('A2');

        // Data rows — loop SEMUA BankJatimLink (master), bukan hanya yang dipakai periode ini
        $rowNum = 2;
        foreach ($this->allBankJatimLinks as $index => $link) {
            $isSelected = in_array($link->id, $this->selectedLinkIds);

            $sheet->setCellValue('A' . $rowNum, $index + 1);
            $sheet->setCellValue('B' . $rowNum, $link->officeType->name ?? '-');
            $sheet->setCellValue('C' . $rowNum, $link->locationCategory->name ?? '-');
            $sheet->setCellValue('D' . $rowNum, $link->service->name ?? '-');
            $sheet->setCellValue('E' . $rowNum, $link->ssid ?? '-');
            $sheet->setCellValue('F' . $rowNum, $link->address ?? '-');
            $sheet->setCellValue('G' . $rowNum, $link->ip_host_telkom ?? '-');
            $sheet->setCellValue('H' . $rowNum, $link->service_start_date ? $link->service_start_date->format('d/m/Y') : '-');
            $sheet->setCellValue('I' . $rowNum, $isSelected ? '✓' : '');

            // Row height
            $sheet->getRowDimension($rowNum)->setRowHeight(20);

            // Striping
            if ($rowNum % 2 == 0) {
                $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                ]);
            } else {
                $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                ]);
            }

            // Highlight baris yang dipakai periode ini
            if ($isSelected) {
                $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9C4']],
                    'font' => ['bold' => true],
                ]);
            }

            $rowNum++;
        }
    }

    /**
     * Create INPUT sheet (sheet kedua, nama = period_code)
     *
     * Layout:
     *   baris 1   : judul + merge A1:I1
     *   baris 2   : header kolom (style biru #1565C0, font putih, bold, rowHeight 35)
     *   baris 3   : data row pertama — kolom A = hardcode 1, kolom D = SSID,
     *                kolom B/C = formula INDEX/MATCH ke sheet DATABASE
     *   baris 4+  : kolom A = formula =A{prev}+1 (auto-increment), sisanya sama
     *
     * Border tipis di SEMUA sel (A:I pada header dan data rows).
     * Alignment center horizontal+vertical untuk semua value + header.
     * Font value utama hitam pekat (#000000), TANPA gray.
     * TANPA kolom J (separator) dan kolom K (CATATAN), TANPA freeze pane.
     */
    private function createInputSheet(Worksheet $sheet)
    {
        // Set sheet name to period code (e.g., JAN25)
        $sheet->setTitle($this->invoicePeriod->period_code);

        // --- ROW 1: Title ---
        $sheet->setCellValue('A1', 'SUB TAHAP 2: TYPE ORDER & BANDWIDTH - ' . $this->invoicePeriod->period_code);
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(18.75);

        // --- ROWS 2-9: CATATAN / PANDUAN PENGISIAN ---
        $catatanRows = $this->buildCatatanSection($sheet);

        // --- ROW N: Header kolom (shifts based on catatan rows) ---
        $headerRow = 2 + $catatanRows;
        $headers = ['NO', 'JENIS KANTOR', 'LAYANAN', 'SSID', 'TYPE ORDER', 'TGL PERUBAHAN', 'EXISTING', 'UPGRADE', 'DOWNGRADE'];
        $sheet->fromArray($headers, null, 'A' . $headerRow);

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1565C0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']],
                'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']],
            ],
        ];
        $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->applyFromArray($headerStyle);
        $sheet->getRowDimension($headerRow)->setRowHeight(35);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(15);

        // --- DATA ROWS: start after header ---
        $dataStartRow = $headerRow + 1;
        $typeOrderCodes = $this->typeOrders->pluck('type_order_code')->toArray();
        $bandwidthNames = $this->bandwidthOptions->pluck('bandwidth_name')->toArray();

        $dataCellStyle = [
            'font' => ['color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']],
                'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']],
            ],
        ];
        $dataCellStripedStyle = $dataCellStyle + [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
        ];

        foreach ($this->invoiceLines as $index => $line) {
            $bankLink = $line->bankJatimLink;
            $rowNum = $dataStartRow + $index;

            if ($index === 0) {
                $sheet->setCellValue('A' . $rowNum, 1);
            } else {
                $sheet->setCellValue('A' . $rowNum, '=A' . ($rowNum - 1) . '+1');
            }

            $sheet->setCellValue('D' . $rowNum, $bankLink->ssid ?? '-');
            $sheet->setCellValue('B' . $rowNum, '=IFERROR(INDEX(DATABASE!$B:$B,MATCH($D' . $rowNum . ',DATABASE!$E:$E,0)),"")');
            $sheet->setCellValue('C' . $rowNum, '=IFERROR(INDEX(DATABASE!$D:$D,MATCH($D' . $rowNum . ',DATABASE!$E:$E,0)),"")');

            $rowStyle = ($rowNum % 2 == 0) ? $dataCellStripedStyle : $dataCellStyle;
            $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray($rowStyle);
            $sheet->getRowDimension($rowNum)->setRowHeight(22);
        }

        $lastDataRow = $dataStartRow + count($this->invoiceLines) - 1;

        // Data validations (dropdowns)
        if (!empty($typeOrderCodes)) {
            $typeOrderValidation = new DataValidation();
            $typeOrderValidation->setType(DataValidation::TYPE_LIST);
            $typeOrderValidation->setErrorStyle(DataValidation::STYLE_STOP);
            $typeOrderValidation->setAllowBlank(true);
            $typeOrderValidation->setShowInputMessage(true);
            $typeOrderValidation->setPromptTitle('TYPE ORDER');
            $typeOrderValidation->setPrompt('Pilih type order dari daftar');
            $typeOrderValidation->setErrorTitle('Error');
            $typeOrderValidation->setError('Nilai tidak valid. Pilih dari daftar.');
            $typeOrderValidation->setFormula1('"' . implode(',', $typeOrderCodes) . '"');
            $typeOrderValidation->setSqref('E' . $dataStartRow . ':E' . $lastDataRow);
            $sheet->setDataValidation('E' . $dataStartRow . ':E' . $lastDataRow, $typeOrderValidation);
        }

        if (!empty($bandwidthNames)) {
            $bandwidthValidation = new DataValidation();
            $bandwidthValidation->setType(DataValidation::TYPE_LIST);
            $bandwidthValidation->setErrorStyle(DataValidation::STYLE_STOP);
            $bandwidthValidation->setAllowBlank(true);
            $bandwidthValidation->setShowInputMessage(true);
            $bandwidthValidation->setPromptTitle('Bandwidth');
            $bandwidthValidation->setPrompt('Pilih bandwidth dari daftar');
            $bandwidthValidation->setErrorTitle('Error');
            $bandwidthValidation->setError('Nilai tidak valid. Pilih dari daftar.');
            $bandwidthValidation->setFormula1('"' . implode(',', $bandwidthNames) . '"');

            $sheet->setDataValidation('G' . $dataStartRow . ':G' . $lastDataRow, clone $bandwidthValidation);
            $sheet->setDataValidation('H' . $dataStartRow . ':H' . $lastDataRow, clone $bandwidthValidation);
            $sheet->setDataValidation('I' . $dataStartRow . ':I' . $lastDataRow, clone $bandwidthValidation);
        }
    }

    /**
     * Build the CATATAN / PANDUAN PENGISIAN section
     * Returns the number of rows used by this section.
     */
    private function buildCatatanSection(Worksheet $sheet): int
    {
        $startRow = 2;

        // Row 2: Judul catatan
        $sheet->setCellValue('A' . $startRow, 'CATATAN / PANDUAN PENGISIAN');
        $sheet->mergeCells('A' . $startRow . ':I' . $startRow);
        $sheet->getStyle('A' . $startRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($startRow)->setRowHeight(20);

        // Helper style for catatan rows
        $catatanCellStyle = [
            'font' => ['size' => 10, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
        $catatanLabelStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '2E7D32']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $catatanValueStyle = [
            'font' => ['size' => 10, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];

        $r = $startRow + 1;

        // --- TYPE ORDER ---
        $sheet->setCellValue('A' . $r, 'TYPE ORDER');
        $sheet->mergeCells('A' . $r . ':B' . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($catatanLabelStyle);
        $sheet->setCellValue('C' . $r, 'Pilih salah satu:');
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->mergeCells('C' . $r . ':I' . $r);
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        $typeOrderData = [
            ['code' => 'existing',      'name' => 'EXISTING',      'desc' => 'Tidak ada perubahan bandwidth (tetap)'],
            ['code' => 'upgrade',      'name' => 'UPGRADE',       'desc' => 'Naik bandwidth (contoh: 512 Kbps → 1 Mbps)'],
            ['code' => 'downgrade',    'name' => 'DOWNGRADE',     'desc' => 'Turun bandwidth (contoh: 2 Mbps → 1 Mbps)'],
            ['code' => 'pasang_baru',  'name' => 'PASANG BARU',   'desc' => 'Layanan baru / SSID baru'],
            ['code' => 'relokasi',     'name' => 'RELOKASI',      'desc' => 'Pemindahan lokasi'],
            ['code' => 'pemutusan',    'name' => 'PEMUTUSAN',     'desc' => 'Penghentian / pemutusan layanan'],
        ];
        foreach ($typeOrderData as $to) {
            $sheet->setCellValue('C' . $r, $to['name']);
            $sheet->getStyle('C' . $r)->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1565C0']],
            ]);
            $sheet->setCellValue('D' . $r, '→ ' . $to['desc']);
            $sheet->mergeCells('D' . $r . ':I' . $r);
            $sheet->getStyle('D' . $r)->applyFromArray($catatanCellStyle);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;
        }

        // Blank separator
        $sheet->getRowDimension($r)->setRowHeight(6);
        $r++;

        // --- BANDWIDTH ---
        $sheet->setCellValue('A' . $r, 'BANDWIDTH');
        $sheet->mergeCells('A' . $r . ':B' . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($catatanLabelStyle);
        $sheet->setCellValue('C' . $r, 'Pilihan tersedia (pilih dari dropdown):');
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->mergeCells('C' . $r . ':I' . $r);
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        // Bandwidth options in chunks of 3 per row
        $bwOptions = ['32 Kbps', '128 Kbps', '256 Kbps', '512 Kbps', '1 Mbps', '2 Mbps', '10 Mbps', '12 Mbps', '100 Mbps', 'TIDAK BERLAKU'];
        $chunkSize = 5;
        $chunks = array_chunk($bwOptions, $chunkSize);
        foreach ($chunks as $chunk) {
            $sheet->setCellValue('C' . $r, implode('   |   ', $chunk));
            $sheet->mergeCells('C' . $r . ':I' . $r);
            $sheet->getStyle('C' . $r)->applyFromArray([
                'font' => ['size' => 10, 'color' => ['rgb' => '1565C0']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;
        }

        // Blank separator
        $sheet->getRowDimension($r)->setRowHeight(6);
        $r++;

        // --- TGL PERUBAHAN ---
        $sheet->setCellValue('A' . $r, 'TGL PERUBAHAN');
        $sheet->mergeCells('A' . $r . ':B' . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($catatanLabelStyle);
        $sheet->setCellValue('C' . $r, 'Format: dd/mm/yyyy  (contoh: 01/01/2026). Kosongkan jika TIDAK ADA perubahan bandwidth.');
        $sheet->mergeCells('C' . $r . ':I' . $r);
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        // --- EXISTING / UPGRADE / DOWNGRADE ---
        $sheet->setCellValue('A' . $r, 'EXISTING');
        $sheet->mergeCells('A' . $r . ':B' . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($catatanLabelStyle);
        $sheet->setCellValue('C' . $r, 'Bandwidth SAAT INI (sebelum perubahan). Kosongkan jika tidak ada perubahan.');
        $sheet->mergeCells('C' . $r . ':I' . $r);
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        $sheet->setCellValue('A' . $r, 'UPGRADE');
        $sheet->mergeCells('A' . $r . ':B' . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($catatanLabelStyle);
        $sheet->setCellValue('C' . $r, 'Bandwidth BARU (setelah upgrade). Jika TYPE ORDER = UPGRADE, isi EXISTING + UPGRADE.');
        $sheet->mergeCells('C' . $r . ':I' . $r);
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        $sheet->setCellValue('A' . $r, 'DOWNGRADE');
        $sheet->mergeCells('A' . $r . ':B' . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($catatanLabelStyle);
        $sheet->setCellValue('C' . $r, 'Bandwidth BARU (setelah downgrade). Jika TYPE ORDER = DOWNGRADE, isi EXISTING + DOWNGRADE.');
        $sheet->mergeCells('C' . $r . ':I' . $r);
        $sheet->getStyle('C' . $r)->applyFromArray($catatanCellStyle);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        // Blank separator before header
        $sheet->getRowDimension($r)->setRowHeight(6);
        $r++;

        return $r - $startRow; // total rows consumed by catatan section
    }
}
