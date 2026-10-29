<?php

namespace App\Services\Billing;

use App\Models\BandwidthOption;
use App\Models\PriceMaster;
use Carbon\Carbon;

final class TariffResolver
{
    private array $rates;

    private array $notApplicable;

    public function __construct()
    {
        $this->rates = PriceMaster::with('contract')->where('is_active', true)->get()->toArray();
        $this->notApplicable = BandwidthOption::where('is_not_applicable', true)->pluck('id')->all();
    }

    public function resolve(array $service, ?int $bandwidth, string $router, Carbon $date, array $overrides): array
    {
        if (in_array($bandwidth, $this->notApplicable, true)) {
            $bandwidth = null;
        }
        $rates = array_values(array_filter($this->rates, function ($r) use ($service, $bandwidth, $router, $date) {
            return ($r['location_category_id'] === null || (int) $r['location_category_id'] === (int) $service['location_category_id'])
                && (int) $r['service_id'] === (int) $service['service_id']
                && (int) (in_array($r['bandwidth_option_id'], $this->notApplicable, true) ? 0 : ($r['bandwidth_option_id'] ?? 0)) === (int) ($bandwidth ?? 0)
                && $r['router_mode'] === $router
                && (! $r['sid'] || $r['sid'] === $service['sid'])
                && (! isset($service['contract_id']) || (int) $r['addendum_contract_id'] === (int) $service['contract_id'])
                && (! $r['addendum_contract_id'] || (! empty($r['contract']) && Carbon::parse($r['contract']['effective_date'])->lte($date) && (! $r['contract']['end_date'] || Carbon::parse($r['contract']['end_date'])->endOfDay()->gte($date))))
                && Carbon::parse($r['effective_start_date'])->startOfDay()->lte($date)
                && (! $r['effective_end_date'] || Carbon::parse($r['effective_end_date'])->endOfDay()->gte($date));
        }));
        // Most specific wins: a SID-specific tariff, then the service's category, then the price for all categories.
        $specific = array_values(array_filter($rates, fn ($r) => $r['sid'] === $service['sid']));
        $byCategory = array_values(array_filter($rates, fn ($r) => $r['location_category_id'] !== null));
        if ($specific) {
            $rates = $specific;
        } elseif ($byCategory) {
            $rates = $byCategory;
        }
        if (count($overrides) > 1) {
            return ['error' => 'Beberapa override tarif untuk SID yang sama.'];
        }
        if ($overrides) {
            $o = $overrides[0];

            return ['rate' => array_merge($rates[0] ?? [], [
                'monthly_price' => $o['amount'], 'override' => $o,
                'installation_price' => $o['attributes']['installation_price'] ?? ($rates[0]['installation_price'] ?? null),
                'relocation_price' => $o['attributes']['relocation_price'] ?? ($rates[0]['relocation_price'] ?? null),
            ])];
        }

        return count($rates) === 1 ? ['rate' => $rates[0]] : ['error' => count($rates) ? 'Tarif berlaku saling tumpang tindih.' : 'Tarif belum tersedia untuk kategori, layanan, bandwidth, router, dan tanggal ini.'];
    }

    /**
     * Tariffs of the service's service and category (or all categories) valid on $date whose monthly
     * price is $price: they show which bandwidth/router a source price belongs to.
     *
     * @return list<array{bandwidth_option_id: ?int, router_mode: string, location_category_id: ?int}>
     */
    public function withPrice(array $service, string $price, Carbon $date): array
    {
        $found = array_filter($this->rates, fn ($r) => (int) $r['service_id'] === (int) ($service['service_id'] ?? 0)
            && ($r['location_category_id'] === null || (int) $r['location_category_id'] === (int) ($service['location_category_id'] ?? 0))
            && (! $r['sid'] || $r['sid'] === ($service['sid'] ?? null))
            && Carbon::parse($r['effective_start_date'])->startOfDay()->lte($date)
            && (! $r['effective_end_date'] || Carbon::parse($r['effective_end_date'])->endOfDay()->gte($date))
            && \Brick\Math\BigDecimal::of($r['monthly_price'])->isEqualTo($price));

        return array_values(array_map(fn ($r) => ['bandwidth_option_id' => $r['bandwidth_option_id'], 'router_mode' => $r['router_mode'], 'location_category_id' => $r['location_category_id']], $found));
    }

    public function boundaries(Carbon $start, Carbon $end, array $service): array
    {
        $dates = [];
        foreach ($this->rates as $r) {
            if (($r['location_category_id'] !== null && (int) $r['location_category_id'] !== (int) ($service['location_category_id'] ?? 0)) || (int) $r['service_id'] !== (int) ($service['service_id'] ?? 0) || ($r['sid'] && $r['sid'] !== $service['sid'])) {
                continue;
            }
            foreach ([$r['effective_start_date'], $r['effective_end_date'] ? Carbon::parse($r['effective_end_date'])->addDay()->toDateString() : null] as $value) {
                if ($value && Carbon::parse($value)->gt($start) && Carbon::parse($value)->lte($end)) {
                    $dates[] = Carbon::parse($value)->toDateString();
                }
            }
        }

        return array_unique($dates);
    }
}
