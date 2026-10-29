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
use App\Models\TypeOrder;
use App\Models\User;
use App\Services\Billing\InvoiceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableSortingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SEP26 invoice with one line per entry: ['ssid', 'kbps', 'price', 'down' (seconds, null = not filled)].
     * Line numbers follow the order of the entries.
     */
    private function period(array $services): InvoicePeriod
    {
        $this->actingAs(User::factory()->create());
        $office = OfficeType::create(['office_type_code' => 'TEST', 'office_type_name' => 'Cabang Test', 'sort_order' => 1]);
        $category = LocationCategory::create(['location_category_code' => 'KK', 'location_category_name' => 'KK']);
        $service = Service::create(['service_code' => 'VPN', 'service_name' => 'TELKOM VPN IP']);
        TypeOrder::create(['type_order_code' => 'existing', 'type_order_name' => 'Existing', 'sort_order' => 1]);
        $p = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => auth()->id()]);

        $bandwidths = [];
        foreach ($services as $i => $s) {
            $bw = $bandwidths[$s['kbps']] ??= BandwidthOption::create(['bandwidth_code' => (string) $s['kbps'], 'bandwidth_name' => $s['kbps'] >= 1024 ? ($s['kbps'] / 1024).' Mbps' : $s['kbps'].' Kbps', 'value_kbps' => $s['kbps']]);
            PriceMaster::firstOrCreate(
                ['location_category_id' => $category->id, 'service_id' => $service->id, 'bandwidth_option_id' => $bw->id],
                ['monthly_price' => $s['price'], 'installation_price' => '0', 'relocation_price' => '0', 'router_mode' => 'none', 'effective_start_date' => '2020-01-01', 'is_active' => true],
            );
            $master = BankJatimLink::create(['ssid' => $s['ssid'], 'office_type_id' => $office->id, 'sequence_in_office_type' => $i + 1, 'location_category_id' => $category->id, 'service_id' => $service->id, 'current_bandwidth_option_id' => $bw->id, 'address' => 'Jl. Test '.($i + 1), 'service_start_date' => '2020-01-01', 'link_status' => 'active']);
            InvoiceLine::create(['invoice_period_id' => $p->id, 'bank_jatim_link_id' => $master->id, 'line_number' => $i + 1, 'existing_bandwidth_option_id' => $bw->id, 'address_snapshot' => $master->address]);
            if ($s['down'] !== null) {
                $this->post(route('billing.downtime', $p), ['sid' => $s['ssid'], 'downtime_seconds' => $s['down']])->assertRedirect();
            }
        }

        return $p;
    }

    private function step1Sids(InvoicePeriod $p, array $query = []): array
    {
        return $this->get(route('invoice-periods.step1', [$p] + $query))->assertOk()->viewData('rows')->pluck('sid')->all();
    }

    public function test_step1_sorts_by_downtime_and_keeps_services_without_downtime_last(): void
    {
        $p = $this->period([
            ['ssid' => 'S-A', 'kbps' => 512, 'price' => '1000', 'down' => 3600],
            ['ssid' => 'S-B', 'kbps' => 512, 'price' => '1000', 'down' => null],
            ['ssid' => 'S-C', 'kbps' => 512, 'price' => '1000', 'down' => 7200],
            ['ssid' => 'S-D', 'kbps' => 512, 'price' => '1000', 'down' => 60],
            ['ssid' => 'S-E', 'kbps' => 512, 'price' => '1000', 'down' => null],
        ]);

        $this->assertSame(['S-C', 'S-A', 'S-D', 'S-B', 'S-E'], $this->step1Sids($p, ['sort' => 'downtime', 'dir' => 'desc']));
        $this->assertSame(['S-D', 'S-A', 'S-C', 'S-B', 'S-E'], $this->step1Sids($p, ['sort' => 'downtime', 'dir' => 'asc']));
        // First click on a numeric column goes from biggest to smallest.
        $this->assertSame(['S-C', 'S-A', 'S-D', 'S-B', 'S-E'], $this->step1Sids($p, ['sort' => 'downtime']));

        // The rendered table follows the same order, and the active header says so.
        $this->get(route('invoice-periods.step1', [$p, 'sort' => 'downtime', 'dir' => 'desc']))->assertOk()
            ->assertSeeInOrder(['<span class="font-semibold">S-C</span>', '<span class="font-semibold">S-A</span>', '<span class="font-semibold">S-D</span>', '<span class="font-semibold">S-B</span>'], false)
            ->assertSee('aria-sort="descending"', false);
    }

    public function test_step1_invalid_sort_falls_back_to_the_default_order(): void
    {
        $p = $this->period([
            ['ssid' => 'S-A', 'kbps' => 512, 'price' => '1000', 'down' => 3600],
            ['ssid' => 'S-B', 'kbps' => 512, 'price' => '1000', 'down' => null],
            ['ssid' => 'S-C', 'kbps' => 512, 'price' => '1000', 'down' => 7200],
        ]);
        $default = ['S-A', 'S-B', 'S-C'];

        $this->assertSame($default, $this->step1Sids($p));
        $this->assertSame($default, $this->step1Sids($p, ['sort' => 'password', 'dir' => 'desc']));
        $this->assertSame($default, $this->step1Sids($p, ['sort' => ['downtime'], 'dir' => 'desc']));
        $this->assertSame($default, $this->step1Sids($p, ['sort' => 'downtime_seconds']));
        $this->assertSame($default, $this->step1Sids($p, ['sort' => 'number', 'dir' => 'asc']));
        $this->assertSame(['S-C', 'S-B', 'S-A'], $this->step1Sids($p, ['sort' => 'number', 'dir' => 'desc']));
        // A valid column with a nonsense direction uses the column's default direction.
        $this->assertSame(['S-C', 'S-A', 'S-B'], $this->step1Sids($p, ['sort' => 'downtime', 'dir' => 'sideways']));
    }

    public function test_step1_sorts_text_naturally_and_bandwidth_by_speed_not_by_name(): void
    {
        $p = $this->period([
            ['ssid' => 'SID-10', 'kbps' => 512, 'price' => '1000', 'down' => null],
            ['ssid' => 'SID-9', 'kbps' => 10240, 'price' => '1000', 'down' => null],
            ['ssid' => 'SID-2', 'kbps' => 1024, 'price' => '1000', 'down' => null],
        ]);

        $this->assertSame(['SID-2', 'SID-9', 'SID-10'], $this->step1Sids($p, ['sort' => 'sid']));
        $this->assertSame(['SID-10', 'SID-9', 'SID-2'], $this->step1Sids($p, ['sort' => 'sid', 'dir' => 'desc']));
        // Names are "512 Kbps", "1 Mbps", "10 Mbps": by text "1 Mbps" would come first.
        $this->assertSame(['SID-10', 'SID-2', 'SID-9'], $this->step1Sids($p, ['sort' => 'bandwidth', 'dir' => 'asc']));
        $this->assertSame(['SID-9', 'SID-2', 'SID-10'], $this->step1Sids($p, ['sort' => 'bandwidth']));
    }

    public function test_step1_sort_covers_all_pages_and_pagination_links_keep_it(): void
    {
        $services = [];
        foreach (range(1, 55) as $n) {
            $services[] = ['ssid' => sprintf('S-%02d', $n), 'kbps' => 512, 'price' => '1000', 'down' => $n === 55 ? 5000 : ($n === 1 ? 100 : null)];
        }
        $p = $this->period($services);

        $first = $this->get(route('invoice-periods.step1', [$p, 'sort' => 'downtime', 'dir' => 'desc']))->assertOk();
        $this->assertCount(50, $first->viewData('rows'));
        $this->assertSame(['S-55', 'S-01', 'S-02'], $first->viewData('rows')->take(3)->pluck('sid')->all());
        $this->assertStringContainsString('sort=downtime&dir=desc&page=2', urldecode($first->viewData('lines')->nextPageUrl()));

        // Page 2 holds what is left, still in line order for the services without downtime.
        $this->assertSame(['S-50', 'S-51', 'S-52', 'S-53', 'S-54'], $this->step1Sids($p, ['sort' => 'downtime', 'dir' => 'desc', 'page' => 2]));
        $this->assertSame(55, $this->get(route('invoice-periods.step1', [$p, 'sort' => 'downtime']))->viewData('lines')->total());
    }

    public function test_sort_links_keep_search_and_filters_toggle_direction_and_drop_the_page(): void
    {
        $p = $this->period([
            ['ssid' => 'S-A', 'kbps' => 512, 'price' => '1000', 'down' => 3600],
            ['ssid' => 'S-B', 'kbps' => 512, 'price' => '1000', 'down' => 60],
        ]);
        $base = route('invoice-periods.step1', $p);

        $this->get(route('invoice-periods.step1', [$p, 'search' => 'S-', 'filter' => 'downtime', 'sort' => 'downtime', 'dir' => 'desc', 'page' => 1]))->assertOk()
            // Active column flips, other columns start at their own default direction.
            ->assertSee('href="'.$base.'?search=S-&amp;filter=downtime&amp;sort=downtime&amp;dir=asc"', false)
            ->assertSee('href="'.$base.'?search=S-&amp;filter=downtime&amp;sort=sid&amp;dir=asc"', false)
            ->assertSee('href="'.$base.'?search=S-&amp;filter=downtime&amp;sort=sla&amp;dir=desc"', false)
            // Columns that cannot be sorted stay plain headers.
            ->assertSee('<th>Tanggal efektif</th>', false)
            // Filter chips and the search form keep the chosen sort.
            ->assertSee('href="'.$base.'?filter=changed&amp;search=S-&amp;sort=downtime&amp;dir=desc"', false)
            ->assertSee('<input type="hidden" name="sort" value="downtime">', false);
    }

    public function test_step2_sorts_money_numerically_over_all_rows(): void
    {
        // Prices are money strings: as text "12000000" < "2319819" < "900", as numbers it is the other way around.
        // S-D loses a day to downtime (SLA credit); S-E has no downtime yet, so SLA, credit and net are empty.
        $p = $this->period([
            ['ssid' => 'S-A', 'kbps' => 512, 'price' => '2319819', 'down' => 0],
            ['ssid' => 'S-B', 'kbps' => 1024, 'price' => '900', 'down' => 0],
            ['ssid' => 'S-C', 'kbps' => 2048, 'price' => '12000000', 'down' => 0],
            ['ssid' => 'S-D', 'kbps' => 4096, 'price' => '5000', 'down' => 86400],
            ['ssid' => 'S-E', 'kbps' => 8192, 'price' => '5000000', 'down' => null],
        ]);
        app(InvoiceCalculator::class)->calculate($p->fresh());
        $sids = fn (array $query) => $this->get(route('invoice-periods.step2', [$p] + $query))->assertOk()->viewData('lines')->getCollection()->pluck('sid')->all();

        $this->assertSame(['S-A', 'S-B', 'S-C', 'S-D', 'S-E'], $sids([]));
        $this->assertSame(['S-B', 'S-D', 'S-A', 'S-C', 'S-E'], $sids(['sort' => 'net', 'dir' => 'asc']));
        $this->assertSame(['S-C', 'S-A', 'S-D', 'S-B', 'S-E'], $sids(['sort' => 'net', 'dir' => 'desc']));
        $this->assertSame(['S-B', 'S-D', 'S-A', 'S-E', 'S-C'], $sids(['sort' => 'gross', 'dir' => 'asc']));
        // Ties (the three zero credits) keep the line order; the SSID without a calculation is always last.
        $this->assertSame(['S-D', 'S-A', 'S-B', 'S-C', 'S-E'], $sids(['sort' => 'sla_credit']));
        $this->assertSame(['S-D', 'S-A', 'S-B', 'S-C', 'S-E'], $sids(['sort' => 'downtime_seconds']));
        $this->assertSame(['S-D', 'S-A', 'S-B', 'S-C', 'S-E'], $sids(['sort' => 'sla', 'dir' => 'asc']));
        $this->assertSame(['S-E', 'S-D', 'S-C', 'S-B', 'S-A'], $sids(['sort' => 'sid', 'dir' => 'desc']));
        $this->assertSame(['S-A', 'S-B', 'S-C', 'S-D', 'S-E'], $sids(['sort' => 'net_total', 'dir' => 'asc']));
        $this->assertSame(['S-D'], $sids(['search' => 'S-D', 'sort' => 'net', 'dir' => 'asc']));

        $this->get(route('invoice-periods.step2', [$p, 'sort' => 'net', 'dir' => 'asc']))->assertOk()
            ->assertSee('aria-sort="ascending"', false)
            ->assertSee('href="'.route('invoice-periods.step2', $p).'?sort=net&amp;dir=desc"', false)
            ->assertSee('href="'.route('invoice-periods.step2', $p).'?sort=gross&amp;dir=desc"', false);
    }
}
