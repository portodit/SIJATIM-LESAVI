<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\ImportBatch;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Operator screens for filling a past month (JAN26, usage December 2025) from Telkom's workbook.
 * The period holds 0012-ABC (also in the file) and 0034-OLD (not in the file); the file adds 0099-NEW,
 * which the master does not know yet.
 */
class HistoryImportUiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $withLines = true): array
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KANTOR KAS']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $link = fn (string $sid, int $sequence) => BankJatimLink::create([
            'ssid' => $sid, 'office_type_id' => $office->id, 'sequence_in_office_type' => $sequence, 'location_category_id' => $category->id, 'service_id' => $service->id,
            'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Master '.$sequence, 'service_start_date' => '2020-01-01', 'link_status' => 'active',
        ]);
        $kept = $link('0012-ABC', 1);
        $old = $link('0034-OLD', 2);
        $period = InvoicePeriod::create(['month' => 1, 'year' => 2026, 'created_by' => auth()->id()]);
        if ($withLines) {
            foreach ([$kept, $old] as $i => $master) {
                InvoiceLine::create(['invoice_period_id' => $period->id, 'bank_jatim_link_id' => $master->id, 'line_number' => $i + 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
            }
            // An input on the SSID that leaves the period: it is removed together with the line.
            ServiceEvent::create(['invoice_period_id' => $period->id, 'sid' => '0034-OLD', 'event_type' => 'upgrade', 'effective_date' => '2025-12-10', 'attributes' => ['bandwidth_option_id' => $bw->id], 'source' => ['origin' => 'operator']]);
        }

        return compact('period', 'kept', 'old', 'office', 'category', 'service', 'bw');
    }

    /** Telkom layout: group headers row 3, sub-headers row 4, letter row 5, data from row 6. All 17 data fields have a column. */
    private function workbook(string $sheetName = 'JAN26'): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($sheetName);
        $letters = array_map([Coordinate::class, 'stringFromColumnIndex'], range(1, 30));
        $rows = [
            3 => [
                'A' => 'NO.', 'B' => 'JENIS KANTOR', 'C' => 'ALAMAT', 'D' => 'KATEGORI LOKASI', 'E' => 'LAYANAN', 'F' => 'TYPE ORDER', 'G' => 'ID', 'H' => 'PERIOD',
                'I' => 'Bandwidth', 'L' => 'IP Host Telkom', 'M' => 'TANGGAL AKTIVASI', 'N' => "TANGGAL MULAI AKTIF LAYANAN \n (UPGRADE/DOWNGRADE)", 'S' => 'DURASI DOWNTIME', 'Y' => 'HARGA (Rp)',
            ],
            4 => [
                'G' => 'SID JAN 2025', 'I' => 'Eksisting BW', 'J' => 'Upgrade BW', 'K' => 'Downgrade BW', 'S' => '(Detik)',
                'Y' => 'Eksisting', 'Z' => 'Upgrade (Prorate)', 'AA' => 'Downgrade (Prorate)', 'AB' => 'Cabut (Prorate)', 'AC' => 'PSB (Prorate)', 'AD' => 'Relokasi',
            ],
            5 => array_combine($letters, $letters),
            6 => [
                'A' => 1, 'B' => 'Cabang Test', 'C' => 'Jl. Test', 'D' => 'KANTOR KAS', 'E' => 'TELKOM VPN IP', 'F' => 'EKSISTING', 'G' => '0012-ABC', 'H' => 202512,
                'I' => '512 Kbps', 'L' => '10.0.0.1 /30', 'M' => '2019-05-01', 'S' => 3600, 'Y' => 20412151,
            ],
            7 => [
                'A' => 2, 'B' => 'Cabang Test', 'C' => 'Jl. Baru 7', 'D' => 'KANTOR KAS', 'E' => 'TELKOM VPN IP', 'F' => 'EKSISTING', 'G' => '0099-NEW', 'H' => 202512,
                'I' => '512 Kbps', 'M' => '2019-05-01', 'S' => 0, 'Y' => 1377297,
            ],
        ];
        foreach ($rows as $row => $cells) {
            foreach ($cells as $letter => $value) {
                $sheet->setCellValue($letter.$row, $value);
            }
        }
        foreach (['I3:K3', 'Y3:AD3'] as $range) {
            $sheet->mergeCells($range);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        $xlsx = ob_get_clean();
        $book->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('Invoice-JAN26.xlsx', $xlsx);
    }

    private function upload(InvoicePeriod $period): ImportBatch
    {
        $this->post(route('billing.imports', $period), ['kind' => 'history', 'file' => $this->workbook()])->assertRedirect();

        return ImportBatch::where('import_type', 'history')->sole();
    }

    public function test_step1_offers_to_fill_the_period_from_the_telkom_workbook(): void
    {
        ['period' => $empty] = $this->fixture(false);

        $this->get(route('invoice-periods.step1', $empty))->assertOk()
            ->assertSee('Belum ada layanan di periode ini')->assertSee('Isi dari workbook Telkom')
            ->assertSee('Untuk bulan yang sudah lewat: daftar SSID diambil dari workbook Telkom bulan itu. Master tidak diubah.')
            ->assertSee('SSID yang tidak ada di file akan dikeluarkan dari periode ini', false)->assertSee('name="kind" value="history"', false);

        InvoiceLine::create(['invoice_period_id' => $empty->id, 'bank_jatim_link_id' => BankJatimLink::first()->id, 'line_number' => 1]);

        $this->get(route('invoice-periods.step1', $empty))->assertOk()
            ->assertSee('Sesuaikan dengan workbook Telkom')->assertDontSee('Belum ada layanan di periode ini');
    }

    public function test_uploading_the_workbook_opens_a_preview_with_sheet_columns_and_differences(): void
    {
        ['period' => $period] = $this->fixture();

        $this->post(route('billing.imports', $period), ['kind' => 'history', 'file' => $this->workbook()])
            ->assertRedirect(route('imports.show', ImportBatch::sole()));
        $batch = ImportBatch::sole();
        $this->assertSame('history', $batch->import_type);
        $this->assertSame('G', $batch->metadata['analysis']['mapping']['sid']);
        $this->assertSame(2, $batch->rows()->count());

        $page = $this->get(route('imports.show', $batch))->assertOk()
            ->assertSee('Workbook Telkom bulan lampau')->assertSee('Sheet & kolom')->assertSee('Sheet JAN26 · 17 dari 17 kolom dikenali')
            ->assertSee('JAN26 · 2 SSID')->assertSee('name="mapping[sid]"', false)->assertSee('name="mapping[address]"', false)
            ->assertSee('<option value="G" selected>G · ID SID JAN 2025</option>', false)
            ->assertSee('— tidak dipakai —')->assertSee('10.0.0.1 /30')
            ->assertSee('2. Perbedaan dengan daftar SSID periode ini')->assertSee('SSID cocok')->assertSee('SSID ditambahkan')->assertSee('SSID dikeluarkan dari periode ini')
            ->assertSee('1 belum ada di Master → disimpan sebagai tidak aktif, tidak ikut bulan berikutnya')
            ->assertSee('EKSISTING · 2', false)->assertSee('PASANG BARU · 0', false)
            ->assertSee('Total harga eksisting Telkom: <strong>Rp 21.789.448</strong>', false)->assertSee('Downtime terisi: <strong>2 SSID</strong>', false)
            ->assertSee('0099-NEW')->assertSee('baru di Master')
            ->assertSee('0034-OLD')->assertSee('1 data perubahan/downtime/penyesuaian SSID ini ikut dihapus')
            ->assertSee('Semua baris siap');
        // Every required column is recognised, so the card is collapsed and Terapkan is usable.
        $html = $page->getContent();
        $this->assertDoesNotMatchRegularExpression('/<details class="card rounded-xl mb-6"\s+open/', $html);
        $this->assertMatchesRegularExpression('/<button class="btn btn-primary"\s*>Terapkan/', $html);
        // Row forms use the Indonesian field names and the lookup dropdowns, not raw keys.
        $page->assertSee('SSID / ID')->assertSee('Tanggal mulai (PSB/Cabut/Upgrade/Downgrade)')->assertSee('Pilih Jenis kantor')->assertSee('Pilih Bandwidth eksisting')
            ->assertDontSee('Pilih Office type')->assertDontSee('name="values[event_type]"', false)->assertDontSee('name="values[addendum_contract_id]"', false);

        // Nothing changed in the period yet.
        $this->assertSame(['0012-ABC', '0034-OLD'], $period->lines()->get()->map->billingSid()->sort()->values()->all());
        $this->assertSame(1, ServiceEvent::count());
    }

    public function test_upload_without_a_ssid_sheet_returns_to_the_page_with_an_error(): void
    {
        ['period' => $period] = $this->fixture();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Catatan');
        ob_start();
        (new Xlsx($book))->save('php://output');
        $xlsx = ob_get_clean();

        $this->post(route('billing.imports', $period), ['kind' => 'history', 'file' => UploadedFile::fake()->createWithContent('Kosong.xlsx', $xlsx)])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_choosing_other_columns_reads_the_rows_again_and_unknown_sheets_are_refused(): void
    {
        ['period' => $period] = $this->fixture();
        $batch = $this->upload($period);
        $row = $batch->rows()->where('sid', '0012-ABC')->sole();
        $this->assertSame('Jl. Test', $row->normalized['address']);
        $mapping = $batch->metadata['analysis']['mapping'];

        // The address now comes from column B ("JENIS KANTOR").
        $this->post(route('imports.mapping', $batch), ['sheet' => 'JAN26', 'mapping' => array_merge($mapping, ['address' => 'b'])])
            ->assertRedirect(route('imports.show', $batch))->assertSessionHas('success', 'Kolom diperbarui. 2 baris dibaca ulang dari sheet JAN26.');
        $this->assertSame(2, $batch->rows()->count());
        $this->assertNull(ImportBatch::sole()->rows()->find($row->id), 'rows are rebuilt');
        $this->assertSame('Cabang Test', $batch->rows()->where('sid', '0012-ABC')->sole()->normalized['address']);
        $this->assertSame('B', $batch->fresh()->metadata['analysis']['mapping']['address']);

        // "— tidak dipakai —" is saved as not mapped; the address goes back to column C because the form sent it so.
        $this->post(route('imports.mapping', $batch), ['sheet' => 'JAN26', 'mapping' => array_merge($mapping, ['ip' => ''])])->assertRedirect();
        $this->assertNull($batch->fresh()->metadata['analysis']['mapping']['ip']);
        $this->assertSame('C', $batch->fresh()->metadata['analysis']['mapping']['address']);

        $before = $batch->rows()->pluck('id')->all();
        $this->post(route('imports.mapping', $batch), ['sheet' => 'TIDAK ADA', 'mapping' => $mapping])->assertSessionHasErrors(['sheet' => 'Sheet tidak ditemukan di file ini.']);
        $this->post(route('imports.mapping', $batch), ['mapping' => $mapping])->assertSessionHasErrors('sheet');
        $this->post(route('imports.mapping', $batch), ['sheet' => 'JAN26', 'mapping' => ['bukan_kolom' => 'A']])->assertSessionHasErrors('mapping');
        $this->post(route('imports.mapping', $batch), ['sheet' => 'JAN26', 'mapping' => ['sid' => 'G1!']])->assertSessionHasErrors('mapping.sid');
        $this->assertSame($before, $batch->rows()->pluck('id')->all(), 'a refused request keeps the rows');
    }

    public function test_apply_is_blocked_and_explained_while_a_required_column_is_not_chosen(): void
    {
        ['period' => $period] = $this->fixture();
        $batch = $this->upload($period);
        $mapping = array_merge($batch->metadata['analysis']['mapping'], ['category' => '', 'service' => '']);

        $this->post(route('imports.mapping', $batch), ['sheet' => 'JAN26', 'mapping' => $mapping])->assertRedirect();

        $page = $this->get(route('imports.show', $batch))->assertOk()->assertSee('Pilih dulu kolom wajib: Kategori lokasi, Layanan')
            ->assertSee('Wajib dipilih')->assertSee('Ada kolom wajib yang belum dipilih')->assertSee('Sheet JAN26 · 15 dari 17 kolom dikenali');
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<button class="btn btn-primary" disabled>Terapkan/', $html);
        $this->assertMatchesRegularExpression('/<details class="card rounded-xl mb-6"\s+open/', $html, 'the column card opens by itself');
        $this->post(route('imports.commit', $batch))->assertSessionHasErrors('import');
        $this->assertSame(2, $period->lines()->count());
    }

    public function test_apply_makes_the_period_match_the_file_and_leaves_the_master_alone(): void
    {
        ['period' => $period, 'old' => $old] = $this->fixture();
        $batch = $this->upload($period);

        $this->post(route('imports.commit', $batch))->assertRedirect(route('invoice-periods.step1', $period));

        $this->assertSame(['0012-ABC', '0099-NEW'], $period->lines()->get()->map->billingSid()->sort()->values()->all());
        $this->assertSame(0, ServiceEvent::count(), 'inputs of the removed SSID are removed with it');
        $this->assertSame('inactive', BankJatimLink::where('ssid', '0099-NEW')->sole()->link_status);
        $this->assertSame('active', $old->fresh()->link_status, 'the master keeps the SSID that left the period');
        $this->assertSame(1, BankJatimLink::where('ssid', '0034-OLD')->count());

        $this->get(route('invoice-periods.step1', $period))->assertOk()->assertSee('0099-NEW')->assertDontSee('0034-OLD');

        $this->get(route('imports.show', $batch))->assertOk()->assertSee('Sudah diterapkan')
            ->assertSee('Daftar SSID dari file ini sudah diterapkan. Untuk koreksi, upload workbook yang sudah diperbaiki.')
            ->assertDontSee('Tarik kembali data dari file ini')->assertDontSee('Baca ulang dengan pilihan ini')->assertDontSee('name="mapping[sid]"', false);
        $this->post(route('imports.discard', $batch), ['reason' => 'Salah file'])->assertStatus(422);
        $this->post(route('imports.mapping', $batch), ['sheet' => 'JAN26', 'mapping' => $batch->metadata['analysis']['mapping']])->assertStatus(423);
    }

    public function test_menu_lanjutan_names_the_new_kind(): void
    {
        ['period' => $period] = $this->fixture();
        $this->upload($period);

        $this->get(route('invoice-periods.advanced', $period))->assertOk()->assertSee('Workbook Telkom bulan lampau');
    }
}
