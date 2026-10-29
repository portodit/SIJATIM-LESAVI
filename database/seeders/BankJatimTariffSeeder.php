<?php

namespace Database\Seeders;

use App\Models\BandwidthOption;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\PriceMaster;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Daftar harga Bank Jatim: Tabel 1 (tanpa router), Tabel 2 (dengan managed router), dan daftar
 * Non Mikrotik (harga per bandwidth untuk semua kategori, dipakai saat upgrade/downgrade).
 *
 * Aman dijalankan ulang: kombinasi yang sudah ada dilewati dan harga yang sudah ada tidak pernah
 * ditimpa (bila berbeda, dilaporkan). Baris yang kategori/layanan/bandwidth-nya tidak ada di
 * Master dilewati dan dilaporkan.
 *
 *   php artisan db:seed --class=BankJatimTariffSeeder
 */
class BankJatimTariffSeeder extends Seeder
{
    /** Sama dengan tarif yang sudah ada di Master (Adendum PKS). */
    private const START_DATE = '2025-01-01';

    /** Biaya pasang baru (PSB) flat: tanpa PPN dan dengan PPN. */
    private const INSTALLATION = 1981982;

    private const INSTALLATION_WITH_PPN = 2200000;

    private const VPN = 'TELKOM VPN IP';

    /**
     * Baris "KK, PP, PBB, SP2D, KPP, KF, UPTD, UPTB, Kantor Kasir, DPPKAD, SPP Online".
     * SP2D, DPPKAD, dan "Kantor Kasir" (= KK) tidak punya kategori di Master dan tidak dipakai SSID mana pun.
     */
    private const COUNTER = ['KANTOR KAS', 'PAYMENT POINT', 'PBB', 'KPP', 'KANTOR FUNGSIONAL', 'UPTD', 'UPTB', 'SPP ONLINE'];

    /**
     * router_mode => [kategori[], layanan, bandwidth, harga bulanan, harga bulanan + PPN].
     * "-" pada bandwidth berarti TIDAK BERLAKU. "BW Pelaporan BI DC" tidak punya kategori di Master dan tidak dimasukkan.
     *
     * @return array<string, list<array{0: list<string>, 1: string, 2: string, 3: int, 4: int}>>
     */
    public static function priceList(): array
    {
        $vpn = self::VPN;
        $aplikasiBi = 'Aplikasi BI-RTGS/SSSS/ETP BI';

        return [
            // Tabel 1: tanpa router
            'none' => [
                [['ATM', 'CRM'], $vpn, '128 Kbps', 1300000, 1443000],
                [['ATM', 'CRM'], $vpn, '256 Kbps', 1950000, 2164500],
                [self::COUNTER, $vpn, '256 Kbps', 1950000, 2164500],
                [self::COUNTER, $vpn, '512 Kbps', 2319819, 2574999],
                [['SAMSAT ONLINE'], $vpn, '128 Kbps', 1300000, 1443000],
                [['SAMSAT ONLINE'], $vpn, '256 Kbps', 1950000, 2164500],
                [['CAPEM'], $vpn, '1 Mbps', 2792792, 3099999],
                [['CABANG'], $vpn, '2 Mbps', 3671171, 4075000],
                [['H2H'], $vpn, '256 Kbps', 1950000, 2164500],
                [['H2H'], $vpn, '512 Kbps', 2319819, 2574999],
                [['H2H'], $vpn, '1 Mbps', 2792792, 3099999],
                [['H2H'], $vpn, '2 Mbps', 3671171, 4075000],
                [['H2H METRO'], 'TELKOM METRO', '1 Mbps', 2737114, 3038197],
                [['DC'], $vpn, '100 Mbps', 20776000, 23061360],
                [['DRC'], 'TELKOM ASTINET', '12 Mbps', 2940000, 3263400],
                [['INTERNET KANTOR PUSAT'], 'TELKOM ASTINET', '100 Mbps', 20412151, 22657488],
                [['WAN KANTOR PUSAT'], $vpn, '100 Mbps', 20776000, 23061360],
                [['COLOCATION'], 'TELKOM NEUCENTRIX', '-', 2295495, 2547999],
                [['JKD BI'], 'TELKOM MANAGED NETWORK', '-', 1412612, 1567999],
                [['CALL CENTER INFOMEDIA'], $vpn, '10 Mbps', 9800000, 10878000],
                [[$aplikasiBi], 'TELKOM MANAGED NETWORK', '2 Mbps', 5782000, 6418020],
            ],
            // Tabel 2: dengan managed router (baris tanpa "+ Managed Router" di tabel asal harganya sama dengan Tabel 1)
            'managed' => [
                [['ATM', 'CRM'], $vpn, '128 Kbps', 1370000, 1520700],
                [['ATM', 'CRM'], $vpn, '256 Kbps', 2020000, 2242200],
                [self::COUNTER, $vpn, '256 Kbps', 2020000, 2242200],
                [self::COUNTER, $vpn, '512 Kbps', 2389819, 2652699],
                [['SAMSAT ONLINE'], $vpn, '128 Kbps', 1300000, 1443000],
                [['SAMSAT ONLINE'], $vpn, '256 Kbps', 1950000, 2164500],
                [['CAPEM'], $vpn, '1 Mbps', 2792792, 3099999],
                [['CABANG'], $vpn, '2 Mbps', 3671171, 4075000],
                [['H2H'], $vpn, '256 Kbps', 2020000, 2242200],
                [['H2H'], $vpn, '512 Kbps', 2389819, 2652699],
                [['H2H'], $vpn, '1 Mbps', 2862792, 3177699],
                [['H2H'], $vpn, '2 Mbps', 3741171, 4152700],
                [['H2H METRO'], 'TELKOM METRO', '1 Mbps', 2737114, 3038197],
                [['DC'], $vpn, '100 Mbps', 20776000, 23061360],
                [['DRC'], $vpn, '100 Mbps', 20776000, 23061360],
                [['DRC'], 'TELKOM ASTINET', '12 Mbps', 2940000, 3263400],
                [['INTERNET KANTOR PUSAT'], 'TELKOM ASTINET', '100 Mbps', 20412151, 22657488],
                [['WAN KANTOR PUSAT'], $vpn, '100 Mbps', 20776000, 23061360],
                [['COLOCATION'], 'TELKOM NEUCENTRIX', '-', 2295495, 2547999],
                [['JKD BI'], 'TELKOM MANAGED NETWORK', '-', 1412612, 1567999],
                [['CALL CENTER INFOMEDIA'], $vpn, '10 Mbps', 9800000, 10878000],
                [[$aplikasiBi], 'TELKOM MANAGED NETWORK', '2 Mbps', 5782000, 6418020],
            ],
        ];
    }

