<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
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
use App\Services\Billing\SummaryImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A special column: an extra amount per SSID with a name of its own, for one invoice, as OKT26 uses for the Mikrotik
 * upgrade of the September invoice. It raises the line total and the invoice total (so also PPN), but is not part of
 * the month's service price. The operator enters it on Menu Lanjutan.
 */
class SpecialColumnTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Biaya contoh usage agst Inv Sept';

    private const SUMMARY = 'Biaya contoh';

    /** Two services at 2.319.819. */
    private function period(): InvoicePeriod
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $period = InvoicePeriod::create(['month' => 10, 'year' => 2026, 'created_by' => auth()->id()]);
        PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        foreach (['1111111111', '2222222222'] as $i => $sid) {
            $master = BankJatimLink::create(['ssid' => $sid, 'office_type_id' => $office->id, 'sequence_in_office_type' => $i + 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Alamat '.$sid, 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
            InvoiceLine::create(['invoice_period_id' => $period->id, 'bank_jatim_link_id' => $master->id, 'line_number' => $i + 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
        }
        $period->update(['sla_coverage' => ['sids' => ['1111111111', '2222222222']]]);

        return $period;
    }

    private function special(InvoicePeriod $period, string $sid = '1111111111', string $amount = '70000', string $name = self::NAME, string $summary = self::SUMMARY): void
    {
        InvoiceAdjustment::create(['invoice_period_id' => $period->id, 'sid' => $sid, 'kind' => InvoiceCalculator::SPECIAL_COLUMN, 'amount' => $amount, 'attributes' => ['column' => $name, 'summary' => $summary], 'reason' => 'Alasan contoh', 'reference' => 'Referensi contoh']);
    }

    private function calculate(InvoicePeriod $period): array
    {
        return app(InvoiceCalculator::class)->calculate($period->fresh(), false);
    }

    private function form(array $with = []): array
    {
        return $with + ['column' => self::NAME, 'summary' => self::SUMMARY, 'amount' => '70000', 'sids' => "1111111111\n2222222222", 'reason' => 'Alasan contoh', 'reference' => 'Referensi contoh'];
    }

    private function rows(InvoicePeriod $period)
    {
        return InvoiceAdjustment::where('invoice_period_id', $period->id)->where('kind', InvoiceCalculator::SPECIAL_COLUMN)->orderBy('id')->get();
    }

    public function test_it_raises_the_line_and_invoice_totals_and_is_taxed(): void
    {
        $period = $this->period();
        $this->special($period);
        $result = $this->calculate($period);
        [$with, $without] = $result['lines'];

        $this->assertSame([self::NAME => '70000.00'], $with['special']);
        $this->assertSame('70000.00', $with['special_total']);
        $this->assertSame('2319819.00', $with['charges']['existing'], 'The month\'s service price stays as it is.');
        $this->assertSame('2389819.00', $with['gross']);
        $this->assertSame('2389819.00', $with['net']);
        $this->assertSame([], $without['special']);
        $this->assertSame('0.00', $without['special_total']);
        $this->assertSame('2319819.00', $without['gross']);

        $totals = $result['totals'];
        $this->assertSame([['name' => self::NAME, 'summary' => self::SUMMARY, 'amount' => '70000.00']], $totals['special_columns']);
        $this->assertSame('70000.00', $totals['special_total']);
        $this->assertSame('4639638.00', $totals['components']['existing']);
        $this->assertSame('4709638.00', $totals['gross']);
        $this->assertSame('4709638.00', $totals['net_exact']);
        $this->assertSame('518060', $totals['tax'], '11% of 4.709.638 = 518.060,18');
        $this->assertSame('5227698.00', $totals['grand_total']);
        $this->assertSame('4709638.00', $totals['branch_totals']['Cabang Test']['net']);
    }

    public function test_several_columns_keep_their_own_amounts_in_the_order_they_were_entered(): void
    {
        $period = $this->period();
        $this->special($period, '2222222222', '5000', 'Zulu', 'Zulu');
        $this->special($period, '1111111111', '70000');
        $this->special($period, '1111111111', '5000', 'Zulu', 'Zulu');
        $result = $this->calculate($period);

        $this->assertEqualsCanonicalizing(['Zulu' => '5000.00', self::NAME => '70000.00'], $result['lines'][0]['special']);
        $this->assertSame('75000.00', $result['lines'][0]['special_total']);
        $this->assertSame('2394819.00', $result['lines'][0]['gross']);
        $this->assertSame([['name' => 'Zulu', 'summary' => 'Zulu', 'amount' => '10000.00'], ['name' => self::NAME, 'summary' => self::SUMMARY, 'amount' => '70000.00']], $result['totals']['special_columns']);
        $this->assertSame('80000.00', $result['totals']['special_total']);
    }

    public function test_a_period_without_it_is_calculated_as_before(): void
    {
        $totals = $this->calculate($this->period())['totals'];

        $this->assertSame([], $totals['special_columns']);
        $this->assertSame('0.00', $totals['special_total']);
        $this->assertSame('4639638.00', $totals['gross']);
        $this->assertSame('4639638.00', $totals['net_exact']);
    }

    public function test_sla_credit_is_taken_on_the_service_price_only(): void
    {
        $period = $this->period();
        // 2% down in a 30-day month: 1% above the allowed 1%.
        $down = (int) (30 * 86400 * 0.02);
        foreach (['1111111111', '2222222222'] as $sid) {
            DB::table('outage_events')->insert(['invoice_period_id' => $period->id, 'sid' => $sid, 'kind' => 'aggregate', 'review_status' => 'eligible', 'duration_seconds' => $down, 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = $this->calculate($period)['lines'][0]['sla_credit'];
        $this->special($period);
        $line = $this->calculate($period)['lines'][0];

        $this->assertSame('23198', $before);
        $this->assertSame($before, $line['sla_credit'], 'A special column is not part of the month\'s service price, so it does not change the credit.');
        $this->assertSame('2366621.00', $line['net'], '2.319.819 - 23.198 + 70.000');
    }

    public function test_it_is_not_reported_as_a_difference_with_telkoms_price(): void
    {
        $period = $this->period();
        foreach ($period->lines as $line) {
            $line->update(['service_snapshot' => InvoiceCalculator::serviceSnapshot($line) + ['reference_price' => '2319819.00']]);
        }
        $this->special($period);

        $this->assertNotContains('tariff_discrepancy', array_column($this->calculate($period)['issues'], 'code'));
    }

    public function test_the_summary_gets_a_row_from_k_on_per_column_and_only_when_the_invoice_has_one(): void
    {
        $period = $this->period();
        $this->assertSame(['gross', 'sla_credit', 'manual_credit', 'subtotal', 'tax', 'grand_total'], array_values(array_filter(array_column(SummaryImage::rows($this->calculate($period))['rows'], 'key'))));

        $this->special($period);
        $this->special($period, '2222222222', '5000', 'Biaya kedua', 'Biaya kedua');
        $data = SummaryImage::rows($this->calculate($period));
        $rows = collect($data['rows'])->keyBy(fn ($row) => $row['key'] ?? $row['label']);

        $this->assertSame(['gross', 'sla_credit', 'manual_credit', 'special_0', 'special_1', 'subtotal', 'tax', 'grand_total'], array_values(array_filter(array_column($data['rows'], 'key'))));
        $this->assertSame('K. Total Nilai Biaya contoh', $rows['special_0']['label']);
        $this->assertSame('Rp70.000', $rows['special_0']['value']);
        $this->assertSame('70000', $rows['special_0']['amount']);
        $this->assertSame('L. Total Nilai Biaya kedua', $rows['special_1']['label']);
        $this->assertSame('Rp5.000', $rows['special_1']['value']);
        $this->assertSame('4639638', $rows['gross']['amount'], 'A to F add up without the special columns, as on the Telkom sheet.');
        $this->assertSame('4714638', $rows['subtotal']['amount']);
    }

    public function test_a_long_name_on_the_summary_picture_still_renders(): void
    {
        $period = $this->period();
        $this->special($period, summary: str_repeat('W', 45));
        $path = tempnam(sys_get_temp_dir(), 'png');

        (new SummaryImage)->render($this->calculate($period), $path);

        $this->assertSame(IMAGETYPE_PNG, getimagesize($path)[2]);
        unlink($path);
    }

    public function test_an_older_snapshot_without_the_fields_still_gives_its_summary(): void
    {
        $snapshot = $this->calculate($this->period());
        unset($snapshot['totals']['special_total'], $snapshot['totals']['special_columns']);

        $rows = collect(SummaryImage::rows($snapshot)['rows'])->keyBy(fn ($row) => $row['key'] ?? $row['label']);

        $this->assertSame('4639638', $rows['gross']['amount']);
        $this->assertFalse($rows->has('special_0'));
    }

    public function test_the_operator_saves_a_column_from_a_pasted_list(): void
    {
        $period = $this->period();
        $version = $period->fresh()->input_version;

        // A list pasted from Excel: line ends, stray spaces, a comma and a repeated SSID.
        $this->post(route('billing.special-columns', $period), $this->form(['amount' => 'Rp70000', 'sids' => " 1111111111\r\n\r\n2222222222, 1111111111 \n"]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $rows = $this->rows($period);
        $this->assertSame(['1111111111', '2222222222'], $rows->pluck('sid')->all());
        $this->assertSame('70000.00', $rows[0]->amount);
        $this->assertSame(['column' => self::NAME, 'summary' => self::SUMMARY], $rows[0]->getAttribute('attributes'));
        $this->assertSame('Alasan contoh', $rows[0]->reason);
        $this->assertSame('Referensi contoh', $rows[0]->reference);
        $this->assertSame(auth()->id(), (int) $rows[0]->created_by);
        $this->assertSame($version + 1, $period->fresh()->input_version, 'The calculation has to be made again.');
        $this->assertDatabaseHas('billing_audit_events', ['invoice_period_id' => $period->id, 'action' => 'special_column_saved']);
        $this->assertSame('140000.00', $this->calculate($period)['totals']['special_total']);
    }

    public function test_saving_the_same_name_replaces_its_list_and_leaves_other_columns(): void
    {
        $period = $this->period();
        $this->post(route('billing.special-columns', $period), $this->form())->assertSessionHasNoErrors();
        $this->post(route('billing.special-columns', $period), $this->form(['column' => 'Biaya kedua', 'summary' => '', 'amount' => '5000', 'sids' => '2222222222']))->assertSessionHasNoErrors();
        $this->post(route('billing.special-columns', $period), $this->form(['amount' => '80000', 'sids' => '2222222222']))->assertSessionHasNoErrors();

        $columns = $this->calculate($period)['totals']['special_columns'];

        $this->assertEqualsCanonicalizing([
            ['name' => 'Biaya kedua', 'summary' => 'Biaya kedua', 'amount' => '5000.00'],
            ['name' => self::NAME, 'summary' => self::SUMMARY, 'amount' => '80000.00'],
        ], $columns);
        $this->assertCount(2, $this->rows($period));
    }

    public function test_nothing_is_saved_when_an_ssid_is_not_in_the_period(): void
    {
        $period = $this->period();
        $version = $period->fresh()->input_version;

        $this->from(route('invoice-periods.advanced', $period))->post(route('billing.special-columns', $period), $this->form(['sids' => "1111111111\n9999999999"]))
            ->assertRedirect(route('invoice-periods.advanced', $period))->assertSessionHasErrors('sids');

        $this->assertStringContainsString('9999999999', session('errors')->first('sids'));
        $this->assertCount(0, $this->rows($period));
        $this->assertSame($version, $period->fresh()->input_version);
    }

    public function test_the_form_refuses_a_wrong_amount_and_a_long_name_without_a_short_one(): void
    {
        $period = $this->period();
        foreach (['0', '-5', 'abc', '70.000'] as $amount) {
            $this->post(route('billing.special-columns', $period), $this->form(['amount' => $amount]))->assertSessionHasErrors('amount');
        }
        $name = str_repeat('x', 46);
        $this->post(route('billing.special-columns', $period), $this->form(['column' => $name, 'summary' => '']))->assertSessionHasErrors('summary');
        $this->post(route('billing.special-columns', $period), $this->form(['column' => str_repeat('x', 61)]))->assertSessionHasErrors('column');
        $this->post(route('billing.special-columns', $period), $this->form(['summary' => str_repeat('x', 46)]))->assertSessionHasErrors('summary');
        $this->assertCount(0, $this->rows($period));

        // A long name is fine with a short one for the summary; a short name serves as both.
        $this->post(route('billing.special-columns', $period), $this->form(['column' => $name]))->assertSessionHasNoErrors();
        $this->post(route('billing.special-columns', $period), $this->form(['column' => 'Pendek', 'summary' => '']))->assertSessionHasNoErrors();
        $this->assertSame('Pendek', $this->rows($period)->last()->getAttribute('attributes')['summary']);
    }

    public function test_the_operator_removes_a_column_with_a_reason(): void
    {
        $period = $this->period();
        $this->post(route('billing.special-columns', $period), $this->form());
        $this->post(route('billing.special-columns', $period), $this->form(['column' => 'Biaya kedua', 'summary' => '', 'sids' => '1111111111']));

        $this->post(route('billing.special-columns.remove', $period), ['column' => self::NAME])->assertSessionHasErrors('reason');
        $this->post(route('billing.special-columns.remove', $period), ['column' => 'Tidak ada', 'reason' => 'Salah input'])->assertNotFound();
        $this->post(route('billing.special-columns.remove', $period), ['column' => self::NAME, 'reason' => 'Salah input'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['Biaya kedua'], $this->rows($period)->map(fn ($row) => $row->getAttribute('attributes')['column'])->all());
        $this->assertDatabaseHas('billing_audit_events', ['invoice_period_id' => $period->id, 'action' => 'special_column_removed']);
    }

    public function test_a_locked_period_refuses_changes(): void
    {
        $period = $this->period();
        $this->post(route('billing.special-columns', $period), $this->form());
        $period->update(['is_locked' => true]);

        $this->post(route('billing.special-columns', $period), $this->form(['amount' => '1']))->assertStatus(423);
        $this->post(route('billing.special-columns.remove', $period), ['column' => self::NAME, 'reason' => 'Salah input'])->assertStatus(423);
        $this->assertSame(['70000.00', '70000.00'], $this->rows($period)->pluck('amount')->all());
        $this->get(route('invoice-periods.advanced', $period))->assertOk()->assertSee(self::NAME)->assertDontSee('Simpan kolom spesial')->assertDontSee('Hapus kolom');
    }

    public function test_the_operator_sees_it_on_step_2_and_on_the_advanced_page(): void
    {
        $period = $this->period();
        $this->post(route('billing.special-columns', $period), $this->form());
        app(InvoiceCalculator::class)->calculate($period->fresh());

        $this->get(route('invoice-periods.step2', $period))->assertOk()->assertSee(self::NAME);
        $this->get(route('invoice-periods.advanced', $period))->assertOk()
            ->assertSee(self::NAME)->assertSee('2 SSID')->assertSee('di ringkasan: '.self::SUMMARY)->assertSee('Simpan kolom spesial')->assertSee('Hapus kolom')
            ->assertDontSee('Tarik penyesuaian');
    }

    public function test_step_2_does_not_mention_it_on_a_period_without_it(): void
    {
        $period = $this->period();
        app(InvoiceCalculator::class)->calculate($period->fresh());

        $this->get(route('invoice-periods.step2', $period))->assertOk()->assertDontSee(self::NAME);
    }
}
