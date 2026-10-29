<?php

namespace Tests\Feature;

use App\Http\Controllers\PriceMasterController;
use App\Jobs\GenerateInvoiceDocuments;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\BillingProfile;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\InvoiceSummary;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\BillingMath;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\InvoiceDocumentGenerator;
use App\Services\Billing\InvoiceFinalizer;
use App\Services\Billing\TaxRate;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TaxRateSettingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An issued invoice for August usage (SEP26 is the draft), both billing the same master service.
     */
    private function fixture(): array
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $master = BankJatimLink::create(['ssid' => '0012-ABC', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
        PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        $period = function (int $month) use ($master, $bw) {
            $p = InvoicePeriod::create(['month' => $month, 'year' => 2026, 'created_by' => auth()->id(), 'sla_coverage' => ['sids' => [$master->ssid]]]);
            InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address, 'traffic_exception_reason' => 'MRTG belum tersedia dari penyedia.']);

            return $p;
        };

        Queue::fake();
        Storage::fake('local');
        $issued = $period(8);
        app(InvoiceCalculator::class)->calculate($issued);
        $revision = app(InvoiceFinalizer::class)->finalize($issued->refresh(), $issued->input_version, auth()->id());
        $draft = $period(9);
        app(InvoiceCalculator::class)->calculate($draft);

        return [$draft->refresh(), $issued->refresh(), $revision];
    }

    private function tax(string $subtotal, string $rate): string
    {
        return BillingMath::round((string) BigDecimal::of($subtotal)->multipliedBy($rate));
    }

    private function latestProfile(): BillingProfile
    {
        return BillingProfile::orderByDesc('version')->firstOrFail();
    }

    public function test_saving_ppn_from_master_harga_stores_the_multiplier_and_is_audited(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertSame('0.11', $this->latestProfile()->settings['tax_rate']);

        $this->post(route('billing-masters.ppn'), ['ppn' => '12'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $this->latestProfile()->version);
        $this->assertSame('0.12', $this->latestProfile()->settings['tax_rate']);
        $this->assertSame('0.11', BillingProfile::where('version', 1)->sole()->settings['tax_rate'], 'The earlier version stays as it was.');
        $this->assertSame('0.99', $this->latestProfile()->settings['sla_target']);
        $this->assertSame('TELKOM Solution', $this->latestProfile()->settings['supplier_name'], 'Other profile data is carried over.');
        $audit = DB::table('billing_audit_events')->where('action', 'admin_ppn_saved')->sole();
        $this->assertSame(['id' => $this->latestProfile()->id, 'reason' => 'Diperbarui melalui Master Harga', 'tax_rate' => '0.12', 'previous_tax_rate' => '0.11'], json_decode($audit->details, true));

        // A decimal comma or point, a trailing percent sign, and the same number again (no new version).
        $this->post(route('billing-masters.ppn'), ['ppn' => '11,5', 'reason' => 'Tarif baru'])->assertRedirect();
        $this->assertSame('0.115', $this->latestProfile()->settings['tax_rate']);
        $this->post(route('billing-masters.ppn'), ['ppn' => '11.50 %'])->assertRedirect()->assertSessionHas('success', 'PPN tetap 11,5%.');
        $this->assertSame(3, $this->latestProfile()->version);
        $this->post(route('billing-masters.ppn'), ['ppn' => '0'])->assertRedirect();
        $this->assertSame('0', $this->latestProfile()->settings['tax_rate']);
        $this->post(route('billing-masters.ppn'), ['ppn' => '100'])->assertRedirect();
        $this->assertSame('1', $this->latestProfile()->settings['tax_rate']);
    }

    public function test_ppn_outside_zero_to_one_hundred_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['-1', '101', '100,5', 'abc', '1.000', '11,555', ''] as $bad) {
            $this->post(route('billing-masters.ppn'), ['ppn' => $bad])->assertSessionHasErrors('ppn');
        }
        $this->assertSame(1, BillingProfile::count());
        $this->assertSame('0.11', $this->latestProfile()->settings['tax_rate']);
        $this->assertSame(0, DB::table('billing_audit_events')->where('action', 'admin_ppn_saved')->count());

        $this->get(route('price-masters.index'))->assertOk()->assertSee('PPN: 11%');
        $this->from(route('price-masters.index'))->post(route('billing-masters.ppn'), ['ppn' => '101'])->assertRedirect(route('price-masters.index'));
        $this->followingRedirects()->from(route('price-masters.index'))->post(route('billing-masters.ppn'), ['ppn' => '101'])
            ->assertSee('PPN harus berupa angka 0 sampai 100')->assertSee('PPN: 11%');
    }

    public function test_a_draft_period_must_be_calculated_again_and_then_uses_the_new_rate(): void
    {
        [$draft] = $this->fixture();
        $this->assertSame('0.11', $draft->calculation['profile']['settings']['tax_rate']);
        $subtotal = $draft->calculation['totals']['subtotal'];
        $this->assertSame($this->tax($subtotal, '0.11'), $draft->calculation['totals']['tax']);
        $this->assertTrue($draft->calculationIsCurrent());
        $version = $draft->input_version;

        $this->post(route('billing-masters.ppn'), ['ppn' => '12'])->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame($this->latestProfile()->id, $draft->billing_profile_id);
        $this->assertFalse($draft->calculationIsCurrent(), 'The old figures are stale until Hitung Invoice.');
        $this->assertNull($draft->calculation);
        $this->assertSame($version + 1, $draft->input_version);
        $this->assertSame(1, DB::table('billing_audit_events')->where('invoice_period_id', $draft->id)->where('action', 'master_ppn_updated')->count());

        $this->postJson('/invoice-periods/'.$draft->id.'/calculate', ['input_version' => $draft->input_version])->assertSuccessful();
        $draft->refresh();
        $this->assertTrue($draft->calculationIsCurrent());
        $this->assertSame('0.12', $draft->calculation['profile']['settings']['tax_rate']);
        $this->assertSame($subtotal, $draft->calculation['totals']['subtotal'], 'Only the tax changes.');
        $this->assertSame($this->tax($subtotal, '0.12'), $draft->calculation['totals']['tax']);
        $this->assertSame(BillingMath::sum([$subtotal, $draft->calculation['totals']['tax']]), $draft->calculation['totals']['grand_total']);
        $this->assertEqualsWithDelta((float) $draft->calculation['totals']['tax'], (float) InvoiceSummary::where('invoice_period_id', $draft->id)->value('ppn_amount'), 0.001, 'The summary row follows the new tax.');
        $this->get('/invoice-periods/'.$draft->id.'/step/2')->assertOk()->assertSee('PPN 12%');

        // Back to 11 %: the draft follows again.
        $this->post(route('billing-masters.ppn'), ['ppn' => '11'])->assertRedirect();
        $this->assertFalse($draft->refresh()->calculationIsCurrent());
        app(InvoiceCalculator::class)->calculate($draft);
        $this->assertSame($this->tax($subtotal, '0.11'), $draft->refresh()->calculation['totals']['tax']);
    }

    public function test_an_issued_invoice_and_its_files_keep_the_rate_it_was_issued_with(): void
    {
        [$draft, $issued, $revision] = $this->fixture();
        $profileId = $issued->billing_profile_id;
        $hash = $revision->snapshot_hash;
        $version = $issued->input_version;
        $this->assertTrue($issued->isLocked());
        $this->assertSame('0.11', $revision->snapshot['profile']['settings']['tax_rate']);
        $tax = $revision->snapshot['totals']['tax'];

        $this->post(route('billing-masters.ppn'), ['ppn' => '12'])->assertRedirect()->assertSessionHasNoErrors();

        $issued->refresh();
        $this->assertSame($profileId, $issued->billing_profile_id, 'A locked period is not moved to the new version.');
        $this->assertSame($version, $issued->input_version);
        $this->assertTrue($issued->isLocked());
        $this->assertTrue($issued->calculationIsCurrent());
        $this->assertSame('0.11', $issued->calculation['profile']['settings']['tax_rate']);
        $this->assertSame(0, DB::table('billing_audit_events')->where('invoice_period_id', $issued->id)->where('action', 'master_ppn_updated')->count());
        $revision->refresh();
        $this->assertSame($hash, $revision->snapshot_hash);
        $this->assertSame('0.11', $revision->snapshot['profile']['settings']['tax_rate']);
        $this->assertSame($tax, $revision->snapshot['totals']['tax']);
        $this->assertSame($this->tax($revision->snapshot['totals']['subtotal'], '0.11'), $tax);
        $this->assertNotSame($profileId, $draft->refresh()->billing_profile_id, 'Only the draft moved.');

        // The files made after the change are drawn from the snapshot: Excel still says PPN 11%.
        (new GenerateInvoiceDocuments($revision->id))->handle(app(InvoiceDocumentGenerator::class));
        $book = IOFactory::load(Storage::disk('local')->path($revision->refresh()->outputs()->where('output_type', 'excel_invoice')->firstOrFail()->file_path));
        $cells = collect($book->getSheetByName('Summary Invoice')->toArray())->flatten()->filter(fn ($v) => is_string($v) && str_starts_with($v, 'PPN'))->values()->all();
        $this->assertSame(['PPN 11%'], $cells);
        $book->disconnectWorksheets();
        $this->assertSame($hash, $revision->fresh()->snapshot_hash);
    }

    public function test_master_harga_shows_the_ppn_and_the_price_list_headers_follow_it(): void
    {
        $this->actingAs(User::factory()->create());
        $priceList = fn () => app(PriceMasterController::class)->index()->render();

        $this->get(route('price-masters.index'))->assertOk()->assertSee('PPN: 11%')->assertSee('Ubah')
            ->assertSee('Berlaku untuk invoice yang belum terbit. Invoice yang sudah terbit tidak berubah.');
        $this->assertStringContainsString('+ PPN 11%', $priceList());

        $this->followingRedirects()->post(route('billing-masters.ppn'), ['ppn' => '11,5'])->assertOk()->assertSee('PPN: 11,5%');
        $this->get(route('price-masters.index'))->assertOk()->assertSee('PPN: 11,5%')->assertDontSee('PPN: 11%');
        $html = $priceList();
        $this->assertStringContainsString('+ PPN 11,5%', $html);
        $this->assertStringNotContainsString('+ PPN 11%', $html);
    }

    public function test_the_price_list_amounts_use_the_configured_rate(): void
    {
        $this->fixture();
        $price = PriceMaster::firstOrFail();
        $this->assertEqualsWithDelta(2319819 * 1.11, $price->calculateWithPpn(), 0.01);
        $this->assertEqualsWithDelta(1981982 * 1.11, $price->calculateInstallationWithPpn(), 0.01);
        $this->post(route('billing-masters.ppn'), ['ppn' => '12']);
        $this->assertSame('0.12', TaxRate::current());
        $this->assertEqualsWithDelta(2319819 * 1.12, $price->calculateWithPpn(), 0.01);
        $this->assertEqualsWithDelta(1981982 * 1.12, $price->calculateInstallationWithPpn(), 0.01);
        $this->assertStringContainsString('Rp 2.598.197', app(PriceMasterController::class)->index()->render());
    }

    public function test_the_profile_form_is_typed_as_a_percentage(): void
    {
        $this->actingAs(User::factory()->create());
        $page = $this->get(route('price-masters.index', ['section' => 'profiles']))->assertOk()->assertSee('PPN (%)')->assertDontSee('Pengali PPN');
        $this->assertMatchesRegularExpression('/name="tax_percent"[^>]*value="11"/', $page->getContent());

        $settings = $this->latestProfile()->settings;
        $payload = ['name' => 'Bank Jatim / Telkom', 'tax_percent' => '12,5', 'proration_divisor' => 30, 'customer_name' => $settings['customer_name'], 'supplier_name' => $settings['supplier_name'], 'account_number' => $settings['account_number'], 'invoice_prefix' => $settings['invoice_prefix'], 'due_day' => 20];
        $this->post('/billing-masters/profiles', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.125', $this->latestProfile()->settings['tax_rate']);
        $this->assertArrayNotHasKey('tax_percent', $this->latestProfile()->settings);
        $this->get(route('price-masters.index', ['section' => 'profiles']))->assertSee('12,5%');

        $this->post('/billing-masters/profiles', array_merge($payload, ['tax_percent' => '101']))->assertSessionHasErrors('tax_percent');
        $this->post('/billing-masters/profiles', array_merge($payload, ['tax_percent' => '-1']))->assertSessionHasErrors('tax_percent');
        $this->assertSame(2, $this->latestProfile()->version);

        // The multiplier itself is still accepted (API and old forms) and must be between 0 and 1.
        unset($payload['tax_percent']);
        $this->post('/billing-masters/profiles', array_merge($payload, ['tax_rate' => '0.11']))->assertRedirect();
        $this->assertSame('0.11', $this->latestProfile()->settings['tax_rate']);
        $this->post('/billing-masters/profiles', array_merge($payload, ['tax_rate' => '11']))->assertSessionHasErrors('tax_rate');
        $this->post('/billing-masters/profiles', $payload)->assertSessionHasErrors(['tax_percent', 'tax_rate']);
    }

    public function test_rates_are_formatted_with_an_indonesian_decimal_comma(): void
    {
        foreach (['0.11' => '11%', '0.115' => '11,5%', '0.1' => '10%', '0.1100' => '11%', '0' => '0%', '1' => '100%', '0.125' => '12,5%', null => '11%', 'abc' => '11%'] as $rate => $label) {
            $this->assertSame($label, TaxRate::label($rate), 'Rate '.var_export($rate, true));
        }
        foreach (['11' => '0.11', '11,5' => '0.115', '11.5' => '0.115', ' 12 % ' => '0.12', '0' => '0', '100' => '1', '7,05' => '0.0705'] as $input => $multiplier) {
            $this->assertSame($multiplier, TaxRate::fromPercent($input), 'Input '.$input);
        }
        foreach (['-1', '101', '100,01', '', 'abc', '1,2,3', '11,555', '1.000'] as $input) {
            $this->assertNull(TaxRate::fromPercent($input), 'Input '.$input);
        }
    }
}
