<?php

namespace Tests\Feature;

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
 * Menu Lanjutan, box "Kredit manual / override harga": it lists the SSIDs with a price problem (Telkom price
 * against the system tariff, or no tariff) and fills the form from that list.
 *
 * JAN26 invoice (usage December 2025). Tariffs, all "Kantor Kas" / TELKOM VPN IP, no router:
 * 32 Kbps 1.300.000, 512 Kbps 2.319.819 (64 Kbps has no tariff).
 */
class AdvancedPriceProblemsTest extends TestCase
{
    use RefreshDatabase;

    private const TELKOM_ROW = 'Invoice Des 2025.xlsx · Existing · baris 12';

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
        foreach (['32' => ['32 Kbps', 32], '64' => ['64 Kbps', 64], '512' => ['512 Kbps', 512]] as $code => [$name, $kbps]) {
            $this->bw[$code] = BandwidthOption::create(['bandwidth_code' => $code, 'bandwidth_name' => $name, 'value_kbps' => $kbps]);
        }
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        foreach (['32' => '1300000', '512' => '2319819'] as $bandwidth => $monthly) {
            PriceMaster::create([
                'location_category_id' => $this->kas->id, 'service_id' => $this->vpn->id, 'bandwidth_option_id' => $this->bw[$bandwidth]->id, 'router_mode' => 'none',
                'monthly_price' => $monthly, 'installation_price' => '500000', 'relocation_price' => '0', 'effective_start_date' => '2020-01-01', 'is_active' => true,
            ]);
        }
        $this->period = InvoicePeriod::create(['month' => 1, 'year' => 2026, 'created_by' => auth()->id()]);
    }

    // ---------------------------------------------------------------- the list

    public function test_the_box_is_open_and_lists_each_ssid_with_both_prices_the_signed_difference_and_the_cause(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512', ['source' => ['batch_id' => $this->batch()->id, 'sheet' => 'Existing', 'row' => 12]]);
        $this->addService('4700020-3', '32', '1000000.00');
        $this->calculate();

        $html = $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertViewHas('calculationCurrent', true)
            ->assertViewHas('priceProblems', function (array $problems) {
                $this->assertCount(2, $problems);
                $first = collect($problems)->firstWhere('sid', '4700020-37118');
                $this->assertSame('tariff_discrepancy', $first['code']);
                $this->assertSame('KANTOR CABANG SURABAYA', $first['branch']);
                $this->assertSame('Jl. Pahlawan 4700020-37118', $first['address']);
                $this->assertSame('32 Kbps', $first['bandwidth']);
                $this->assertSame('KANTOR KAS', $first['category']);
                $this->assertSame('tanpa router', $first['router_label']);
                $this->assertSame(['2319819.00', '1300000.00', '1300000.00', '1019819.00'], [$first['reference'], $first['system'], $first['monthly'], $first['difference']]);
                $this->assertStringContainsString('Harga Telkom Rp 2.319.819 sama dengan tarif 512 Kbps tanpa router.', $first['explanation']);
                $this->assertSame(self::TELKOM_ROW, $first['source_text']);
                $this->assertFalse($first['stale']);
                $this->assertSame('-300000.00', collect($problems)->firstWhere('sid', '4700020-3')['difference']);
                $this->assertNull(collect($problems)->firstWhere('sid', '4700020-3')['source_text']);

                return true;
            })
            ->assertSee('Kredit manual / override harga')
            ->assertSee('SSID yang perlu dicek harganya (2)')
            ->assertSee('Harga di workbook Telkom berbeda dengan tarif sistem, atau tarifnya belum ada. Pilih harga yang benar lalu simpan; atau selesaikan sekaligus di langkah 2.')
            ->assertSee('Buka langkah 2')->assertSee('href="'.route('invoice-periods.step2', $this->period).'"', false)
            ->assertSee('4700020-37118')->assertSee('Cabang KANTOR CABANG SURABAYA', false)->assertSee('Jl. Pahlawan 4700020-37118')
            ->assertSee('32 Kbps')->assertSee('KANTOR KAS · tanpa router')
            ->assertSee('Rp 2.319.819')->assertSee('Rp 1.300.000')->assertSee('Rp 1.000.000')
            ->assertSee('+Rp 1.019.819')->assertSee('−Rp 300.000')
            ->assertSee('Harga Telkom Rp 2.319.819 sama dengan tarif 512 Kbps tanpa router.')
            ->assertSee('Sumber: '.self::TELKOM_ROW)
            ->assertSee('Isi: harga Telkom')->assertSee('Isi: harga sistem')
            ->assertDontSee('Daftar ini dari perhitungan terakhir')
            ->assertDontSee('Tidak ada SSID dengan masalah harga.')
            ->assertDontSee('Klik Hitung ulang di langkah 2 untuk melihat penyebabnya.')
            ->getContent();

        $this->assertSame(1, preg_match_all('/lg:col-span-2"\s+open/', $html), 'The box is open, and wide enough for the table.');
        $this->assertStringContainsString('text-amber-700">+Rp 1.019.819', $html);
        $this->assertStringContainsString('text-red-600">−Rp 300.000', $html);
    }

    public function test_the_box_stays_closed_when_there_is_nothing_to_check(): void
    {
        $this->addService('4700020-1', '32', '1300000.00');
        $this->calculate();

        $html = $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertSee('Kredit manual / override harga')
            ->assertSee('Tidak ada SSID dengan masalah harga.')
            ->assertDontSee('SSID yang perlu dicek harganya')->assertDontSee('Buka langkah 2')->assertDontSee('Isi: harga Telkom')
            ->getContent();

        $this->assertMatchesRegularExpression('/<details class="card rounded-xl p-6 "\s+x-data=/', $html, 'No open attribute and no wide column.');
        $this->assertStringContainsString('action="'.route('billing.adjustments', $this->period).'"', $html);
    }

    public function test_without_a_calculation_neither_the_list_nor_the_green_line_is_shown(): void
    {
        $this->addService('4700020-1', '32', '2319819.00');

        $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertViewHas('priceProblems', [])
            ->assertViewHas('calculationCurrent', false)
            ->assertSee('Kredit manual / override harga')
            ->assertDontSee('SSID yang perlu dicek harganya')->assertDontSee('Tidak ada SSID dengan masalah harga.');
    }

    public function test_an_old_calculation_is_still_listed_with_a_note(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00');
        $this->calculate();
        DB::table('invoice_periods')->where('id', $this->period->id)->update(['calculated_input_version' => 999]);

        $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertViewHas('calculationCurrent', false)
            ->assertSee('SSID yang perlu dicek harganya (1)')
            ->assertSee('Daftar ini dari perhitungan terakhir. Hitung ulang di langkah 2 untuk daftar terbaru.')
            ->assertSee('+Rp 1.019.819');
    }

    public function test_a_calculation_stored_before_the_price_detail_existed_still_shows_the_prices_and_asks_for_a_recalculation(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512');
        $this->addService('4700020-8', '32', '1950000.00');
        $this->calculate();
        $this->rewriteIssues(function (array $issue) {
            unset($issue['detail']);
            $issue['message'] = $issue['sid'] === '4700020-37118'
                ? 'Tarif sumber 2319819 berbeda dengan register 1300000.00. Catat override atau rekonsiliasi sumber.'
                : 'Tarif instalasi pada sumber berbeda dengan register. Catat penyelesaian atau override periode.';

            return $issue;
        });

        $html = $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertViewHas('priceProblems', function (array $problems) {
                $parsed = collect($problems)->firstWhere('sid', '4700020-37118');
                $this->assertTrue($parsed['stale']);
                $this->assertSame(['2319819.00', '1300000.00', '1300000.00', '1019819.00'], [$parsed['reference'], $parsed['system'], $parsed['monthly'], $parsed['difference']]);
                $this->assertNull($parsed['explanation']);
                $this->assertNull($parsed['source_text']);
                $this->assertNull($parsed['bandwidth']);
                $this->assertSame('KANTOR KAS', $parsed['category'], 'The category comes from the calculated line.');
                $this->assertSame('KANTOR CABANG SURABAYA', $parsed['branch']);

                $unknown = collect($problems)->firstWhere('sid', '4700020-8');
                $this->assertTrue($unknown['stale']);
                $this->assertSame([null, null, null, null], [$unknown['reference'], $unknown['system'], $unknown['monthly'], $unknown['difference']]);

                return true;
            })
            ->assertSee('SSID yang perlu dicek harganya (2)')
            ->assertSee('Rp 2.319.819')->assertSee('Rp 1.300.000')->assertSee('+Rp 1.019.819')
            ->assertSee('Klik Hitung ulang di langkah 2 untuk melihat penyebabnya.')
            ->assertSee('Isi manual di form bawah.')
            ->assertDontSee('Harga Telkom Rp 2.319.819 sama dengan')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'Klik Hitung ulang di langkah 2 untuk melihat penyebabnya.'));
        // Only the SSID whose prices could be read can be filled.
        $this->assertSame(['4700020-37118'], array_keys($this->alpineData($html, 'fills')));
        $this->assertSame('2319819', $this->alpineData($html, 'fills')['4700020-37118']['source']['amount']);
        $this->assertSame('Workbook Telkom', $this->alpineData($html, 'fills')['4700020-37118']['source']['reference']);
    }

    public function test_a_missing_tariff_shows_what_is_missing_and_links_to_master_harga(): void
    {
        $this->addService('4700020-4', '64', null);
        $this->calculate();

        $html = $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertViewHas('priceProblems', function (array $problems) {
                $this->assertCount(1, $problems);
                $this->assertSame('tariff', $problems[0]['code']);
                $this->assertSame('64 Kbps', $problems[0]['bandwidth']);
                $this->assertNull($problems[0]['reference']);
                $this->assertNull($problems[0]['difference']);
                $this->assertFalse($problems[0]['stale']);

                return true;
            })
            ->assertSee('SSID yang perlu dicek harganya (1)')
            ->assertSee('4700020-4')->assertSee('64 Kbps')->assertSee('KANTOR KAS · tanpa router')
            ->assertSee('Belum ada tarif untuk TELKOM VPN IP · KANTOR KAS · 64 Kbps · tanpa router yang berlaku pada 01/12/2025.')
            ->assertSee('Tambah tarif di Master Harga')
            ->assertSee('href="'.route('price-masters.index', ['section' => 'tariffs']).'"', false)
            ->assertDontSee('Isi: harga Telkom')->assertDontSee('Isi: harga sistem')
            ->getContent();

        $this->assertSame([], $this->alpineData($html, 'fills'));
        $this->assertSame(['4700020-4' => 'Tarif belum ada di Master Harga.'], $this->alpineData($html, 'hints'));
    }

    public function test_an_ssid_that_already_has_an_override_gets_a_badge_instead_of_the_fill_buttons(): void
    {
        $this->addService('4700020-1', '32', '2319819.00');
        $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();
        InvoiceAdjustment::create([
            'invoice_period_id' => $this->period->id, 'sid' => '4700020-1', 'kind' => 'tariff_override', 'amount' => '2319819.00',
            'reason' => 'Sudah dikonfirmasi Telkom', 'reference' => 'Email Telkom', 'attributes' => [], 'created_by' => auth()->id(),
        ]);
        // A credit is not a price override.
        InvoiceAdjustment::create([
            'invoice_period_id' => $this->period->id, 'sid' => '4700020-2', 'kind' => 'manual_credit', 'amount' => '1000.00',
            'reason' => 'Kompensasi', 'reference' => 'Tiket 1', 'attributes' => [], 'created_by' => auth()->id(),
        ]);

        $html = $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertSee('SSID yang perlu dicek harganya (2)')
            ->assertSee('Sudah ada override')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Sudah ada override'));
        $this->assertSame(1, substr_count($html, 'Isi: harga Telkom'), 'Only the SSID without an override can be filled.');
        $this->assertSame(['4700020-2'], array_keys($this->alpineData($html, 'fills')));
    }

    public function test_a_locked_period_has_no_form_and_no_list(): void
    {
        $this->addService('4700020-1', '32', '2319819.00');
        $this->calculate();
        DB::table('invoice_periods')->where('id', $this->period->id)->update(['is_locked' => true]);

        $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertDontSee('SSID yang perlu dicek harganya')->assertDontSee('Isi: harga Telkom')->assertDontSee('Simpan penyesuaian');
    }

    // ---------------------------------------------------------------- filling the form

    public function test_the_fill_buttons_carry_the_amount_reference_and_reason_for_each_choice(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512', ['source' => ['batch_id' => $this->batch()->id, 'sheet' => 'Existing', 'row' => 12]]);
        $this->addService('4700020-3', '32', '1000000.50');
        $this->calculate();

        $html = $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertSee("fill('4700020-37118', 'source')", false)->assertSee("fill('4700020-37118', 'system')", false)
            ->assertSee("fill('4700020-3', 'source')", false)->assertSee("fill('4700020-3', 'system')", false)
            ->getContent();

        $fills = $this->alpineData($html, 'fills');
        $this->assertSame([
            'source' => [
                'amount' => '2319819', 'reference' => self::TELKOM_ROW,
                'reason' => 'Disamakan dengan harga workbook Telkom Rp 2.319.819; tarif sistem Rp 1.300.000.',
            ],
            'system' => [
                'amount' => '1300000', 'reference' => self::TELKOM_ROW,
                'reason' => 'Tetap memakai tarif sistem Rp 1.300.000; harga Telkom Rp 2.319.819 tidak dipakai.',
            ],
        ], $fills['4700020-37118']);
        // No file name known: a plain reference. A Telkom price with cents keeps them.
        $this->assertSame('1000000.5', $fills['4700020-3']['source']['amount']);
        $this->assertSame('Workbook Telkom', $fills['4700020-3']['source']['reference']);
        $this->assertSame('Disamakan dengan harga workbook Telkom Rp 1.000.000,50; tarif sistem Rp 1.300.000.', $fills['4700020-3']['source']['reason']);
        $this->assertSame('1300000', $fills['4700020-3']['system']['amount']);

        $this->assertSame([
            '4700020-37118' => 'Harga Telkom Rp 2.319.819 · sistem Rp 1.300.000 · selisih +Rp 1.019.819',
            '4700020-3' => 'Harga Telkom Rp 1.000.000,50 · sistem Rp 1.300.000 · selisih −Rp 299.999,50',
        ], $this->alpineData($html, 'hints'));

        // The form keeps its fields and action; the filled ones are bound to the page state.
        $this->assertStringContainsString('action="'.route('billing.adjustments', $this->period).'"', $html);
        foreach (['sid' => 'form.sid', 'kind' => 'form.kind', 'amount' => 'form.amount', 'reference' => 'form.reference', 'reason' => 'form.reason'] as $field => $model) {
            $this->assertMatchesRegularExpression('/<(input|select|textarea)[^>]*name="'.$field.'"[^>]*x-model="'.preg_quote($model, '/').'"/', $html, "Field {$field} is bound.");
        }
        $this->assertStringContainsString('x-ref="reason"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="installation_price"(?![^>]*x-model)[^>]*>/', $html);
        $this->assertStringContainsString('x-show="hint"', $html);
    }

    public function test_the_values_the_buttons_fill_are_accepted_by_the_form_and_settle_the_price_problem(): void
    {
        $this->addService('4700020-37118', '32', '2319819.00', '512', ['source' => ['batch_id' => $this->batch()->id, 'sheet' => 'Existing', 'row' => 12]]);
        $this->addService('4700020-2', '32', '1950000.00');
        $this->calculate();
        $fills = $this->alpineData($this->get(route('invoice-periods.advanced', $this->period))->assertOk()->getContent(), 'fills');

        // The operator posts what the button filled; the second one adds a sentence to the reason.
        $this->post(route('billing.adjustments', $this->period), ['sid' => '4700020-37118', 'kind' => 'tariff_override'] + $fills['4700020-37118']['source'])
            ->assertRedirect()->assertSessionHas('success', 'Penyesuaian disimpan.')->assertSessionHasNoErrors();
        $this->post(route('billing.adjustments', $this->period), array_merge(['sid' => '4700020-2', 'kind' => 'tariff_override'], $fills['4700020-2']['system'], ['reason' => $fills['4700020-2']['system']['reason'].' Dikonfirmasi lewat telepon.']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $telkom = InvoiceAdjustment::where('sid', '4700020-37118')->sole();
        $this->assertSame('tariff_override', $telkom->kind);
        $this->assertSame('2319819.00', $telkom->amount);
        $this->assertSame(self::TELKOM_ROW, $telkom->reference);
        $this->assertSame('Disamakan dengan harga workbook Telkom Rp 2.319.819; tarif sistem Rp 1.300.000.', $telkom->reason);
        $system = InvoiceAdjustment::where('sid', '4700020-2')->sole();
        $this->assertSame('1300000.00', $system->amount);
        $this->assertSame('Workbook Telkom', $system->reference);
        $this->assertStringEndsWith('Dikonfirmasi lewat telepon.', $system->reason);

        // The page edit invalidates the old calculation, so the list is gone until the next calculation.
        $this->get(route('invoice-periods.advanced', $this->period))->assertOk()->assertViewHas('priceProblems', [])->assertDontSee('SSID yang perlu dicek harganya');

        (new InvoiceCalculator)->calculate($this->period->fresh());

        $this->assertNull($this->issue('tariff_discrepancy', '4700020-37118'));
        $this->assertNull($this->issue('tariff_discrepancy', '4700020-2'));
        $this->assertSame('2319819.00', $this->calculatedLine('4700020-37118')['gross']);
        $this->assertSame('1300000.00', $this->calculatedLine('4700020-2')['gross']);
        $this->get(route('invoice-periods.advanced', $this->period))->assertOk()
            ->assertViewHas('priceProblems', [])->assertViewHas('calculationCurrent', true)
            ->assertSee('Tidak ada SSID dengan masalah harga.');
    }

    // ---------------------------------------------------------------- helpers

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
        return (new InvoiceCalculator)->calculate($this->period->fresh());
    }

    /** Rewrites the stored price issues, like a calculation made by an older version of the app. */
    private function rewriteIssues(callable $rewrite): void
    {
        $calculation = $this->period->fresh()->calculation;
        $calculation['issues'] = array_map(fn ($issue) => $issue['code'] === 'tariff_discrepancy' ? $rewrite($issue) : $issue, $calculation['issues']);
        DB::table('invoice_periods')->where('id', $this->period->id)->update(['calculation' => json_encode($calculation)]);
    }

    /**
     * The data the page hands to Alpine (@js turns it into JSON.parse('...') with the JSON escaped twice).
     */
    private function alpineData(string $html, string $key): array
    {
        $this->assertSame(1, preg_match('/'.$key.': (JSON\.parse\(\'(.*?)\'\)|\{\})/s', $html, $m), "The page has no {$key} for Alpine.");
        if ($m[1] === '{}') {
            return [];
        }

        return json_decode(json_decode('"'.$m[2].'"', true, 512, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
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
