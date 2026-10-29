@extends('layouts.sijatim')

@php
    $locked = $period->isLocked();
    $rupiah = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
    $routerLabel = fn ($router) => \App\Services\Billing\InvoiceCalculator::routerLabel($router);
    $componentLabels = ['existing' => 'Layanan existing', 'upgrade' => 'Upgrade', 'downgrade' => 'Downgrade', 'termination' => 'Cabut (prorata)', 'installation' => 'Biaya instalasi PSB', 'relocation' => 'Biaya relokasi'];
@endphp

@section('content')
<x-sij.period-header :period="$period" :step="2" />

@if (! $hasLines)
    <div class="card rounded-xl p-10 text-center">
        <h2 class="text-xl font-semibold">Belum ada layanan</h2>
        <p class="text-gray-500 mt-2">Isi daftar SSID terlebih dahulu di langkah 1.</p>
        <a class="btn btn-primary mt-6" href="{{ route('invoice-periods.step1', $period) }}">Ke langkah 1</a>
    </div>
@else
    {{-- Calculate --}}
    @if (! $current)
        <div class="card rounded-xl p-6 mb-6 border-amber-300 bg-amber-50 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-lg">Invoice belum dihitung</h2>
                <p class="text-sm text-gray-700">Klik <strong>Hitung Invoice</strong> untuk menghitung tagihan dan memeriksa kelengkapan data. Ulangi setiap kali ada data yang berubah.</p>
            </div>
            @unless ($locked)
                <form method="POST" action="{{ route('billing.calculate', $period) }}">
                    @csrf
                    <input type="hidden" name="input_version" value="{{ $period->input_version }}">
                    <button class="btn btn-primary px-6 py-3 text-base">Hitung Invoice</button>
                </form>
            @endunless
        </div>
    @endif

    {{-- Checklist --}}
    @if ($blocking->isNotEmpty())
        <div class="card rounded-xl p-6 mb-6">
            <h2 class="font-semibold text-lg">Perlu dilengkapi sebelum terbit ({{ $blocking->count() }})</h2>
            <p class="text-sm text-gray-500 mb-4">Perbaiki setiap poin, lalu klik Hitung ulang.</p>
            <div class="space-y-3">
                @foreach ($blocking as $group)
                    @php
                        $details = $group['details'];
                        $plainSids = $details->isNotEmpty() ? $group['sids']->diff($details->pluck('sid'))->values() : $group['sids'];
                        $isPrice = $group['code'] === 'tariff_discrepancy';
                        $source = fn ($detail) => implode(' · ', array_filter([$detail['source']['file'] ?? null, $detail['source']['sheet'] ?? null, isset($detail['source']['row']) ? 'baris '.$detail['source']['row'] : null]));
                    @endphp
                    <div class="rounded-lg border border-red-200 border-l-4 border-l-red-500 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-red-700">{{ $group['label'] }}
                                    <span class="font-normal text-gray-600">· {{ $group['sids']->isNotEmpty() ? $group['sids']->count().' SSID' : $group['count'].' hal' }}</span>
                                </p>
                                <p class="text-sm text-gray-700 mt-1">{{ $group['hint'] }}</p>
                                @if ($plainSids->isNotEmpty())
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ $plainSids->take(6)->implode(', ') }}@if ($plainSids->count() > 6) dan {{ $plainSids->count() - 6 }} lainnya @endif
                                    </p>
                                @elseif ($group['sids']->isEmpty())
                                    @foreach ($group['messages'] as $message)
                                        <p class="text-xs text-gray-500 mt-1">{{ $message }}</p>
                                    @endforeach
                                @endif
                            </div>
                            @if ($group['link'])
                                <a class="btn btn-secondary whitespace-nowrap" href="{{ $group['link'] }}">{{ $group['link_label'] }}</a>
                            @endif
                        </div>

                        @if ($details->isNotEmpty())
                            @if ($isPrice && $canDecide)
                                <form id="price-form" method="POST" action="{{ route('billing.price-decisions', $period) }}">
                                    @csrf
                            @endif
                            <details class="mt-4" {{ $details->count() <= 15 ? 'open' : '' }}>
                                <summary class="cursor-pointer text-sm font-semibold text-gray-700">Lihat rincian {{ $details->count() }} SSID</summary>
                                @if ($isPrice && $canDecide)
                                    <div class="flex flex-wrap gap-2 mt-3">
                                        <button type="button" class="btn btn-secondary" onclick="document.querySelectorAll('#price-form input[type=radio][value=source]').forEach(function (e) { e.checked = true })">Pilih semua: pakai harga Telkom</button>
                                        <button type="button" class="btn btn-secondary" onclick="document.querySelectorAll('#price-form input[type=radio][value=system]').forEach(function (e) { e.checked = true })">Pilih semua: tetap harga sistem</button>
                                    </div>
                                @endif
                                <div class="overflow-auto max-h-[32rem] mt-3">
                                    <table class="table">
                                        <thead>
                                            @if ($isPrice)
                                                <tr>
                                                    <th>SSID</th><th>Data sistem</th><th class="text-right">Harga Telkom</th><th class="text-right">Harga sistem</th><th class="text-right">Selisih</th><th>Penyebab</th>
                                                    @if ($canDecide)<th>Pilihan</th>@endif
                                                </tr>
                                            @else
                                                <tr><th>SSID</th><th>Yang perlu dilengkapi</th></tr>
                                            @endif
                                        </thead>
                                        <tbody>
                                            @foreach ($details as $row)
                                                @php
                                                    $detail = $row['detail'];
                                                    $diff = (float) ($detail['difference'] ?? 0);
                                                    $target = $isPrice ? \App\Http\Controllers\BillingWorkflowController::priceTargetBandwidth($detail) : null;
                                                    $where = $isPrice ? $source($detail) : '';
                                                    $tariffPrice = $detail['monthly'] ?? $detail['system'] ?? null;
                                                @endphp
                                                <tr class="align-top">
                                                    <td class="min-w-[11rem]">
                                                        <span class="font-semibold">{{ $row['sid'] }}</span>
                                                        @if ($row['branch'])<span class="block text-xs text-gray-500">Cabang {{ $row['branch'] }}</span>@endif
                                                        @if ($row['address'])<span class="block text-xs text-gray-500" title="{{ $row['address'] }}">{{ \Illuminate\Support\Str::limit($row['address'], 60) }}</span>@endif
                                                    </td>
                                                    @if ($isPrice)
                                                        <td class="min-w-[11rem]">
                                                            <span class="block">{{ $detail['bandwidth'] ?? '—' }}</span>
                                                            <span class="block text-xs text-gray-500">{{ $detail['category'] ?: '—' }} · {{ $routerLabel($detail['router'] ?? 'none') }}</span>
                                                        </td>
                                                        <td class="text-right whitespace-nowrap font-semibold">{{ $rupiah($detail['reference']) }}</td>
                                                        <td class="text-right whitespace-nowrap">{{ $rupiah($detail['system']) }}</td>
                                                        <td class="text-right whitespace-nowrap font-semibold {{ $diff > 0 ? 'text-amber-700' : ($diff < 0 ? 'text-red-600' : 'text-gray-500') }}">{{ ($diff > 0 ? '+' : ($diff < 0 ? '−' : '')).$rupiah(abs($diff)) }}</td>
                                                        <td class="min-w-[16rem] text-sm">
                                                            {{ $detail['explanation'] ?? $row['message'] }}
                                                            @if ($where)<span class="block text-xs text-gray-500 mt-1">Sumber: {{ $where }}</span>@endif
                                                        </td>
                                                        @if ($canDecide)
                                                            <td class="min-w-[16rem]">
                                                                <label class="flex items-start gap-2 py-1"><input type="radio" class="mt-1 text-[#E6002D] focus:ring-[#E6002D]" name="decisions[{{ $row['sid'] }}]" value="source"> <span>Pakai harga Telkom ({{ $rupiah($detail['reference']) }})</span></label>
                                                                @if ($target)
                                                                    <label class="flex items-start gap-2 py-1"><input type="radio" class="mt-1 text-[#E6002D] focus:ring-[#E6002D]" name="decisions[{{ $row['sid'] }}]" value="bandwidth"> <span>Ganti bandwidth bulan ini ke {{ $target['name'] }}@if ($target['master']) (sesuai Master)@endif</span></label>
                                                                @endif
                                                                <label class="flex items-start gap-2 py-1"><input type="radio" class="mt-1 text-[#E6002D] focus:ring-[#E6002D]" name="decisions[{{ $row['sid'] }}]" value="system"> <span>Tetap harga sistem ({{ $rupiah($tariffPrice) }})</span></label>
                                                            </td>
                                                        @endif
                                                    @else
                                                        <td class="text-sm">{{ $detail['explanation'] ?? $row['message'] }}</td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if ($isPrice && $canDecide)
                                    <div class="mt-4 flex flex-wrap items-end gap-3">
                                        <div class="min-w-[16rem] flex-1">
                                            <label class="block text-sm text-gray-600 mb-1" for="price-note">Catatan (boleh dikosongkan)</label>
                                            <input id="price-note" class="input w-full" name="note" maxlength="500" placeholder="Mis. sudah dikonfirmasi ke Telkom">
                                        </div>
                                        <button class="btn btn-primary">Terapkan pilihan &amp; hitung ulang</button>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">SSID yang belum dipilih tetap menahan penerbitan. Anda bisa memilih sebagian dulu. Pilihan harga Telkom atau harga sistem dicatat sebagai override harga dan bisa dihapus di Menu Lanjutan.</p>
                                @elseif ($isPrice)
                                    <p class="text-xs text-gray-500 mt-3">{{ $period->isLocked() ? 'Periode sudah terkunci, pilihan tidak bisa diubah.' : 'Hitung ulang dulu untuk memilih harga.' }}</p>
                                @endif
                            </details>
                            @if ($isPrice && $canDecide)
                                </form>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($current)
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 p-5 flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="font-semibold text-green-800">Semua data lengkap</p>
                <p class="text-sm text-green-800">Periksa total di bawah, lalu lanjut ke langkah Terbitkan.</p>
            </div>
            <a class="btn btn-primary" href="{{ route('invoice-periods.step3', $period) }}">Lanjut: Terbitkan &rarr;</a>
        </div>
    @endif

    @if ($current && $totals)
        {{-- Totals --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div class="card rounded-xl p-5">
                <p class="text-sm text-gray-500">Subtotal</p>
                <p class="text-2xl font-bold mt-1"><x-sij.money :value="$totals['subtotal']" empty="Belum lengkap" /></p>
            </div>
            <div class="card rounded-xl p-5">
                <p class="text-sm text-gray-500">PPN {{ \App\Services\Billing\TaxRate::label(\App\Services\Billing\TaxRate::ofSnapshot($period->calculation)) }}</p>
                <p class="text-2xl font-bold mt-1"><x-sij.money :value="$totals['tax']" empty="Belum lengkap" /></p>
            </div>
            <div class="card rounded-xl p-5 border-[#E6002D]">
                <p class="text-sm text-gray-500">Total tagihan</p>
                <p class="text-2xl font-bold mt-1 text-[#E6002D]"><x-sij.money :value="$totals['grand_total']" empty="Belum lengkap" /></p>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6 text-sm text-gray-600">
            <p>
                {{ number_format($totals['service_count'], 0, ',', '.') }} SSID ·
                Potongan SLA <x-sij.money :value="$totals['sla_credit']" />
                @if ((float) $totals['manual_credit'] > 0) · Kredit manual <x-sij.money :value="$totals['manual_credit']" /> @endif
                · {{ $totals['below_sla_count'] ?? 0 }} SSID di bawah SLA 99%
            </p>
            @unless ($locked)
                <form method="POST" action="{{ route('billing.calculate', $period) }}">
                    @csrf
                    <input type="hidden" name="input_version" value="{{ $period->input_version }}">
                    <button class="btn btn-secondary">Hitung ulang</button>
                    <span class="text-xs text-gray-500 ml-2">Dihitung {{ $period->calculated_at?->diffForHumans() }}</span>
                </form>
            @endunless
        </div>

        <details class="card rounded-xl p-5 mb-6">
            <summary class="cursor-pointer font-semibold">Rincian perhitungan</summary>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 mt-4 text-sm">
                <div>
                    @foreach ($totals['components'] as $component => $amount)
                        <p class="flex justify-between border-b border-gray-100 py-1.5"><span>{{ $componentLabels[$component] ?? $component }}</span><x-sij.money :value="$amount" /></p>
                    @endforeach
                    <p class="flex justify-between border-b border-gray-100 py-1.5 font-semibold"><span>Tagihan sebelum potongan</span><x-sij.money :value="$totals['gross']" /></p>
                </div>
                <div>
                    <p class="flex justify-between border-b border-gray-100 py-1.5"><span>Potongan SLA</span><span>− <x-sij.money :value="$totals['sla_credit']" /></span></p>
                    <p class="flex justify-between border-b border-gray-100 py-1.5"><span>Kredit manual</span><span>− <x-sij.money :value="$totals['manual_credit']" /></span></p>
                    <p class="flex justify-between border-b border-gray-100 py-1.5"><span>Pembulatan</span><x-sij.money :value="$totals['rounding']" /></p>
                    <p class="flex justify-between border-b border-gray-100 py-1.5 font-semibold"><span>Subtotal</span><x-sij.money :value="$totals['subtotal']" /></p>
                </div>
            </div>
        </details>

        {{-- Per SSID --}}
        <div class="card rounded-xl p-6 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-semibold text-lg">Tagihan per SSID</h2>
                    <p class="text-sm text-gray-500">Target SLA 99% untuk semua SSID. Potongan dihitung bila SLA aktual di bawah target.</p>
                </div>
                <form method="GET" class="flex gap-2">
                    <input class="input" name="search" value="{{ $search }}" placeholder="Cari SSID / alamat">
                    @foreach (array_filter(request()->only('sort', 'dir'), 'is_string') as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <button class="btn btn-secondary">Cari</button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <x-sij.sort-th sort="sid">SSID</x-sij.sort-th>
                            <x-sij.sort-th sort="category">Kategori · Layanan</x-sij.sort-th>
                            <x-sij.sort-th sort="order">Status</x-sij.sort-th>
                            <x-sij.sort-th sort="gross" dir="desc" class="text-right">Tagihan</x-sij.sort-th>
                            <x-sij.sort-th sort="downtime_seconds" dir="desc" class="text-right">Downtime (detik)</x-sij.sort-th>
                            <x-sij.sort-th sort="sla" dir="desc" class="text-right">SLA aktual</x-sij.sort-th>
                            <x-sij.sort-th sort="sla_credit" dir="desc" class="text-right">Potongan SLA</x-sij.sort-th>
                            <x-sij.sort-th sort="net" dir="desc" class="text-right">Total</x-sij.sort-th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $line)
                            <tr>
                                <td class="min-w-[11rem]">
                                    <span class="font-semibold">{{ $line['sid'] }}</span>
                                    <span class="block text-xs text-gray-500">Cabang {{ $line['branch'] }}</span>
                                </td>
                                <td class="min-w-[12rem]">
                                    <span class="block">{{ $line['category'] ?: '—' }}</span>
                                    <span class="block text-xs text-gray-500">{{ $line['service'] }} · {{ $line['bandwidth'] }}</span>
                                </td>
                                <td><x-sij.status-badge :status="$line['order']" /></td>
                                <td class="text-right"><x-sij.money :value="$line['gross']" empty="Harga belum ada" /></td>
                                <td class="text-right">{{ $line['downtime_seconds'] === null ? 'Belum diisi' : number_format($line['downtime_seconds'], 0, ',', '.') }}</td>
                                <td class="text-right whitespace-nowrap {{ $line['sla'] !== null && (float) $line['sla'] < (float) $line['sla_target'] ? 'text-red-600 font-semibold' : '' }}">
                                    {{ $line['sla'] === null ? '—' : number_format((float) $line['sla'] * 100, 4, ',', '.').'%' }}
                                </td>
                                <td class="text-right"><x-sij.money :value="$line['sla_credit']" /></td>
                                <td class="text-right font-semibold"><x-sij.money :value="$line['net']" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-8 text-center text-gray-500">Tidak ada SSID yang cocok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $lines->links() }}</div>
        </div>
    @endif

    {{-- Non-blocking notes --}}
    @if ($notes->isNotEmpty())
        <details class="card rounded-xl p-5 mb-6">
            <summary class="cursor-pointer font-semibold">Catatan lain ({{ $notes->count() }}) · tidak menghalangi penerbitan</summary>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($notes as $group)
                    <li>
                        <strong>{{ $group['label'] }}</strong> · {{ $group['count'] }} · <span class="text-gray-600">{{ $group['hint'] }}</span>
                        @if ($group['sids']->isNotEmpty())
                            <details class="mt-1">
                                <summary class="cursor-pointer text-xs text-gray-500">Lihat {{ $group['sids']->count() }} SSID</summary>
                                <p class="text-xs text-gray-500 mt-1">{{ $group['sids']->implode(', ') }}</p>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <div class="flex flex-wrap justify-between gap-3">
        <a class="btn btn-secondary" href="{{ route('invoice-periods.step1', $period) }}">&larr; Kembali ke Layanan & Downtime</a>
        @if ($current && $blocking->isEmpty())
            <a class="btn btn-primary px-6 py-3 text-base" href="{{ route('invoice-periods.step3', $period) }}">Lanjut: Terbitkan &rarr;</a>
        @endif
    </div>
@endif
@endsection