    /**
     * Daftar harga Non Mikrotik: harga per bandwidth untuk SEMUA kategori (kategori null). Dipakai bila SSID
     * di-upgrade/downgrade ke bandwidth yang tidak punya harga kategori; harga kategori tetap didahulukan.
     * Tanpa akhiran = TELKOM VPN IP. "1 Mbps-H2H" (2.737.114) adalah H2H Metro yang memakai layanan VPN IP
     * (H2H biasa 1 Mbps ditagih 2.792.792), sehingga dicatat sebagai harga kategori H2H METRO.
     *
     * @return array<string, list<array{0: list<string|null>, 1: string, 2: string, 3: int, 4: int}>>
     */
    public static function nonMikrotik(): array
    {
        $vpn = self::VPN;
        $all = [null];

        return [
            'none' => [
                [$all, $vpn, '32 Kbps', 1300000, 1443000],
                [$all, $vpn, '128 Kbps', 1300000, 1443000],
                [$all, $vpn, '256 Kbps', 1950000, 2164500],
                [$all, $vpn, '512 Kbps', 2319819, 2574999],
                [$all, $vpn, '1 Mbps', 2792792, 3099999],
                [$all, $vpn, '2 Mbps', 3671171, 4075000],
                [$all, $vpn, '10 Mbps', 9800000, 10878000],
                [$all, $vpn, '12 Mbps', 2940000, 3263400],
                [$all, $vpn, '100 Mbps', 20776000, 23061360],
                [$all, 'TELKOM METRO', '1 Mbps', 2737114, 3038197],            // 1 Mbps-Metro
                [$all, 'TELKOM ASTINET', '12 Mbps', 2940000, 3263400],        // 12 Mbps-AST
                [$all, 'TELKOM ASTINET', '100 Mbps', 20412151, 22657488],     // 100 Mbps-KP
                [$all, 'TELKOM NEUCENTRIX', '-', 2295495, 2547999],           // COLC
                [$all, 'TELKOM MANAGED NETWORK', '-', 1412612, 1567999],      // TMN
                [$all, 'TELKOM MANAGED NETWORK', '2 Mbps', 5782000, 6418020], // 2 Mbps-TMN
                [['H2H METRO'], $vpn, '1 Mbps', 2737114, 3038197],            // 1 Mbps-H2H
            ],
            'managed' => [
                [$all, $vpn, '128 Kbps', 1370000, 1520700],                   // 128 Kbps-Managed
                [$all, $vpn, '512 Kbps', 2389819, 2652699],                   // 512 Kbps-Manged, 512 Kbps-H2H
            ],
        ];
    }

