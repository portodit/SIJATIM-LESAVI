{{-- Master Harga entry form. Expects $section, $fields ([essential, extra]), $values, $optionsFor, $edit. --}}
@php
    $nullable = $section === 'tariffs' ? ['location_category_id', 'bandwidth_option_id', 'addendum_contract_id'] : ['bandwidth_option_id', 'addendum_contract_id'];
    // Empty bandwidth means the service has no bandwidth (e.g. Colocation), not "any bandwidth".
    $emptyLabels = ['location_category_id' => 'Semua kategori (harga umum per bandwidth)', 'bandwidth_option_id' => 'Tidak berlaku (tanpa bandwidth)'];
    $isDate = fn ($key) => str_contains($key, '_date') || str_ends_with($key, '_from');
    [$essential, $extra] = $fields;
@endphp
<form method="POST" action="{{ route('billing-masters.save', $section) }}">
    @csrf
    @if ($edit && $section !== 'profiles')
        <input type="hidden" name="id" value="{{ $edit->id }}">
    @endif

    @foreach (['essential' => $essential, 'extra' => $extra] as $group => $groupFields)
        @if ($group === 'extra' && $groupFields)
            <details class="mt-5 rounded-lg border border-gray-200 p-4" @if ($errors->hasAny(array_keys($groupFields))) open @endif>
                <summary class="cursor-pointer text-sm font-medium text-gray-700">Opsi lain</summary>
        @endif
        @if ($groupFields)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 {{ $group === 'extra' ? 'mt-4' : '' }}">
                @foreach ($groupFields as $key => $label)
                    @php($options = $optionsFor($key))
                    <label class="block text-sm font-medium {{ in_array($key, ['address', 'customer_address', 'supplier_address']) ? 'md:col-span-2' : '' }}">
                        {{ $label }}
                        @if ($key === 'sla_target')
                            <input class="input mt-1 bg-gray-50" value="99%" readonly>
                        @elseif ($options)
                            <select class="input mt-1" name="{{ $key }}">
                                @if (in_array($key, $nullable) && $key !== 'location_category_id')
                                    <option value="">{{ $emptyLabels[$key] ?? 'Tidak dipilih' }}</option>
                                @endif
                                @foreach ($options as $id => $name)
                                    <option value="{{ $id }}" @selected((string) old($key, $values[$key] ?? '') === (string) $id)>{{ $name }}</option>
                                @endforeach
                                {{-- Listed last so a new tariff never defaults to "all categories" by accident. --}}
                                @if (in_array($key, $nullable) && $key === 'location_category_id')
                                    <option value="" @selected(old($key, $edit ? ($edit->location_category_id ?? '') : null) === '')>{{ $emptyLabels[$key] }}</option>
                                @endif
                            </select>
                        @elseif (in_array($key, ['address', 'customer_address', 'supplier_address']))
                            <textarea class="input mt-1" name="{{ $key }}" rows="2">{{ old($key, $values[$key] ?? '') }}</textarea>
                        @else
                            @php($raw = old($key, $values[$key] ?? ''))
                            <input class="input mt-1" name="{{ $key }}" type="{{ $isDate($key) ? 'date' : 'text' }}"
                                   value="{{ $isDate($key) && $raw ? substr((string) $raw, 0, 10) : $raw }}"
                                   @if (str_ends_with($key, '_price')) inputmode="decimal" placeholder="Contoh: 2319819" @elseif ($key === 'tax_percent') inputmode="decimal" placeholder="Contoh: 11 atau 11,5" @endif>
                        @endif
                        @error($key)<span class="block text-xs text-red-600 mt-1">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
        @endif
        @if ($group === 'extra' && $groupFields)
            </details>
        @endif
    @endforeach

    <label class="block text-sm font-medium mt-5">Catatan perubahan <span class="font-normal text-gray-400">(opsional)</span>
        <input class="input mt-1" name="reason" maxlength="2000" placeholder="Contoh: harga baru sesuai addendum 2026">
    </label>
    <div class="flex justify-end gap-2 mt-5">
        @if ($section !== 'profiles')
            <a class="btn btn-secondary" href="{{ route('price-masters.index', ['section' => $section]) }}">Batal</a>
        @endif
        <button class="btn btn-primary">{{ $section === 'profiles' ? 'Simpan' : ($edit ? 'Simpan perubahan' : 'Simpan') }}</button>
    </div>
</form>
