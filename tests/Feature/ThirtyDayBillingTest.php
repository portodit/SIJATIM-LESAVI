<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\InvoiceLine;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\BillingMath;
use App\Services\Billing\InvoiceCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proration on a fixed 30-day month (February 28) with PSB billed from H+1.
 * Daily tariff of 2.319.819: round(/30) = 77.327, round(/28) = 82.851.
 */
class ThirtyDayBillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $month = 9, int $year = 2026): array
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        $bw = BandwidthOption::create(['bandwidth_code' => '512', 'bandwidth_name' => '512 Kbps', 'value_kbps' => 512]);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $master = BankJatimLink::create(['ssid' => '0012-ABC', 'office_type_id' => $office->id, 'sequence_in_office_type' => 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test', 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
        $p = InvoicePeriod::create(['month' => $month, 'year' => $year, 'created_by' => auth()->id()]);
        $line = InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $master->id, 'line_number' => 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address, 'traffic_exception_reason' => 'MRTG belum tersedia dari penyedia.']);
        $rate = PriceMaster::create(['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id, 'monthly_price' => '2319819', 'installation_price' => '1981982', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true]);
        $p->update(['sla_coverage' => ['sids' => [$master->ssid]]]);

        return [$p, $line, $rate, $master, $bw];
    }

    private function line(InvoicePeriod $p, BankJatimLink $master, array $events = []): array
    {
        foreach ($events as [$type, $date, $attributes]) {
            ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => $type, 'effective_date' => $date, 'attributes' => $attributes]);
        }

        return app(InvoiceCalculator::class)->calculate($p->fresh(), false)['lines'][0];
    }

    public function test_billing_day_helpers(): void
    {
        $this->assertSame(30, BillingMath::monthDays(Carbon::parse('2026-08-15')));
        $this->assertSame(30, BillingMath::monthDays(Carbon::parse('2026-09-15')));
        $this->assertSame(28, BillingMath::monthDays(Carbon::parse('2026-02-01')));
        $this->assertSame(28, BillingMath::monthDays(Carbon::parse('2028-02-01')), 'February has 28 billing days in leap years too.');
        $this->assertSame(31, BillingMath::billingDay(Carbon::parse('2026-08-31')));
        $this->assertSame(30, BillingMath::billingDay(Carbon::parse('2026-08-31'), true));
        $this->assertSame(28, BillingMath::billingDay(Carbon::parse('2028-02-29'), true));
        $this->assertSame(30, BillingMath::billingDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
        $this->assertSame(29, BillingMath::billingDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), true));
        $this->assertSame(0, BillingMath::billingDays(Carbon::parse('2026-08-30'), Carbon::parse('2026-08-31'), true));
        $this->assertSame(0, BillingMath::billingDays(Carbon::parse('2026-08-31'), Carbon::parse('2026-08-31')));
        $this->assertSame(28, BillingMath::billingDays(Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29')));
        $this->assertSame('2319819.00', BillingMath::segmentAmount('2319819', 30, 30));
        $this->assertSame('2242483.00', BillingMath::segmentAmount('2319819', 29, 30));
    }

    public function test_whole_month_is_flat_and_31_day_month_has_30_billing_days(): void
    {
        [$p, , , $master] = $this->fixture();
        $line = $this->line($p, $master);
        $this->assertSame('2319819.00', $line['gross']);
        $this->assertSame(30, $line['rates'][0]['billing_days']);
        $this->assertSame(30, $line['rates'][0]['month_days']);
        $this->assertSame(31 * 86400, $line['billing_seconds'], 'SLA keeps real calendar seconds.');
    }

    public function test_psb_on_the_first_is_billed_from_the_next_day(): void
    {
        [$p, , , $master] = $this->fixture();
        $line = $this->line($p, $master, [['pasang_baru', '2026-08-01', []]]);
        $this->assertSame('77327.00', BillingMath::prorate('2319819', 1, 30));
        $this->assertSame('2242483.00', $line['charges']['existing'], '29 x 77.327');
        $this->assertSame('1981982.00', $line['charges']['installation'], 'Installation fee is unchanged.');
        $this->assertSame(29, $line['rates'][0]['billing_days']);
        $this->assertSame('2026-08-01', $line['rates'][0]['start']);
        $this->assertSame(31 * 86400, $line['billing_seconds'], 'The SLA window still starts on the PSB date.');
    }

    public function test_psb_on_the_30th_or_31st_bills_no_monthly_days(): void
    {
        [$p, , , $master] = $this->fixture();
        foreach (['2026-08-30' => 2, '2026-08-31' => 1] as $date => $calendarDays) {
            $p->serviceEvents()->delete();
            $line = $this->line($p, $master, [['pasang_baru', $date, []]]);
            $this->assertSame('0.00', $line['charges']['existing'], $date);
            $this->assertSame(0, $line['rates'][0]['billing_days'], $date);
            $this->assertSame('1981982.00', $line['gross'], $date);
            $this->assertSame($calendarDays * 86400, $line['billing_seconds'], $date);
            $this->assertFalse(collect($line['issues'])->contains('code', 'not_billable'), $date);
        }
    }

    public function test_master_billing_start_date_counts_from_its_own_day(): void
    {
        [$p, $line, , $master] = $this->fixture();
        $s = InvoiceCalculator::serviceSnapshot($line);
        $s['billing_start_date'] = '2026-08-01';
        $line->update(['service_snapshot' => $s]);
        $this->assertSame('2319819.00', $this->line($p, $master)['gross']);
        $s['billing_start_date'] = '2026-08-11';
        $line->update(['service_snapshot' => $s]);
        $this->assertSame('1546540.00', $this->line($p, $master)['gross'], '20 days x 77.327');
    }

    public function test_cabut_bills_the_cut_day_and_on_the_31st_the_whole_month(): void
    {
        [$p, , , $master] = $this->fixture();
        $line = $this->line($p, $master, [['pemutusan', '2026-08-21', []]]);
        $this->assertSame('1623867.00', $line['charges']['termination']);
        $this->assertSame(21, array_sum(array_column($line['rates'], 'billing_days')));
        $p->serviceEvents()->delete();
        $line = $this->line($p, $master, [['pemutusan', '2026-08-31', []]]);
        $this->assertSame('2319819.00', $line['charges']['termination']);
        $this->assertSame(30, array_sum(array_column($line['rates'], 'billing_days')));
        $this->assertSame(31 * 86400, $line['billing_seconds']);
    }

    public function test_upgrade_splits_old_and_new_rate_on_30_days(): void
    {
        [$p, , $rate, $master] = $this->fixture();
        $newBw = BandwidthOption::create(['bandwidth_code' => '1024', 'bandwidth_name' => '1 Mbps', 'value_kbps' => 1024]);
        $new = $rate->replicate();
        $new->bandwidth_option_id = $newBw->id;
        $new->monthly_price = '1300000';
        $new->save();
        $line = $this->line($p, $master, [['upgrade', '2026-08-15', ['bandwidth_option_id' => $newBw->id]]]);
        $this->assertSame('1082578.00', $line['charges']['existing'], '14 x 77.327');
        $this->assertSame('693328.00', $line['charges']['upgrade'], '16 x 43.333');
        $this->assertSame([14, 16], array_column($line['rates'], 'billing_days'));
    }

    public function test_february_has_28_billing_days(): void
    {
        [$p, , , $master] = $this->fixture(3, 2026);
        $this->assertSame('2026-02-01', $p->start_date->toDateString());
        $line = $this->line($p, $master);
        $this->assertSame('2319819.00', $line['gross'], 'Whole February is billed flat.');
        $this->assertSame(28, $line['rates'][0]['billing_days']);
        $this->assertSame(28 * 86400, $line['billing_seconds']);
        $line = $this->line($p, $master, [['pasang_baru', '2026-02-01', []]]);
        $this->assertSame('82851.00', BillingMath::prorate('2319819', 1, 28));
        $this->assertSame('2236977.00', $line['charges']['existing'], '27 x round(2.319.819 / 28)');
        $this->assertSame(27, $line['rates'][0]['billing_days']);
    }

    public function test_leap_february_is_still_flat_and_cabut_on_the_29th_is_the_whole_month(): void
    {
        [$p, , , $master] = $this->fixture(3, 2028);
        $this->assertSame('2028-02-29', $p->end_date->toDateString());
        $this->assertSame('2319819.00', $this->line($p, $master)['gross']);
        $line = $this->line($p, $master, [['pemutusan', '2028-02-29', []]]);
        $this->assertSame('2319819.00', $line['charges']['termination']);
        $this->assertSame(29 * 86400, $line['billing_seconds']);
    }

    public function test_downtime_for_a_psb_keeps_its_calendar_coverage(): void
    {
        [$p, , , $master] = $this->fixture();
        ServiceEvent::create(['invoice_period_id' => $p->id, 'sid' => $master->ssid, 'event_type' => 'pasang_baru', 'effective_date' => '2026-08-10', 'attributes' => []]);
        $this->post(route('billing.downtime', $p), ['sid' => $master->ssid, 'downtime_seconds' => 3600])->assertRedirect();
        $this->assertSame('2026-08-10', $p->outages()->sole()->coverage_start->toDateString());
        $result = app(InvoiceCalculator::class)->calculate($p->fresh(), false);
        $this->assertFalse(collect($result['issues'])->contains('code', 'sla_coverage'));
        $line = $result['lines'][0];
        $this->assertSame(22 * 86400, $line['billing_seconds']);
        $this->assertSame(20, $line['rates'][0]['billing_days'], 'PSB 10 Aug bills 11..30.');
        $this->assertSame('1546540.00', $line['charges']['existing']);
    }
}
