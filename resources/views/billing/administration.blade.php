@extends('layouts.sijatim')

@php
    $tabs = ['tariffs' => 'Tarif', 'services' => 'Daftar SSID', 'profiles' => 'Data Pelanggan & Invoice', 'contracts' => 'Kontrak'];
    // [essential fields, "Opsi lain" fields]
    $fields = match ($section) {
        'tariffs' => [
            ['location_category_id' => 'Kategori lokasi', 'service_id' => 'Layanan', 'bandwidth_option_id' => 'Bandwidth', 'router_mode' => 'Router', 'monthly_price' => 'Harga bulanan (Rp)', 'installation_price' => 'Biaya instalasi PSB (Rp)', 'relocation_price' => 'Biaya relokasi (Rp, 0 boleh)', 'effective_start_date' => 'Mulai berlaku'],
            ['sid' => 'Khusus untuk SSID (opsional)', 'addendum_contract_id' => 'Kontrak', 'effective_end_date' => 'Akhir berlaku', 'is_active' => 'Status'],
        ],
        'services' => [
            ['ssid' => 'SSID', 'office_type_id' => 'Cabang', 'location_category_id' => 'Kategori lokasi', 'service_id' => 'Layanan', 'current_bandwidth_option_id' => 'Bandwidth', 'service_start_date' => 'Tanggal aktif', 'address' => 'Alamat'],
            ['ip_host_telkom' => 'IP', 'billing_start_date' => 'Awal ditagih', 'billing_end_date' => 'Akhir ditagih', 'service_end_date' => 'Akhir layanan fisik', 'billable_router_status' => 'Router ditagih', 'router_billing_from' => 'Router ditagih mulai', 'physical_router_status' => 'Router fisik', 'addendum_contract_id' => 'Kontrak', 'link_status' => 'Status layanan'],
        ],
        'contracts' => [
            ['contract_number' => 'Nomor kontrak', 'contract_title' => 'Judul kontrak', 'effective_date' => 'Mulai berlaku', 'end_date' => 'Akhir berlaku', 'status' => 'Status'],
            [],
        ],
        'profiles' => [
            ['name' => 'Nama profil', 'customer_name' => 'Nama pelanggan', 'customer_id' => 'Customer ID', 'npwp' => 'NPWP', 'customer_address' => 'Alamat pelanggan', 'supplier_name' => 'Nama penyedia', 'account_number' => 'Nomor rekening / akun', 'supplier_address' => 'Alamat penyedia', 'invoice_prefix' => 'Awalan nomor invoice', 'due_day' => 'Tanggal jatuh tempo (1–31)'],
            ['sla_target' => 'Target SLA (tetap 99%)', 'tax_percent' => 'PPN (%)', 'proration_divisor' => 'Pembagi prorata (hari)'],
        ],
    };
    $enums = [
        'router_mode' => ['none' => 'Tanpa router', 'managed' => 'Dengan managed router'],
        'billable_router_status' => ['none' => 'Tanpa router', 'managed' => 'Dengan managed router'],
        'physical_router_status' => ['unknown' => 'Belum diketahui', 'present' => 'Ada', 'absent' => 'Tidak ada'],
        'status' => ['active' => 'Aktif', 'expired' => 'Berakhir', 'terminated' => 'Dihentikan'],
        'link_status' => ['active' => 'Aktif', 'inactive' => 'Tidak aktif', 'terminated' => 'Diputus'],
        'is_active' => [1 => 'Aktif', 0 => 'Tidak aktif'],
    ];
    $optionsFor = fn ($key) => $choices[$key === 'current_bandwidth_option_id' ? 'bandwidth_option_id' : $key] ?? $enums[$key] ?? null;
    $values = $section === 'profiles'
        ? array_merge($profile?->settings ?? [], ['name' => $profile?->name, 'tax_percent' => \App\Services\Billing\TaxRate::percent($profile?->settings['tax_rate'] ?? null)])
        : ($edit?->getAttributes() ?? ['router_mode' => 'none', 'billable_router_status' => 'none', 'physical_router_status' => 'unknown', 'relocation_price' => 0, 'is_active' => 1, 'status' => 'active', 'link_status' => 'active']);
    $money = fn ($v) => $v === null ? '—' : 'Rp '.number_format((float) $v, fmod((float) $v, 1) == 0 ? 0 : 2, ',', '.');
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $openForm = $section !== 'profiles' && ! $errors->has('ppn') && ($edit || $errors->any() || request('new'));
    $ppnLabel = \App\Services\Billing\TaxRate::label($ppnRate);
@endphp

