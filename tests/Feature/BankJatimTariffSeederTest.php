<?php

namespace Tests\Feature;

use App\Models\BandwidthOption;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\BankJatimTariffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankJatimTariffSeederTest extends TestCase
{
    use RefreshDatabase;

    private function masters(): array
    {
        $atm = LocationCategory::create(['location_category_code' => 'atm', 'location_category_name' => 'ATM']);
        $crm = LocationCategory::create(['location_category_code' => 'crm', 'location_category_name' => 'CRM']);
        $colo = LocationCategory::create(['location_category_code' => 'colo', 'location_category_name' => 'COLOCATION']);
        $vpn = Service::create(['service_code' => 'vpn', 'service_name' => 'TELKOM VPN IP']);
        $neu = Service::create(['service_code' => 'neu', 'service_name' => 'TELKOM NEUCENTRIX']);
        $bw128 = BandwidthOption::create(['bandwidth_code' => '128', 'bandwidth_name' => '128 Kbps', 'value_kbps' => 128]);
        BandwidthOption::create(['bandwidth_code' => '256', 'bandwidth_name' => '256 Kbps', 'value_kbps' => 256]);
        $na = BandwidthOption::create(['bandwidth_code' => 'na', 'bandwidth_name' => 'TIDAK BERLAKU', 'is_not_applicable' => true]);

        return compact('atm', 'crm', 'colo', 'vpn', 'neu', 'bw128', 'na');
    }

    public function test_seeds_both_price_tables_idempotently_and_reports_what_it_skipped(): void
    {
        ['atm' => $atm, 'crm' => $crm, 'vpn' => $vpn, 'bw128' => $bw128] = $this->masters();
        $seeder = new BankJatimTariffSeeder;

        $first = $seeder->apply();
        // ATM, CRM x (128, 256) x (tanpa, dengan router) + COLOCATION x 2 = 10, plus 4 Non Mikrotik prices
        // for all categories (VPN 128/256 and Neucentrix without router, VPN 128 with router)
        $this->assertSame(14, $first['created']);
        $this->assertSame(0, $first['exists']);
        $this->assertContains('CAPEM | TELKOM VPN IP | 1 Mbps | tanpa router (tidak ada di Master)', $first['skipped']);
        $this->assertContains('Semua kategori | TELKOM VPN IP | 1 Mbps | tanpa router (tidak ada di Master)', $first['skipped']);
        $general = PriceMaster::whereNull('location_category_id')->where('bandwidth_option_id', $bw128->id)->where('router_mode', 'managed')->sole();
        $this->assertSame('1370000.00', $general->monthly_price);

        $none = PriceMaster::where('location_category_id', $atm->id)->where('service_id', $vpn->id)->where('bandwidth_option_id', $bw128->id)->where('router_mode', 'none')->sole();
        $this->assertSame('1300000.00', $none->monthly_price);
        $this->assertSame('1443000.00', $none->monthly_price_with_ppn);
        $this->assertSame('1981982.00', $none->installation_price);
        $this->assertSame('0.00', $none->relocation_price);
        $this->assertSame('2025-01-01', $none->effective_start_date->toDateString());
        $managed = PriceMaster::where('location_category_id', $crm->id)->where('bandwidth_option_id', $bw128->id)->where('router_mode', 'managed')->sole();
        $this->assertSame('1370000.00', $managed->monthly_price);

        $second = $seeder->apply();
        $this->assertSame(0, $second['created']);
        $this->assertSame(14, $second['exists']);
        $this->assertSame(14, PriceMaster::count());
    }

    public function test_existing_prices_are_never_overwritten_and_not_applicable_bandwidth_is_not_duplicated(): void
    {
        ['atm' => $atm, 'colo' => $colo, 'vpn' => $vpn, 'neu' => $neu, 'bw128' => $bw128] = $this->masters();
        $old = PriceMaster::create(['location_category_id' => $atm->id, 'service_id' => $vpn->id, 'bandwidth_option_id' => $bw128->id, 'router_mode' => 'none', 'monthly_price' => 999, 'effective_start_date' => '2024-01-01', 'is_active' => true]);
        // An older tariff stored with no bandwidth at all must count as the same "TIDAK BERLAKU" combination.
        PriceMaster::create(['location_category_id' => $colo->id, 'service_id' => $neu->id, 'bandwidth_option_id' => null, 'router_mode' => 'none', 'monthly_price' => 2295495, 'effective_start_date' => '2024-01-01', 'is_active' => true]);

        $result = (new BankJatimTariffSeeder)->apply();

        $this->assertSame('999.00', $old->fresh()->monthly_price);
        $this->assertCount(1, $result['differs']);
        $this->assertStringContainsString('ATM | TELKOM VPN IP | 128 Kbps | tanpa router', $result['differs'][0]);
        $this->assertSame(1, PriceMaster::where('location_category_id', $colo->id)->where('router_mode', 'none')->count());
        $this->assertSame(1, $result['exists']);
    }

    public function test_open_periods_are_marked_for_recalculation_but_locked_ones_are_untouched(): void
    {
        $this->masters();
        $user = User::factory()->create();
        $open = InvoicePeriod::create(['month' => 9, 'year' => 2026, 'created_by' => $user->id]);
        $locked = InvoicePeriod::create(['month' => 8, 'year' => 2026, 'is_locked' => true, 'created_by' => $user->id]);

        (new BankJatimTariffSeeder)->apply();
        $this->assertSame(2, $open->fresh()->input_version);
        $this->assertSame(1, $locked->fresh()->input_version);

        (new BankJatimTariffSeeder)->apply();
        $this->assertSame(2, $open->fresh()->input_version, 'A run that adds nothing must not invalidate calculations.');
    }
}