    public function run(): void
    {
        $result = $this->apply();
        $this->command?->info("Tarif baru: {$result['created']} | sudah ada: {$result['exists']} | harga berbeda (tidak ditimpa): ".count($result['differs']).' | dilewati: '.count($result['skipped']));
        foreach ($result['differs'] as $line) {
            $this->command?->warn('Harga berbeda: '.$line);
        }
        foreach ($result['skipped'] as $line) {
            $this->command?->warn('Dilewati: '.$line);
        }
    }

    /**
     * @return array{created: int, exists: int, differs: list<string>, skipped: list<string>}
     */
    public function apply(): array
    {
        $key = fn (string $name) => mb_strtolower(trim($name));
        $categories = LocationCategory::all()->mapWithKeys(fn ($m) => [$key($m->location_category_name) => $m->id]);
        $services = Service::all()->mapWithKeys(fn ($m) => [$key($m->service_name) => $m->id]);
        $bandwidths = BandwidthOption::all()->mapWithKeys(fn ($m) => [$key($m->bandwidth_name) => $m->id]);
        $notApplicable = BandwidthOption::where('is_not_applicable', true)->value('id');
        $result = ['created' => 0, 'exists' => 0, 'differs' => [], 'skipped' => []];

        DB::transaction(function () use ($key, $categories, $services, $bandwidths, $notApplicable, &$result) {
            foreach (array_merge_recursive(self::priceList(), self::nonMikrotik()) as $router => $lines) {
                foreach ($lines as [$names, $service, $bandwidth, $price, $priceWithPpn]) {
                    foreach ($names as $category) {
                        $label = ($category ?? 'Semua kategori').' | '.$service.' | '.$bandwidth.' | '.($router === 'managed' ? 'dengan router' : 'tanpa router');
                        // null = price for all categories; false = category missing from the master
                        $categoryId = $category === null ? null : ($categories[$key($category)] ?? false);
                        $serviceId = $services[$key($service)] ?? null;
                        $bandwidthId = $bandwidth === '-' ? $notApplicable : ($bandwidths[$key($bandwidth)] ?? null);
                        if ($categoryId === false || ! $serviceId || ! $bandwidthId) {
                            $result['skipped'][] = $label.' (tidak ada di Master)';

                            continue;
                        }
                        // The resolver treats NULL and "TIDAK BERLAKU" as the same bandwidth, so look for both.
                        $existing = PriceMaster::where('is_active', true)->where('location_category_id', $categoryId)->where('service_id', $serviceId)
                            ->where('router_mode', $router)->whereNull('sid')->whereNull('addendum_contract_id')
                            ->where(fn ($q) => $bandwidthId === $notApplicable ? $q->whereNull('bandwidth_option_id')->orWhere('bandwidth_option_id', $bandwidthId) : $q->where('bandwidth_option_id', $bandwidthId))
                            ->get();
                        if ($existing->isNotEmpty()) {
                            if ($existing->contains(fn ($e) => abs((float) $e->monthly_price - $price) < 0.005)) {
                                $result['exists']++;
                            } else {
                                $result['differs'][] = $label.': file Rp '.number_format($price, 0, ',', '.').', Master Rp '.number_format((float) $existing->first()->monthly_price, 0, ',', '.');
                            }

                            continue;
                        }
                        PriceMaster::create([
                            'location_category_id' => $categoryId, 'service_id' => $serviceId, 'bandwidth_option_id' => $bandwidthId,
                            'router_mode' => $router, 'monthly_price' => $price, 'monthly_price_with_ppn' => $priceWithPpn,
                            'installation_price' => self::INSTALLATION, 'installation_price_with_ppn' => self::INSTALLATION_WITH_PPN, 'relocation_price' => 0,
                            'addendum_reference' => $router === 'managed' ? 'Harga Adendum-Pks (dengan managed router)' : 'Harga Adendum-Pks',
                            'effective_start_date' => self::START_DATE, 'is_active' => true,
                        ]);
                        $result['created']++;
                    }
                }
            }
            if ($result['created']) {
                DB::table('billing_audit_events')->insert(['action' => 'admin_tariffs_bulk_added', 'details' => json_encode(['source' => self::class, 'created' => $result['created']]), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
                // Open periods must recalculate to pick up the new prices.
                foreach (InvoicePeriod::where('is_locked', false)->where('status', '!=', 'finalized')->get() as $period) {
                    $period->markInputsChanged('master_tariffs_updated', ['source' => self::class, 'created' => $result['created']]);
                }
            }
        });

        return $result;
    }
}
