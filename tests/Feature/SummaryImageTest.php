<?php

namespace Tests\Feature;

use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoiceOutput;
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
use App\Services\Billing\SummaryImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SummaryImageTest extends TestCase
{
    use RefreshDatabase;

    /** Snapshot shaped like the one of AGU26 (775 services, 2 below SLA). */
    private function snapshot(): array
    {
        return [
            'period' => ['name' => 'Agustus 2026', 'code' => 'AGU26'],
            'totals' => [
                'order_counts' => ['existing' => 774, 'relokasi' => 1],
                'components' => ['existing' => '1817765242.00', 'upgrade' => '0.00', 'downgrade' => '0.00', 'termination' => '0.00', 'installation' => '0.00', 'relocation' => '0.00'],
                'gross' => '1817765242.00', 'sla_credit' => '27341.59', 'manual_credit' => '0.00', 'below_sla_count' => 2,
                'subtotal' => '1817737900', 'tax' => '199951169', 'grand_total' => '2017689069',
            ],
            'lines' => array_merge(array_fill(0, 773, ['sla' => '1.00000000']), array_fill(0, 2, ['sla' => '0.98000000'])),
        ];
    }

    public function test_rows_follow_the_telkom_summary_layout(): void
    {
        $data = SummaryImage::rows($this->snapshot());
        $this->assertSame('INVOICE PERIODE  AGUSTUS 2026', $data['title']);
        $this->assertSame('*TELKOM - MONTHLY DATA SUMMARY', $data['subtitle']);
        $by = collect($data['rows'])->keyBy('label');

        $this->assertSame(['774', 'Rp1.817.765.242'], [$by['Total Layanan Eksisting']['count'], $by['Total Layanan Eksisting']['value']]);
        $this->assertSame(['1', 'Rp0'], [$by['Total Layanan Relokasi']['count'], $by['Total Layanan Relokasi']['value']]);
        $this->assertSame(['0', 'Rp0'], [$by['Total Layanan Baru (PSB)']['count'], $by['Total Layanan Baru (PSB)']['value']]);
        $this->assertSame(['775', 'Rp1.817.765.242'], [$by['Total Nilai Tagihan']['count'], $by['Total Nilai Tagihan']['value']]);
        $this->assertSame('773', $by['G. Total Titik Lokasi SLA Yang Terpenuhi']['value']);
        $this->assertSame('2', $by['H. Total Titik Lokasi SLA Yang Tidak Terpenuhi']['value']);
        $this->assertSame('-Rp27.342', $by['I.  Total Nilai Restitusi SLA Yang Tidak Terpenuhi']['value'], 'Credits are shown negative, rounded half up.');
        $this->assertSame('Rp0', $by['J. Total Nilai Restitusi Tagihan']['value'], 'Zero is never shown as -Rp0.');
        $this->assertSame('Rp1.817.737.900', $by['Total Nilai Tagihan Setelah Restitusi']['value']);
        $this->assertSame('Rp199.951.169', $by['PPN']['value']);
        $this->assertSame('Rp2.017.689.069', $by['Grand Total Nilai Tagihan']['value']);
        $this->assertSame(21, count($data['rows']));

        $this->assertSame('Rp1.234', SummaryImage::rupiah('1233.50'));
        $this->assertSame('-Rp2', SummaryImage::rupiah('1.5', true));
        $this->assertSame('—', SummaryImage::rupiah(null));
    }

    public function test_unknown_sla_is_not_counted_as_met_and_manual_credit_is_negative(): void
    {
        $s = $this->snapshot();
        $s['lines'] = [['sla' => '1.0'], ['sla' => null], ['sla' => '0.97']];
        $s['totals']['below_sla_count'] = 1;
        $s['totals']['manual_credit'] = '5000.00';
        $by = collect(SummaryImage::rows($s)['rows'])->keyBy('label');
        $this->assertSame('1', $by['G. Total Titik Lokasi SLA Yang Terpenuhi']['value']);
        $this->assertSame('-Rp5.000', $by['J. Total Nilai Restitusi Tagihan']['value']);
    }

    public function test_renders_a_valid_png_with_telkom_red_bands(): void
    {
        $path = sys_get_temp_dir().'/summary-'.bin2hex(random_bytes(4)).'.png';
        (new SummaryImage)->render($this->snapshot(), $path);
        try {
            [$width, $height, $type] = getimagesize($path);
            $this->assertSame(IMAGETYPE_PNG, $type);
            $this->assertSame([1416, 1020], [$width, $height]);
            $im = imagecreatefrompng($path);
            $rgb = fn (int $x, int $y) => array_values(imagecolorsforindex($im, imagecolorat($im, $x, $y)));
            $this->assertSame([255, 255, 255, 0], $rgb(5, 5), 'White page background.');
            $this->assertSame([255, 0, 0, 0], $rgb(900, 227), 'Band "A. LAYANAN EKSISTING" is red across the table.');
            $this->assertSame([255, 255, 255, 0], $rgb(1385, 270), 'The value cell of an item row is white.');
            $this->assertSame([255, 0, 0, 0], $rgb(400, 1000), 'The grand total label is on red.');
            $this->assertSame([255, 255, 255, 0], $rgb(900, 1000), 'The cell next to the grand total label is white.');
            imagedestroy($im);
        } finally {
            @unlink($path);
        }
    }

    private function issuedRevision(): InvoiceRevision
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
        app(InvoiceCalculator::class)->calculate($period->fresh());
        $revision = app(InvoiceFinalizer::class)->finalize($period->fresh(), $period->fresh()->input_version, auth()->id());
        (new GenerateInvoiceDocuments($revision->id))->handle(app(InvoiceDocumentGenerator::class));

        return $revision->fresh();
    }

    public function test_new_versions_get_the_picture_as_a_third_download_and_it_is_shown_on_the_publish_page(): void
    {
        $revision = $this->issuedRevision();
        $this->assertSame('ready', $revision->status);
        $png = $revision->outputs()->where('output_type', 'summary_png')->sole();
        $this->assertSame('Ringkasan_SEP26_v1.png', $png->file_name);
        $this->assertSame(IMAGETYPE_PNG, getimagesize(Storage::disk('local')->path($png->file_path))[2]);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($png->file_path)), $png->file_hash);

        $this->get(route('billing.download', $png))->assertOk()->assertHeader('content-type', 'image/png');
        $this->get(route('invoice-periods.step3', $revision->invoicePeriod))->assertOk()
            ->assertSee('Unduh Ringkasan (PNG)')->assertSee('Unduh Excel')->assertSee('Unduh PDF')
            ->assertSee('<img src="'.route('billing.download', $png).'" alt="Ringkasan invoice', false)->assertDontSee('Buat gambar ringkasan');
        $this->get(route('dashboard'))->assertOk()->assertSee('Unduh Ringkasan (PNG)');
        $this->get(route('invoices.show', $revision->invoice_id))->assertOk()->assertSee('Unduh Ringkasan (PNG)');
    }

    public function test_a_version_issued_before_the_feature_can_get_its_picture_once(): void
    {
        $revision = $this->issuedRevision();
        $revision->outputs()->where('output_type', 'summary_png')->delete();
        Storage::disk('local')->delete('billing/invoices/'.$revision->invoice_id.'/v1/summary.png');
        $period = $revision->invoicePeriod;
        $this->get(route('invoice-periods.step3', $period))->assertOk()->assertSee('Buat gambar ringkasan (PNG)')->assertDontSee('alt="Ringkasan invoice', false);

        $this->post(route('billing.summary-image', $revision))->assertRedirect()->assertSessionHas('success');
        $png = $revision->outputs()->where('output_type', 'summary_png')->sole();
        $this->assertTrue(Storage::disk('local')->exists($png->file_path));
        $this->get(route('invoice-periods.step3', $period))->assertOk()->assertSee('alt="Ringkasan invoice', false)->assertDontSee('Buat gambar ringkasan');

        // Asking again changes nothing; the issued Excel/PDF and their hashes stay as they were.
        $hash = $png->file_hash;
        $excelHash = $revision->outputs()->where('output_type', 'excel_invoice')->value('file_hash');
        $this->post(route('billing.summary-image', $revision))->assertRedirect();
        $this->assertSame(1, $revision->outputs()->where('output_type', 'summary_png')->count());
        $this->assertSame($hash, $revision->outputs()->where('output_type', 'summary_png')->value('file_hash'));
        $this->assertSame($excelHash, $revision->outputs()->where('output_type', 'excel_invoice')->value('file_hash'));
        $this->assertSame(3, InvoiceOutput::where('invoice_revision_id', $revision->id)->count());
    }

    public function test_the_picture_is_refused_for_a_version_whose_files_are_not_ready(): void
    {
        $revision = $this->issuedRevision();
        $revision->update(['status' => 'approved']);
        $this->post(route('billing.summary-image', $revision))->assertStatus(422);
    }
}
