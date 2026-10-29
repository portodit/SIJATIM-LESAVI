<?php

namespace Tests\Feature;

use App\Http\Controllers\BillingWorkflowController;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\ImportBatch;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\PeriodInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Price differences with the Telkom workbook: the calculator explains each one (detail), step 2 lists
 * them per SSID, and the operator settles them with one click (price-decisions) followed by a recalculation.
 *
 * JAN26 invoice (usage December 2025). Tariffs, all "Kantor Kas" / TELKOM VPN IP, no router unless noted:
 * 32 Kbps 1.300.000 (managed 1.950.000), 512 Kbps 2.319.819, 1 Mbps 3.000.000.
 */
class PriceDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    private InvoicePeriod $period;

    private OfficeType $office;

    private LocationCategory $kas;

    private Service $vpn;

    /** @var array<string, BandwidthOption> */
    private array $bw = [];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->office = OfficeType::create(['office_type_code' => 'sby', 'office_type_name' => 'KANTOR CABANG SURABAYA', 'sort_order' => 1]);
        $this->kas = LocationCategory::create(['location_category_code' => 'kk', 'location_category_name' => 'KANTOR KAS']);
        $this->vpn = Service::create(['service_code' => 'vpn', 'service_name' => 'TELKOM VPN IP']);
        foreach (['32' => ['32 Kbps', 32], '64' => ['64 Kbps', 64], '512' => ['512 Kbps', 512], '1m' => ['1 Mbps', 1024]] as $code => [$name, $kbps]) {
            $this->bw[$code] = BandwidthOption::create(['bandwidth_code' => $code, 'bandwidth_name' => $name, 'value_kbps' => $kbps]);
        }
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $this->tariff('32', '1300000');
        $this->tariff('32', '1950000', 'managed');
        $this->tariff('512', '2319819');
        $this->tariff('1m', '3000000');
        $this->period = InvoicePeriod::create(['month' => 1, 'year' => 2026, 'created_by' => auth()->id()]);
    }

    // ---------------------------------------------------------------- calculator detail

    public function test_detail_points_at_another_bandwidth_and_the_master_bandwidth_settles_it(): void
    {
        // The workbook carried an old 32 Kbps, the master says 512 Kbps today; Telkom bills the 512 Kbps price.
        $this->addService('4700020-37118', '32', '2319819.00', '512', ['source' => ['batch_id' => $this->batch()->id, 'sheet' => 'Existing', 'row' => 12]]);
        $this->calculate();

        $issue = $this->issue('tariff_discrepancy', '4700020-37118');
        $this->assertSame('blocking', $issue['severity']);
        $detail = $issue['detail'];
        $this->assertSame('2319819.00', $detail['reference']);
        $this->assertSame('1300000.00', $detail['system']);
        $this->assertSame('1300000.00', $detail['monthly']);
        $this->assertSame('1019819.00', $detail['difference'], 'Positive when Telkom is higher.');
        $this->assertSame('bandwidth', $detail['cause']);
        $this->assertSame('32 Kbps', $detail['bandwidth']);
        $this->assertSame('none', $detail['router']);
        $this->assertSame([['bandwidth' => '512 Kbps', 'bandwidth_option_id' => $this->bw['512']->id, 'router' => 'none', 'category' => 'KANTOR KAS']], $detail['matches']);
        $this->assertSame('512 Kbps', $detail['master_bandwidth']);
        $this->assertSame($this->bw['512']->id, $detail['master_bandwidth_option_id']);
        $this->assertTrue($detail['master_matches']);
        $this->assertStringContainsString('Harga Telkom Rp 2.319.819 sama dengan tarif 512 Kbps tanpa router.', $detail['explanation']);
        $this->assertStringContainsString('Sistem memakai tarif 32 Kbps tanpa router (Rp 1.300.000).', $detail['explanation']);
        $this->assertStringContainsString('Di Master saat ini SSID ini 512 Kbps, cocok dengan harga Telkom.', $detail['explanation']);
        $this->assertSame(['file' => 'Invoice Des 2025.xlsx', 'sheet' => 'Existing', 'row' => 12], $detail['source']);
    }

    public function test_detail_when_the_master_bandwidth_differs_but_does_not_match(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '1m');
        $this->calculate();

        $detail = $this->issue('tariff_discrepancy', '4700020-1')['detail'];
        $this->assertSame('bandwidth', $detail['cause']);
        $this->assertSame('1 Mbps', $detail['master_bandwidth']);
        $this->assertFalse($detail['master_matches']);
        $this->assertStringEndsWith('Di Master saat ini SSID ini 1 Mbps.', $detail['explanation']);
    }

    public function test_detail_blames_the_router_when_the_telkom_price_is_the_managed_router_tariff(): void
    {
        $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();

        $detail = $this->issue('tariff_discrepancy', '4700020-2')['detail'];
        $this->assertSame('router', $detail['cause']);
        $this->assertSame('650000.00', $detail['difference']);
        $this->assertSame([['bandwidth' => '32 Kbps', 'bandwidth_option_id' => $this->bw['32']->id, 'router' => 'managed', 'category' => 'KANTOR KAS']], $detail['matches']);
        $this->assertNull($detail['master_bandwidth']);
        $this->assertFalse($detail['master_matches']);
        $this->assertStringContainsString('sama dengan tarif 32 Kbps dengan managed router', $detail['explanation']);
        $this->assertStringContainsString('Periksa apakah SSID ini memakai router.', $detail['explanation']);
    }

    public function test_detail_without_any_matching_tariff_has_a_negative_difference_when_telkom_is_lower(): void
    {
        $this->addService('4700020-3', '32', '1000000.00');
        $this->calculate();

        $detail = $this->issue('tariff_discrepancy', '4700020-3')['detail'];
        $this->assertSame('no_tariff', $detail['cause']);
        $this->assertSame('-300000.00', $detail['difference']);
        $this->assertSame([], $detail['matches']);
        $this->assertStringContainsString('Tidak ada tarif sebesar Rp 1.000.000 untuk TELKOM VPN IP · KANTOR KAS di Master Harga.', $detail['explanation']);
    }

    public function test_missing_tariff_names_the_combination_to_add(): void
    {
        $this->addService('4700020-4', '64', null);
        $this->calculate();

        $issue = $this->issue('tariff', '4700020-4');
        $this->assertSame('Belum ada tarif untuk TELKOM VPN IP · KANTOR KAS · 64 Kbps · tanpa router yang berlaku pada 01/12/2025. Tambahkan di Master Harga › Tarif.', $issue['detail']['explanation']);
        $this->assertSame('64 Kbps', $issue['detail']['bandwidth']);
    }

    public function test_an_empty_telkom_price_is_only_a_note(): void
    {
        $this->addService('4700020-5', '32', null, null, ['reference_missing' => true]);
        $this->calculate();

        $issue = $this->issue('reference_missing', '4700020-5');
        $this->assertSame('comparison', $issue['severity']);
        $this->assertArrayNotHasKey('detail', $issue);
        $this->assertNull($this->issue('tariff_discrepancy', '4700020-5'));
        $this->assertSame('1300000.00', $this->calculatedLine('4700020-5')['gross']);
    }

    // ---------------------------------------------------------------- step 2 page

    public function test_step_two_lists_every_price_problem_per_ssid_with_the_three_choices(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512', ['source' => ['batch_id' => $this->batch()->id, 'sheet' => 'Existing', 'row' => 12]]);
        $this->addService('4700020-3', '32', '1000000.00');
        $this->calculate();

        $html = $this->get(route('invoice-periods.step2', $this->period))->assertOk()
            ->assertSee('Harga berbeda dengan file Telkom')
            ->assertSee('Harga di workbook Telkom tidak sama dengan tarif sistem. Lihat rinciannya di bawah, lalu pilih harga yang dipakai untuk bulan ini.')
            ->assertSee('Lihat rincian 2 SSID')
            ->assertSee('4700020-37118')->assertSee('Cabang KANTOR CABANG SURABAYA', false)->assertSee('Jl. Pahlawan 4700020-37118')
            ->assertSee('KANTOR KAS · tanpa router')
            ->assertSee('Rp 2.319.819')->assertSee('Rp 1.300.000')
            ->assertSee('+Rp 1.019.819')->assertSee('−Rp 300.000')
            ->assertSee('Harga Telkom Rp 2.319.819 sama dengan tarif 512 Kbps tanpa router.')
            ->assertSee('Sumber: Invoice Des 2025.xlsx · Existing · baris 12')
            ->assertSee('Pakai harga Telkom (Rp 2.319.819)')->assertSee('Pakai harga Telkom (Rp 1.000.000)')
            ->assertSee('Ganti bandwidth bulan ini ke 512 Kbps (sesuai Master)')
            ->assertSee('Tetap harga sistem (Rp 1.300.000)')
            ->assertSee('Pilih semua: pakai harga Telkom')->assertSee('Pilih semua: tetap harga sistem')
            ->assertSee('Terapkan pilihan &amp; hitung ulang', false)
            ->assertSee('action="'.route('billing.price-decisions', $this->period).'"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Ganti bandwidth bulan ini'), 'The SSID without a target bandwidth only gets two choices.');
        $this->assertSame(2, substr_count($html, 'value="system"'));
        $this->assertSame(0, preg_match('/<input[^>]*checked/', $html), 'Nothing is preselected.');
        $this->assertStringContainsString('<details class="mt-4" open>', $html);
    }

    public function test_a_long_list_is_collapsed(): void
    {
        foreach (range(1, 16) as $n) {
            $this->addService('4700020-9'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), '32', '2319819.00');
        }
        $this->calculate();

        $html = $this->get(route('invoice-periods.step2', $this->period))->assertOk()->assertSee('Lihat rincian 16 SSID')->getContent();
        $this->assertStringContainsString('<details class="mt-4" >', $html);
        $this->assertStringNotContainsString('<details class="mt-4" open>', $html);
    }

    public function test_step_two_shows_a_missing_tariff_and_an_empty_telkom_price(): void
    {
        $this->addService('4700020-4', '64', null);
        $this->addService('4700020-5', '32', null, null, ['reference_missing' => true]);
        $this->calculate();

        $this->get(route('invoice-periods.step2', $this->period))->assertOk()
            ->assertSee('Harga belum ditemukan')
            ->assertSee('Belum ada tarif untuk kombinasi di bawah. Tambahkan di Master Harga › Tarif.')
            ->assertSee('Belum ada tarif untuk TELKOM VPN IP · KANTOR KAS · 64 Kbps · tanpa router yang berlaku pada 01/12/2025.')
            ->assertSee('Buka Master Harga')
            ->assertSee('Harga Telkom kosong di file')
            ->assertSee('Hanya catatan: SSID ini tidak punya harga eksisting di workbook Telkom, jadi tidak dibandingkan. Harga sistem dipakai.')
            ->assertSee('4700020-5')
            ->assertDontSee('price-decisions');
    }

    public function test_step_two_is_read_only_when_the_calculation_is_old_and_works_without_one(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512');
        $this->get(route('invoice-periods.step2', $this->period))->assertOk()->assertSee('Invoice belum dihitung')->assertDontSee('Harga berbeda dengan file Telkom');

        $this->calculate();
        $this->get(route('invoice-periods.step2', $this->period))->assertOk()->assertSee('price-decisions', false);

        // Locked: the table stays, the form goes.
        DB::table('invoice_periods')->where('id', $this->period->id)->update(['is_locked' => true]);
        $this->get(route('invoice-periods.step2', $this->period))->assertOk()
            ->assertSee('Harga berbeda dengan file Telkom')->assertSee('+Rp 1.019.819')->assertSee('Periode sudah terkunci, pilihan tidak bisa diubah.')
            ->assertDontSee('price-decisions', false)->assertDontSee('Pakai harga Telkom (');
    }

    // ---------------------------------------------------------------- settling

    public function test_using_the_telkom_price_creates_an_override_and_recalculates(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512', ['source' => ['batch_id' => $this->batch()->id, 'sheet' => 'Existing', 'row' => 12]]);
        $this->calculate();
        $version = $this->period->fresh()->input_version;

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-37118' => 'source'], 'note' => 'Sudah dikonfirmasi Telkom'])
            ->assertRedirect(route('invoice-periods.step2', $this->period))
            ->assertSessionHas('success', '1 SSID diselesaikan dan invoice dihitung ulang. Override harga bisa dihapus di Menu Lanjutan bila keliru.');

        $override = InvoiceAdjustment::sole();
        $this->assertSame($this->period->id, $override->invoice_period_id);
        $this->assertSame('4700020-37118', $override->sid);
        $this->assertSame('tariff_override', $override->kind);
        $this->assertSame('2319819.00', $override->amount);
        $this->assertSame('Disamakan dengan harga workbook Telkom Rp 2.319.819 (file Invoice Des 2025.xlsx, baris 12); tarif sistem Rp 1.300.000. Catatan: Sudah dikonfirmasi Telkom', $override->reason);
        $this->assertSame('Invoice Des 2025.xlsx · Existing · baris 12', $override->reference);
        $this->assertSame('source', $override->attributes['decision']);
        $this->assertSame('2319819.00', $override->attributes['detail']['reference']);
        $this->assertSame(auth()->id(), $override->created_by);

        $period = $this->period->fresh();
        $this->assertSame($version + 1, $period->input_version);
        $this->assertTrue($period->calculationIsCurrent(), 'The invoice is recalculated at once.');
        $this->assertNull($this->issue('tariff_discrepancy', '4700020-37118'));
        $this->assertSame('2319819.00', $this->calculatedLine('4700020-37118')['gross']);
        $this->assertSame(1, DB::table('billing_audit_events')->where('invoice_period_id', $period->id)->where('action', 'price_decisions')->count());
    }

    public function test_the_override_reason_stays_readable_without_a_file_name(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512', ['source' => ['row' => 7]]);
        $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'source', '4700020-2' => 'source']])->assertRedirect();

        $overrides = InvoiceAdjustment::orderBy('sid')->get();
        $this->assertSame('Disamakan dengan harga workbook Telkom Rp 2.319.819 (baris 7); tarif sistem Rp 1.300.000.', $overrides[0]->reason);
        $this->assertSame('baris 7', $overrides[0]->reference);
        $this->assertSame('Disamakan dengan harga workbook Telkom Rp 1.950.000; tarif sistem Rp 1.300.000.', $overrides[1]->reason);
        $this->assertSame('Workbook Telkom', $overrides[1]->reference);
    }

    public function test_keeping_the_system_price_creates_an_override_with_the_system_tariff(): void
    {
        $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-2' => 'system']])->assertRedirect(route('invoice-periods.step2', $this->period));

        $override = InvoiceAdjustment::sole();
        $this->assertSame('tariff_override', $override->kind);
        $this->assertSame('1300000.00', $override->amount);
        $this->assertSame('Tetap memakai tarif sistem Rp 1.300.000; harga Telkom Rp 1.950.000 tidak dipakai.', $override->reason);
        $this->assertSame('Workbook Telkom', $override->reference);
        $this->assertSame('system', $override->attributes['decision']);
        $this->assertNull($this->issue('tariff_discrepancy', '4700020-2'));
        $this->assertSame('1300000.00', $this->calculatedLine('4700020-2')['gross']);
    }

    public function test_changing_the_bandwidth_only_changes_this_periods_line(): void
    {
        // The master says 512 Kbps (the Telkom price), the workbook line says 32 Kbps.
        $line = $this->addService('4700020-37118', '32', '2319819.00', '512');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-37118' => 'bandwidth']])->assertRedirect(route('invoice-periods.step2', $this->period));

        $line = $line->fresh();
        $this->assertSame($this->bw['512']->id, $line->service_snapshot['bandwidth_option_id']);
        $this->assertSame($this->bw['512']->id, $line->existing_bandwidth_option_id);
        $this->assertSame('2319819.00', $line->service_snapshot['reference_price'], 'The Telkom price stays on the line.');
        $this->assertSame(0, InvoiceAdjustment::count());
        $this->assertNull($this->issue('tariff_discrepancy', '4700020-37118'));
        $this->assertSame('2319819.00', $this->calculatedLine('4700020-37118')['gross']);
        $this->assertSame('512 Kbps', $this->calculatedLine('4700020-37118')['bandwidth']);
    }

    public function test_changing_the_bandwidth_leaves_a_different_master_bandwidth_alone(): void
    {
        // Master is 1 Mbps; only the 512 Kbps tariff equals the Telkom price.
        $line = $this->addService('4700020-1', '32', '2319819.00', '1m');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'bandwidth']])->assertRedirect();

        $this->assertSame($this->bw['512']->id, $line->fresh()->existing_bandwidth_option_id);
        $this->assertSame($this->bw['1m']->id, BankJatimLink::where('ssid', '4700020-1')->sole()->current_bandwidth_option_id, 'The master is untouched.');
        $this->assertSame(0, InvoiceAdjustment::count());
        $this->assertSame('2319819.00', $this->calculatedLine('4700020-1')['gross']);
    }

    public function test_several_ssids_are_settled_in_one_go(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->addService('4700020-2', '32', '1950000.00');
        $this->addService('4700020-3', '32', '1000000.00');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'bandwidth', '4700020-2' => 'source', '4700020-3' => 'system']])
            ->assertSessionHas('success', fn ($message) => str_starts_with($message, '3 SSID diselesaikan'));

        $this->assertSame(['4700020-2' => '1950000.00', '4700020-3' => '1300000.00'], InvoiceAdjustment::orderBy('sid')->pluck('amount', 'sid')->all());
        $this->assertSame([], array_filter($this->period->fresh()->calculation['issues'], fn ($i) => $i['code'] === 'tariff_discrepancy'));
        $this->assertSame(1, DB::table('billing_audit_events')->where('action', 'price_decisions')->count(), 'One change record for the whole batch.');
    }

    public function test_only_the_chosen_ssids_are_settled(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-2' => 'source']])->assertRedirect();

        $this->assertNull($this->issue('tariff_discrepancy', '4700020-2'));
        $this->assertNotNull($this->issue('tariff_discrepancy', '4700020-1'));
    }

    public function test_amounts_in_the_request_are_ignored(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->calculate();

        $this->post(route('billing.price-decisions', $this->period), [
            'decisions' => ['4700020-1' => 'source'], 'amount' => '1', 'reference' => '1', 'amounts' => ['4700020-1' => '1'], 'kind' => 'manual_credit', 'sid' => 'lain', 'detail' => ['reference' => '1.00'],
        ])->assertRedirect();

        $override = InvoiceAdjustment::sole();
        $this->assertSame('tariff_override', $override->kind);
        $this->assertSame('4700020-1', $override->sid);
        $this->assertSame('2319819.00', $override->amount);
        $this->assertSame('2319819.00', $this->calculatedLine('4700020-1')['gross']);
    }

    public function test_an_ssid_without_a_price_difference_is_refused(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->addService('4700020-6', '32', '1300000.00');
        $this->calculate();

        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-6' => 'source']])->assertStatus(422);
        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['9999999-9' => 'system']])->assertStatus(422);
        // One bad SID stops the whole batch: the good one is not applied either.
        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'source', '4700020-6' => 'source']])->assertStatus(422);

        $this->assertSame(0, InvoiceAdjustment::count());
        $this->assertTrue($this->period->fresh()->calculationIsCurrent(), 'Nothing changed, so the calculation is still current.');
    }

    public function test_a_bandwidth_choice_needs_a_target_bandwidth(): void
    {
        // The Telkom price is the managed-router tariff of the same bandwidth: there is no other bandwidth to move to.
        $line = $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();

        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-2' => 'bandwidth']])
            ->assertStatus(422)->assertJsonPath('message', 'Pilih harga Telkom atau harga sistem untuk SSID 4700020-2.');

        $this->assertSame($this->bw['32']->id, $line->fresh()->existing_bandwidth_option_id);
        $this->assertSame(0, InvoiceAdjustment::count());
    }

    public function test_an_unknown_choice_or_an_empty_form_is_refused(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->calculate();

        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'gratis']])->assertStatus(422);
        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => []])->assertStatus(422)->assertJsonValidationErrors('decisions');
        $this->postJson(route('billing.price-decisions', $this->period), [])->assertStatus(422)->assertJsonPath('errors.decisions.0', 'Pilih harga untuk minimal satu SSID.');
        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'source'], 'note' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors('note');

        // From the form an empty choice goes back to step 2 with a message.
        $this->from(route('invoice-periods.step2', $this->period))->post(route('billing.price-decisions', $this->period), ['note' => 'x'])
            ->assertRedirect(route('invoice-periods.step2', $this->period))->assertSessionHasErrors('decisions');
        $this->assertSame(0, InvoiceAdjustment::count());
    }

    public function test_an_old_calculation_is_refused(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->calculate();
        $this->period->fresh()->markInputsChanged('test_change');

        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'source']])
            ->assertStatus(409)->assertJsonPath('message', 'Data berubah sejak halaman dibuka. Hitung ulang dulu.');
        $this->assertSame(0, InvoiceAdjustment::count());
    }

    public function test_a_locked_period_is_refused(): void
    {
        $this->addService('4700020-1', '32', '2319819.00', '512');
        $this->calculate();
        DB::table('invoice_periods')->where('id', $this->period->id)->update(['is_locked' => true]);

        $this->postJson(route('billing.price-decisions', $this->period), ['decisions' => ['4700020-1' => 'source']])->assertStatus(423);
        $this->assertSame(0, InvoiceAdjustment::count());
    }

    // ---------------------------------------------------------------- which bandwidth can settle a difference

    public function test_target_bandwidth_rules(): void
    {
        $match = fn (int $id, string $name, string $router = 'none') => ['bandwidth' => $name, 'bandwidth_option_id' => $id, 'router' => $router, 'category' => 'KANTOR KAS'];
        $base = ['bandwidth' => '32 Kbps', 'router' => 'none', 'master_matches' => false, 'master_bandwidth_option_id' => null];

        // One other bandwidth at the same router setting.
        $this->assertSame(['id' => 5, 'name' => '512 Kbps', 'master' => false], BillingWorkflowController::priceTargetBandwidth($base + ['matches' => [$match(5, '512 Kbps')]]));
        // The same bandwidth listed under two categories is still one bandwidth.
        $this->assertSame(5, BillingWorkflowController::priceTargetBandwidth($base + ['matches' => [$match(5, '512 Kbps'), $match(5, '512 Kbps')]])['id']);
        // Several bandwidths: none, unless the master bandwidth is one of them.
        $two = [$match(5, '512 Kbps'), $match(6, '1 Mbps')];
        $this->assertNull(BillingWorkflowController::priceTargetBandwidth($base + ['matches' => $two]));
        $this->assertSame(['id' => 6, 'name' => '1 Mbps', 'master' => true], BillingWorkflowController::priceTargetBandwidth(['master_matches' => true, 'master_bandwidth_option_id' => 6, 'master_bandwidth' => '1 Mbps'] + $base + ['matches' => $two]));
        // Another router setting, the bandwidth the system already uses, or a tariff without bandwidth: nothing to switch to.
        $this->assertNull(BillingWorkflowController::priceTargetBandwidth($base + ['matches' => [$match(5, '512 Kbps', 'managed')]]));
        $this->assertNull(BillingWorkflowController::priceTargetBandwidth($base + ['matches' => [$match(1, '32 Kbps', 'none')]]));
        $this->assertNull(BillingWorkflowController::priceTargetBandwidth($base + ['matches' => [['bandwidth' => 'Tidak berlaku', 'bandwidth_option_id' => null, 'router' => 'none', 'category' => 'Semua kategori']]]));
        $this->assertNull(BillingWorkflowController::priceTargetBandwidth($base + ['matches' => []]));
        // A master match at another router setting cannot settle it.
        $this->assertNull(BillingWorkflowController::priceTargetBandwidth(['master_matches' => true, 'master_bandwidth_option_id' => 5, 'master_bandwidth' => '512 Kbps'] + $base + ['matches' => [$match(5, '512 Kbps', 'managed')]]));
    }

    // ---------------------------------------------------------------- helpers

    private function tariff(string $bandwidth, string $monthly, string $router = 'none'): PriceMaster
    {
        return PriceMaster::create([
            'location_category_id' => $this->kas->id, 'service_id' => $this->vpn->id, 'bandwidth_option_id' => $this->bw[$bandwidth]->id, 'router_mode' => $router,
            'monthly_price' => $monthly, 'installation_price' => '500000', 'relocation_price' => '0', 'effective_start_date' => '2020-01-01', 'is_active' => true,
        ]);
    }

    /**
     * A master service in the period whose line carries the workbook bandwidth and Telkom price.
     *
     * @param  string|null  $reference  the Telkom price ("existing price" cell), null for an empty cell
     * @param  string|null  $masterBw  bandwidth of the master today when it differs from the line's
     */
    private function addService(string $sid, string $lineBw, ?string $reference, ?string $masterBw = null, array $snapshot = []): InvoiceLine
    {
        $link = BankJatimLink::create([
            'ssid' => $sid, 'office_type_id' => $this->office->id, 'sequence_in_office_type' => ++$this->sequence, 'location_category_id' => $this->kas->id, 'service_id' => $this->vpn->id,
            'current_bandwidth_option_id' => $this->bw[$masterBw ?? $lineBw]->id, 'address' => 'Jl. Pahlawan '.$sid, 'service_start_date' => '2020-01-01', 'link_status' => 'active',
        ]);
        $line = PeriodInventory::add($this->period, $link);
        $line->update([
            'existing_bandwidth_option_id' => $this->bw[$lineBw]->id,
            'service_snapshot' => array_merge($line->service_snapshot, ['bandwidth_option_id' => $this->bw[$lineBw]->id, 'reference_price' => $reference], $snapshot),
        ]);

        return $line->fresh();
    }

    private function batch(): ImportBatch
    {
        return ImportBatch::create(['invoice_period_id' => $this->period->id, 'import_type' => 'history', 'file_name' => 'upload.xlsx', 'original_filename' => 'Invoice Des 2025.xlsx', 'status' => 'completed']);
    }

    private function calculate(): array
    {
        return (new InvoiceCalculator)->calculate($this->period);
    }

    private function issue(string $code, string $sid): ?array
    {
        return collect($this->period->fresh()->calculation['issues'])->first(fn ($i) => $i['code'] === $code && $i['sid'] === $sid);
    }

    private function calculatedLine(string $sid): array
    {
        return collect($this->period->fresh()->calculation['lines'])->firstWhere('sid', $sid);
    }
}