@section('content')
<div x-data="{ modal: @js($openForm ? 'form' : ($errors->has('ppn') ? 'ppn' : null)) }">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold">Master Harga</h1>
            <p class="text-gray-500">Data tetap yang dipakai setiap bulan. Perubahan di sini berlaku untuk periode yang belum dikunci setelah klik Hitung ulang.</p>
        </div>
        @if ($section !== 'profiles')
            <a href="{{ route('price-masters.index', ['section' => $section, 'new' => 1]) }}" class="btn btn-primary" @unless ($edit) @click.prevent="modal = 'form'" @endunless>
                + Tambah {{ ['tariffs' => 'tarif', 'services' => 'SSID', 'contracts' => 'kontrak'][$section] }}
            </a>
        @endif
    </div>

    <nav class="flex flex-wrap gap-2 border-b border-gray-200 mb-6">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('price-masters.index', ['section' => $key]) }}"
               class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ $section === $key ? 'border-[#E6002D] text-[#E6002D]' : 'border-transparent text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($section === 'tariffs')
        {{-- PPN setting: one percentage for every invoice that is not issued yet --}}
        <div class="card rounded-xl px-5 py-4 mb-6 flex flex-wrap items-center gap-3">
            <p class="text-lg font-semibold">PPN: {{ $ppnLabel }}</p>
            <button type="button" class="btn btn-secondary" @click="modal = 'ppn'">Ubah</button>
            <p class="text-sm text-gray-500">Berlaku untuk invoice yang belum terbit. Invoice yang sudah terbit tidak berubah.</p>
        </div>
        <x-sij.modal name="ppn" title="Ubah PPN" width="max-w-md">
            <form method="POST" action="{{ route('billing-masters.ppn') }}" class="space-y-4">
                @csrf
                <label class="block text-sm font-medium">PPN (%)
                    <input class="input mt-1" name="ppn" inputmode="decimal" required maxlength="10" placeholder="Contoh: 11 atau 11,5" value="{{ old('ppn', \App\Services\Billing\TaxRate::percent($ppnRate)) }}">
                    @error('ppn')<span class="block text-xs text-red-600 mt-1">{{ $message }}</span>@enderror
                </label>
                <p class="text-xs text-gray-500">Berlaku untuk invoice yang belum terbit. Invoice yang sudah terbit tidak berubah. Klik Hitung Invoice lagi pada periode yang sedang dikerjakan.</p>
                <label class="block text-sm font-medium">Catatan perubahan <span class="font-normal text-gray-400">(opsional)</span>
                    <input class="input mt-1" name="reason" maxlength="2000" placeholder="Contoh: tarif PPN baru">
                </label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                    <button class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-sij.modal>
    @endif

    @if ($section === 'services')
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
            Untuk upgrade, downgrade, relokasi, atau cabut bulanan gunakan <strong>Ubah Layanan</strong> di periode tagihan, bukan di sini. SSID baru (PSB) juga bisa langsung ditambahkan dari periode.
        </div>
    @endif

    {{-- Entry form: modal for list sections, inline card for the billing profile --}}
    @if ($section === 'profiles')
        <div class="card rounded-xl p-6 mb-6">
            <h2 class="font-semibold text-lg">Data pelanggan & invoice</h2>
            <p class="text-sm text-gray-500 mb-4">Dipakai pada setiap invoice. Menyimpan membuat versi baru; invoice yang sudah terbit tidak berubah.</p>
            @include('billing.partials.master-form')
        </div>
    @else
        <x-sij.modal name="form" :title="$edit ? 'Ubah data' : 'Tambah data'" width="max-w-3xl">
            @include('billing.partials.master-form')
        </x-sij.modal>
    @endif

    {{-- Filters --}}
    @if ($section === 'services')
        <form method="GET" class="flex gap-2 mb-4">
            <input type="hidden" name="section" value="services">
            <input name="search" value="{{ request('search') }}" placeholder="Cari SSID atau alamat" class="input max-w-sm">
            <button class="btn btn-secondary">Cari</button>
        </form>
    @elseif ($section === 'tariffs')
        <form method="GET" class="flex flex-wrap gap-2 mb-4">
            <input type="hidden" name="section" value="tariffs">
            <select name="service_id" class="input w-auto">
                <option value="">Semua layanan</option>
                @foreach ($choices['service_id'] as $id => $name)
                    <option value="{{ $id }}" @selected(request('service_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
            <select name="bandwidth_option_id" class="input w-auto">
                <option value="">Semua bandwidth</option>
                @foreach ($choices['bandwidth_option_id'] as $id => $name)
                    <option value="{{ $id }}" @selected(request('bandwidth_option_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
            <button class="btn btn-secondary">Saring</button>
        </form>
    @endif

    {{-- List --}}
    <div class="card rounded-xl overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    @switch($section)
                        @case('tariffs')
                            <th>Kategori</th><th>Layanan</th><th>Bandwidth</th><th>Router</th><th class="text-right">Harga bulanan</th><th class="text-right">Instalasi</th><th class="text-right">Relokasi</th><th>Berlaku</th><th>Status</th>
                            @break
                        @case('services')
                            <th>SSID</th><th>Cabang</th><th>Layanan</th><th>Bandwidth</th><th>Alamat</th><th>Aktif sejak</th><th>Status</th>
                            @break
                        @case('contracts')
                            <th>Nomor</th><th>Judul</th><th>Mulai</th><th>Akhir</th><th>Status</th>
                            @break
                        @case('profiles')
                            <th>Versi</th><th>Pelanggan</th><th>Nomor akun</th><th>PPN</th><th>Target SLA</th><th>Dibuat</th>
                            @break
                    @endswitch
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @switch($section)
                            @case('tariffs')
                                <td>{{ $row->location_category_id ? ($choices['location_category_id'][$row->location_category_id] ?? '—') : 'Semua kategori' }}</td>
                                <td>{{ $choices['service_id'][$row->service_id] ?? '—' }}</td>
                                <td>{{ $choices['bandwidth_option_id'][$row->bandwidth_option_id] ?? 'Tidak berlaku' }}@if ($row->sid)<span class="block text-xs text-gray-500">Khusus {{ $row->sid }}</span>@endif</td>
                                <td>{{ $enums['router_mode'][$row->router_mode] ?? $row->router_mode }}</td>
                                <td class="text-right whitespace-nowrap font-semibold">{{ $money($row->getRawOriginal('monthly_price')) }}</td>
                                <td class="text-right whitespace-nowrap">{{ $money($row->getRawOriginal('installation_price')) }}</td>
                                <td class="text-right whitespace-nowrap">{{ $money($row->getRawOriginal('relocation_price')) }}</td>
                                <td class="whitespace-nowrap">{{ $date($row->getRawOriginal('effective_start_date')) }} – {{ $row->getRawOriginal('effective_end_date') ? $date($row->getRawOriginal('effective_end_date')) : 'sekarang' }}</td>
                                <td><span class="{{ $row->is_active ? 'badge-success' : 'badge-gray' }}">{{ $row->is_active ? 'Aktif' : 'Tidak aktif' }}</span></td>
                                @break
                            @case('services')
                                <td class="font-semibold whitespace-nowrap">{{ $row->ssid }}</td>
                                <td>{{ $choices['office_type_id'][$row->office_type_id] ?? '—' }}</td>
                                <td>{{ $choices['service_id'][$row->service_id] ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $choices['bandwidth_option_id'][$row->current_bandwidth_option_id] ?? '—' }}</td>
                                <td class="min-w-[16rem]">{{ $row->address }}</td>
                                <td class="whitespace-nowrap">{{ $date($row->getRawOriginal('service_start_date')) }}</td>
                                <td><span class="{{ $row->link_status === 'active' ? 'badge-success' : 'badge-gray' }}">{{ $enums['link_status'][$row->link_status] ?? $row->link_status }}</span></td>
                                @break
                            @case('contracts')
                                <td class="font-semibold">{{ $row->contract_number }}</td>
                                <td>{{ $row->contract_title }}</td>
                                <td>{{ $date($row->getRawOriginal('effective_date')) }}</td>
                                <td>{{ $date($row->getRawOriginal('end_date')) }}</td>
                                <td>{{ $enums['status'][$row->status] ?? $row->status }}</td>
                                @break
                            @case('profiles')
                                <td>{{ $row->name }} v{{ $row->version }}</td>
                                <td>{{ $row->settings['customer_name'] ?? '—' }}</td>
                                <td>{{ $row->settings['account_number'] ?? '—' }}</td>
                                <td>{{ \App\Services\Billing\TaxRate::label($row->settings['tax_rate'] ?? null) }}</td>
                                <td>{{ number_format((float) ($row->settings['sla_target'] ?? 0.99) * 100, 0) }}%</td>
                                <td>{{ $row->created_at?->format('d/m/Y H:i') }}</td>
                                @break
                        @endswitch
                        <td class="text-right">
                            @if ($section !== 'profiles')
                                <a class="text-[#E6002D] hover:underline" href="{{ route('price-masters.index', array_merge(request()->query(), ['section' => $section, 'edit' => $row->id])) }}">Ubah</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-10 text-center text-gray-500">Belum ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</div>
@endsection
