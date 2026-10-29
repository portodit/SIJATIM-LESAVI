<?php

namespace App\Http\Controllers;

use App\Models\AddendumContract;
use App\Models\BandwidthOption;
use App\Models\BankJatimLink;
use App\Models\BillingProfile;
use App\Models\InvoicePeriod;
use App\Models\LocationCategory;
use App\Models\OfficeType;
use App\Models\PriceMaster;
use App\Models\Service;
use App\Services\Billing\InvoiceCalculator;
use App\Services\Billing\SourceValue;
use App\Services\Billing\TaxRate;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillingAdministrationController extends Controller
{
    public static function choices(): array
    {
        return ['office_type_id' => OfficeType::pluck('office_type_name', 'id'), 'location_category_id' => LocationCategory::pluck('location_category_name', 'id'), 'service_id' => Service::pluck('service_name', 'id'), 'bandwidth_option_id' => BandwidthOption::pluck('bandwidth_name', 'id'), 'addendum_contract_id' => AddendumContract::pluck('contract_number', 'id')];
    }

    public function index(Request $request)
    {
        $section = in_array($request->section, ['tariffs', 'contracts', 'services', 'profiles']) ? $request->section : 'tariffs';
        $classes = ['tariffs' => PriceMaster::class, 'contracts' => AddendumContract::class, 'services' => BankJatimLink::class, 'profiles' => BillingProfile::class];
        $query = $classes[$section]::query();
        if ($section === 'services') {
            $query->when($request->search, fn ($q) => $q->where(fn ($q) => $q->where('ssid', 'like', '%'.$request->search.'%')->orWhere('address', 'like', '%'.$request->search.'%')));
        }
        if ($section === 'tariffs') {
            $query->when($request->service_id, fn ($q) => $q->where('service_id', $request->service_id))
                ->when($request->bandwidth_option_id, fn ($q) => $q->where('bandwidth_option_id', $request->bandwidth_option_id));
        }

        $profile = BillingProfile::latest('version')->first();

        return view('billing.administration', ['title' => 'Master Harga', 'section' => $section, 'rows' => $query->latest('id')->paginate(20)->withQueryString(), 'choices' => self::choices(), 'edit' => $request->edit ? $classes[$section]::findOrFail($request->edit) : null, 'profile' => $profile, 'ppnRate' => TaxRate::multiplier($profile?->settings['tax_rate'] ?? null)]);
    }

    /**
     * The PPN setting on the Master Harga page, typed as a percentage. It is saved as a new billing profile version.
     */
    public function savePpn(Request $request)
    {
        $d = $request->validate(['ppn' => ['required', self::percentRule()], 'reason' => 'nullable|string|max:2000'], ['ppn.required' => 'PPN wajib diisi, misalnya 11 atau 11,5.']);
        $rate = TaxRate::fromPercent($d['ppn']);
        $reason = ($d['reason'] ?? '') ?: 'Diperbarui melalui Master Harga';

        return DB::transaction(function () use ($rate, $reason) {
            $previous = BillingProfile::latest('version')->first();
            if (! $previous) {
                return back()->withInput()->withErrors(['ppn' => 'Isi dulu Data Pelanggan & Invoice sebelum mengubah PPN.']);
            }
            if (BigDecimal::of(TaxRate::multiplier($previous->settings['tax_rate'] ?? null))->isEqualTo($rate)) {
                return redirect()->route('price-masters.index')->with('success', 'PPN tetap '.TaxRate::label($rate).'.');
            }
            $record = $this->newProfileVersion($previous->name, array_merge($previous->settings, ['tax_rate' => $rate]));
            $this->applyTaxRateToDrafts($record, $previous, $reason);
            DB::table('billing_audit_events')->insert(['action' => 'admin_ppn_saved', 'details' => json_encode(['id' => $record->id, 'reason' => $reason, 'tax_rate' => $rate, 'previous_tax_rate' => $previous->settings['tax_rate'] ?? null]), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            return redirect()->route('price-masters.index')->with('success', 'PPN diubah menjadi '.TaxRate::label($rate).'. Invoice yang belum terbit memakai PPN baru setelah Hitung Invoice.');
        });
    }

    /** A percentage typed by a person: 0 to 100, decimal comma or point. */
    private static function percentRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (TaxRate::fromPercent($value) === null) {
                $fail('PPN harus berupa angka 0 sampai 100, misalnya 11 atau 11,5.');
            }
        };
    }

    private function newProfileVersion(string $name, array $settings): BillingProfile
    {
        return BillingProfile::create(['name' => $name, 'version' => (BillingProfile::where('name', $name)->max('version') ?? 0) + 1, 'settings' => $settings, 'is_active' => true]);
    }

    /**
     * A changed PPN reaches the periods that are not locked: they switch to the new profile version and must be
     * calculated again (Hitung Invoice). Locked periods and issued invoices keep their frozen snapshot.
     */
    private function applyTaxRateToDrafts(BillingProfile $profile, ?BillingProfile $previous, string $reason): void
    {
        $rate = TaxRate::multiplier($profile->settings['tax_rate'] ?? null);
        if ($previous && BigDecimal::of(TaxRate::multiplier($previous->settings['tax_rate'] ?? null))->isEqualTo($rate)) {
            return;
        }
        $drafts = InvoicePeriod::where('is_locked', false)->whereNotIn('status', [InvoicePeriod::STATUS_FINALIZED, InvoicePeriod::STATUS_ARCHIVED])
            ->where(fn ($q) => $q->whereNull('billing_profile_id')->orWhereIn('billing_profile_id', BillingProfile::where('name', $profile->name)->select('id')))
            ->lockForUpdate()->get();
        foreach ($drafts as $period) {
            $period->update(['billing_profile_id' => $profile->id]);
            $period->markInputsChanged('master_ppn_updated', ['id' => $profile->id, 'reason' => $reason, 'tax_rate' => $rate, 'previous_tax_rate' => $previous?->settings['tax_rate'] ?? null]);
        }
    }

    public function save(Request $request, string $section)
    {
        abort_unless(in_array($section, ['tariffs', 'contracts', 'services', 'profiles']), 404);
        $request->validate(['reason' => 'nullable|string|max:2000']);
        $request->merge(['reason' => $request->input('reason') ?: 'Diperbarui melalui Master Harga']);
        $rules = match ($section) {
            'tariffs' => ['location_category_id' => 'nullable|exists:location_categories,id', 'service_id' => 'required|exists:services,id', 'bandwidth_option_id' => 'nullable|exists:bandwidth_options,id', 'addendum_contract_id' => 'nullable|exists:addendum_contracts,id', 'router_mode' => ['required', Rule::in(['none', 'managed'])], 'sid' => 'nullable|string|max:100', 'monthly_price' => 'required|string|max:50', 'installation_price' => 'nullable|string|max:50', 'relocation_price' => 'required|string|max:50', 'effective_start_date' => 'required|date_format:Y-m-d', 'effective_end_date' => 'nullable|date_format:Y-m-d|after_or_equal:effective_start_date', 'is_active' => 'required|boolean'],
            'contracts' => ['contract_number' => 'required|string|max:100', 'contract_title' => 'required|string|max:500', 'effective_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|required_if:status,expired,terminated|date_format:Y-m-d|after_or_equal:effective_date', 'status' => ['required', Rule::in(['active', 'expired', 'terminated'])]],
            'services' => ['addendum_contract_id' => 'nullable|exists:addendum_contracts,id', 'ssid' => ['required', 'string', 'max:100', Rule::unique('bank_jatim_links')->ignore($request->id)], 'office_type_id' => 'required|exists:office_types,id', 'location_category_id' => 'required|exists:location_categories,id', 'service_id' => 'required|exists:services,id', 'current_bandwidth_option_id' => 'required|exists:bandwidth_options,id', 'address' => 'required|string|max:2000', 'ip_host_telkom' => 'nullable|string|max:500', 'service_start_date' => 'required|date_format:Y-m-d', 'service_end_date' => 'nullable|date_format:Y-m-d|after_or_equal:service_start_date', 'billing_start_date' => 'nullable|date_format:Y-m-d', 'billing_end_date' => 'nullable|date_format:Y-m-d|after_or_equal:billing_start_date', 'physical_router_status' => ['required', Rule::in(['unknown', 'present', 'absent'])], 'billable_router_status' => ['required', Rule::in(['none', 'managed'])], 'router_billing_from' => 'nullable|date_format:Y-m-d', 'link_status' => ['required', Rule::in(['active', 'inactive', 'terminated'])]],
            'profiles' => ['name' => 'required|string|max:100', 'sla_target' => 'sometimes|numeric|between:0.99,0.99', 'tax_percent' => ['required_without:tax_rate', 'nullable', 'max:10', self::percentRule()], 'tax_rate' => 'required_without:tax_percent|numeric|min:0|max:1', 'proration_divisor' => 'required|integer|min:1|max:366', 'customer_name' => 'required|string|max:500', 'supplier_name' => 'required|string|max:500', 'account_number' => 'required|string|max:100', 'customer_address' => 'nullable|string|max:2000', 'supplier_address' => 'nullable|string|max:2000', 'npwp' => 'nullable|string|max:100', 'customer_id' => 'nullable|string|max:100', 'invoice_prefix' => 'required|string|max:100', 'due_day' => 'required|integer|min:1|max:31'],
        };
        $d = $request->validate($rules);
        // The profile form is typed as a percentage; the profile stores the multiplier (11 -> 0.11).
        if (isset($d['tax_percent'])) {
            $d['tax_rate'] = TaxRate::fromPercent($d['tax_percent']);
        }
        unset($d['tax_percent']);
        foreach (['monthly_price', 'installation_price', 'relocation_price'] as $key) {
            if (array_key_exists($key, $d)) {
                $d[$key] = SourceValue::number($d[$key]);
                if ($d[$key] !== null && BigDecimal::of($d[$key])->isLessThan(0)) {
                    return back()->withErrors([$key => 'Harga tidak boleh negatif.']);
                }
            }
        }

        return DB::transaction(function () use ($section, $d, $request) {
            $classes = ['tariffs' => PriceMaster::class, 'contracts' => AddendumContract::class, 'services' => BankJatimLink::class];
            if ($section === 'profiles') {
                $previous = BillingProfile::latest('version')->first();
                $settings = array_merge($previous?->settings ?? [], $d);
                unset($settings['name']);
                $record = $this->newProfileVersion($d['name'], $settings);
                $this->applyTaxRateToDrafts($record, $previous, $request->reason);
            } else {
                $class = $classes[$section];
                $record = $request->id ? $class::lockForUpdate()->findOrFail($request->id) : new $class;
                if ($section === 'services') {
                    if ($record->exists && $record->ssid !== $d['ssid'] && $record->invoiceLines()->exists()) {
                        return back()->withErrors(['ssid' => 'SID dengan riwayat tidak dapat diganti. Buat layanan baru.']);
                    }
                    if (! $record->exists) {
                        $d['sequence_in_office_type'] = (BankJatimLink::where('office_type_id', $d['office_type_id'])->max('sequence_in_office_type') ?? 0) + 1;
                        $d['created_by'] = auth()->id();
                    }
                }
                if ($section === 'contracts' && ! $record->exists) {
                    $d['uploaded_by'] = auth()->id();
                }
                $before = $record->exists ? $record->toArray() : null;
                $record->fill($d)->save();
                foreach (InvoicePeriod::where('is_locked', false)->where('status', '!=', 'finalized')->lockForUpdate()->get() as $p) {
                    if ($section === 'services') {
                        foreach ($p->lines()->where('bank_jatim_link_id', $record->id)->get() as $line) {
                            $line->existing_bandwidth_option_id = $record->current_bandwidth_option_id;
                            $line->address_snapshot = $record->address;
                            $line->unsetRelation('bankJatimLink');
                            $line->service_snapshot = InvoiceCalculator::serviceSnapshot($line);
                            $line->save();
                        }
                    }
                    $p->markInputsChanged('master_'.$section.'_updated', ['id' => $record->id, 'reason' => $request->reason, 'previous' => $before]);
                }
            }
            DB::table('billing_audit_events')->insert(['action' => 'admin_'.$section.'_saved', 'details' => json_encode(['id' => $record->id, 'reason' => $request->reason]), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            return redirect()->route('price-masters.index', ['section' => $section])->with('success', $section === 'profiles' ? 'Data pelanggan & invoice disimpan sebagai versi baru.' : 'Data master disimpan. Klik Hitung ulang pada periode yang sedang dikerjakan.');
        });
    }
}
