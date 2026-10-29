<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\LocationCategory;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\TariffResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tariff precedence: SID-specific > location category > price for all categories (Non Mikrotik).
 */
class TariffPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private array $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = [
            'kas' => LocationCategory::create(['location_category_code' => 'kk', 'location_category_name' => 'KANTOR KAS']),
            'atm' => LocationCategory::create(['location_category_code' => 'atm', 'location_category_name' => 'ATM']),
            'vpn' => Service::create(['service_code' => 'vpn', 'service_name' => 'TELKOM VPN IP']),
            '1m' => BandwidthOption::create(['bandwidth_code' => '1m', 'bandwidth_name' => '1 Mbps', 'value_kbps' => 1024]),
        ];
    }

    private function tariff(?LocationCategory $category, int $price, array $extra = []): PriceMaster
    {
        return PriceMaster::create(array_merge(['location_category_id' => $category?->id, 'service_id' => $this->m['vpn']->id, 'bandwidth_option_id' => $this->m['1m']->id, 'router_mode' => 'none', 'monthly_price' => $price, 'effective_start_date' => '2025-01-01', 'is_active' => true], $extra));
    }

    private function price(LocationCategory $category, string $sid = '4700020-1'): array
    {
        $service = ['sid' => $sid, 'location_category_id' => $category->id, 'service_id' => $this->m['vpn']->id];

        return (new TariffResolver)->resolve($service, $this->m['1m']->id, 'none', Carbon::parse('2026-07-15'), []);
    }

    public function test_price_for_all_categories_applies_when_the_category_has_none(): void
    {
        $this->tariff(null, 2792792);
        $this->assertSame('2792792.00', $this->price($this->m['kas'])['rate']['monthly_price']);
        $this->assertSame('2792792.00', $this->price($this->m['atm'])['rate']['monthly_price']);
    }

    public function test_category_price_beats_the_general_price_and_sid_price_beats_both(): void
    {
        $this->tariff(null, 2792792);
        $this->tariff($this->m['kas'], 2737114);
        $this->assertSame('2737114.00', $this->price($this->m['kas'])['rate']['monthly_price']);
        $this->assertSame('2792792.00', $this->price($this->m['atm'])['rate']['monthly_price'], 'Other categories keep the general price.');

        $this->tariff(null, 1000000, ['sid' => '4700020-SPECIAL']);
        $this->assertSame('1000000.00', $this->price($this->m['kas'], '4700020-SPECIAL')['rate']['monthly_price']);
    }

    public function test_two_general_prices_for_the_same_combination_are_still_reported_as_overlapping(): void
    {
        $this->tariff(null, 2792792);
        $this->tariff(null, 2800000);
        $this->assertSame('Tarif berlaku saling tumpang tindih.', $this->price($this->m['kas'])['error']);
    }

    public function test_master_harga_saves_and_lists_a_price_for_all_categories(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/billing-masters/tariffs', ['location_category_id' => '', 'service_id' => $this->m['vpn']->id, 'bandwidth_option_id' => $this->m['1m']->id, 'router_mode' => 'none', 'monthly_price' => '2792792', 'relocation_price' => '0', 'effective_start_date' => '2025-01-01', 'is_active' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(PriceMaster::sole()->location_category_id);
        $this->get(route('price-masters.index', ['section' => 'tariffs']))->assertOk()->assertSee('<td>Semua kategori</td>', false)->assertSee('Semua kategori (harga umum per bandwidth)');
        // A new tariff form must not preselect "all categories".
        $this->get(route('price-masters.index', ['section' => 'tariffs', 'new' => 1]))->assertOk()->assertDontSee('<option value="" selected>Semua kategori', false);
    }
}
