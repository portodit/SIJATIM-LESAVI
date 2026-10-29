<?php

namespace Database\Seeders;

use App\Models\BandwidthOption;
use App\Models\LocationCategory;
use App\Models\PriceMaster;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PriceMastersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed master harga dari Addendum PKS Bank jatim
     */
    public function run(): void
    {
        // Check if already seeded
        if (DB::table('price_masters')->count() > 0) {
            $this->command->info('price_masters already has data. Skipping.');

            return;
        }

        $this->command->info('Seeding price_masters...');

        // Get lookup IDs
        $locationCategories = LocationCategory::pluck('id', 'location_category_code')->toArray();
        $services = Service::pluck('id', 'service_code')->toArray();
        $bandwidths = BandwidthOption::pluck('id', 'bandwidth_code')->toArray();

        $effectiveDate = '2025-01-01'; // Tanggal mulai berlaku addendum

        // Harga flat PSB
        $installationPrice = 1981982;
        $installationPriceWithPpn = 2200000;

        // Kombinasi harga dari PRD
        $prices = [
            // ATM, CRM - TELKOM VPN IP
            ['loc' => 'atm', 'svc' => 'telkom_vpn_ip', 'bw' => '128_kbps', 'price' => 1300000, 'ppn' => 1443000],
            ['loc' => 'crm', 'svc' => 'telkom_vpn_ip', 'bw' => '128_kbps', 'price' => 1300000, 'ppn' => 1443000],
            ['loc' => 'atm', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'crm', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],

            // KANTOR KAS, PAYMENT POINT, PBB, SPP ONLINE, KPP, KANTOR FUNGSIONAL - TELKOM VPN IP - 256 Kbps
            ['loc' => 'kantor_kas', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'payment_point', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'pbb', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'spp_online', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'kpp', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'kantor_fungsional', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],

            // KANTOR KAS, PAYMENT POINT, PBB, SPP ONLINE - TELKOM VPN IP - 512 Kbps
            ['loc' => 'kantor_kas', 'svc' => 'telkom_vpn_ip', 'bw' => '512_kbps', 'price' => 2319819, 'ppn' => 2574999],
            ['loc' => 'payment_point', 'svc' => 'telkom_vpn_ip', 'bw' => '512_kbps', 'price' => 2319819, 'ppn' => 2574999],
            ['loc' => 'pbb', 'svc' => 'telkom_vpn_ip', 'bw' => '512_kbps', 'price' => 2319819, 'ppn' => 2574999],
            ['loc' => 'spp_online', 'svc' => 'telkom_vpn_ip', 'bw' => '512_kbps', 'price' => 2319819, 'ppn' => 2574999],

            // SAMSAT ONLINE - TELKOM VPN IP
            ['loc' => 'samsat_online', 'svc' => 'telkom_vpn_ip', 'bw' => '128_kbps', 'price' => 1300000, 'ppn' => 1443000],
            ['loc' => 'samsat_online', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],

            // CAPEM - TELKOM VPN IP - 1 Mbps
            ['loc' => 'capem', 'svc' => 'telkom_vpn_ip', 'bw' => '1_mbps', 'price' => 2792792, 'ppn' => 3099999],

            // CABANG - TELKOM VPN IP - 2 Mbps
            ['loc' => 'cabang', 'svc' => 'telkom_vpn_ip', 'bw' => '2_mbps', 'price' => 3671171, 'ppn' => 4075000],

            // H2H - TELKOM VPN IP
            ['loc' => 'h2h', 'svc' => 'telkom_vpn_ip', 'bw' => '256_kbps', 'price' => 1950000, 'ppn' => 2164500],
            ['loc' => 'h2h', 'svc' => 'telkom_vpn_ip', 'bw' => '512_kbps', 'price' => 2319819, 'ppn' => 2574999],
            ['loc' => 'h2h', 'svc' => 'telkom_vpn_ip', 'bw' => '1_mbps', 'price' => 2792792, 'ppn' => 3099999],
            ['loc' => 'h2h', 'svc' => 'telkom_vpn_ip', 'bw' => '2_mbps', 'price' => 3671171, 'ppn' => 4075000],

            // H2H METRO - TELKOM METRO - 1 Mbps
            ['loc' => 'h2h_metro', 'svc' => 'telkom_metro', 'bw' => '1_mbps', 'price' => 2737114, 'ppn' => 3038197],

            // DC - TELKOM VPN IP - 100 Mbps
            ['loc' => 'dc', 'svc' => 'telkom_vpn_ip', 'bw' => '100_mbps', 'price' => 20776000, 'ppn' => 23061360],

            // DRC - TELKOM ASTINET - 12 Mbps
            ['loc' => 'drc', 'svc' => 'telkom_astinet', 'bw' => '12_mbps', 'price' => 2940000, 'ppn' => 3263400],

            // INTERNET KANTOR PUSAT - TELKOM ASTINET - 100 Mbps
            ['loc' => 'internet_kantor_pusat', 'svc' => 'telkom_astinet', 'bw' => '100_mbps', 'price' => 20412151, 'ppn' => 22657488],

            // WAN KANTOR PUSAT - TELKOM VPN IP - 100 Mbps
            ['loc' => 'wan_kantor_pusat', 'svc' => 'telkom_vpn_ip', 'bw' => '100_mbps', 'price' => 20776000, 'ppn' => 23061360],

            // COLOCATION - TIDAK BERLAKU
            ['loc' => 'colocation', 'svc' => 'telkom_vpn_ip', 'bw' => 'not_applicable', 'price' => 2295495, 'ppn' => 2547999],

            // JKD BI - TELKOM MANAGED NETWORK - TIDAK BERLAKU
            ['loc' => 'jkd_bi', 'svc' => 'telkom_managed_network', 'bw' => 'not_applicable', 'price' => 1412612, 'ppn' => 1567999],

            // CALL CENTER INFOMEDIA - TELKOM VPN IP - 10 Mbps
            ['loc' => 'call_center_infomedia', 'svc' => 'telkom_vpn_ip', 'bw' => '10_mbps', 'price' => 9800000, 'ppn' => 10878000],

            // Aplikasi BI-RTGS/SSSS/ETP BI - TELKOM MANAGED NETWORK - 2 Mbps
            ['loc' => 'aplikasi_bi_rtgs_ssss_etp_bi', 'svc' => 'telkom_managed_network', 'bw' => '2_mbps', 'price' => 5782000, 'ppn' => 6418020],
        ];

        $rows = [];
        foreach ($prices as $p) {
            $locId = $locationCategories[$p['loc']] ?? null;
            $svcId = $services[$p['svc']] ?? null;
            $bwId = $bandwidths[$p['bw']] ?? null;

            if (! $locId || ! $svcId || ! $bwId) {
                $this->command->warn("Skipping: loc={$p['loc']}, svc={$p['svc']}, bw={$p['bw']} - not found in master");

                continue;
            }

            $rows[] = [
                'location_category_id' => $locId,
                'service_id' => $svcId,
                'bandwidth_option_id' => $bwId,
                'monthly_price' => $p['price'],
                'monthly_price_with_ppn' => $p['ppn'],
                'installation_price' => $installationPrice,
                'installation_price_with_ppn' => $installationPriceWithPpn,
                'addendum_reference' => 'Harga Adendum-Pks',
                'effective_start_date' => $effectiveDate,
                'effective_end_date' => null,
                'notes' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert
        if (! empty($rows)) {
            foreach (array_chunk($rows, 50) as $chunk) {
                PriceMaster::insert($chunk);
            }
        }

        $this->command->info('Seeded '.count($rows).' price master records.');
    }
}
