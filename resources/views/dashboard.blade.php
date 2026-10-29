@extends('layouts.sijatim')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold">Invoice Bank Jatim</h1>
        <p class="text-gray-500">Pilih periode tagihan, lalu klik <strong>Lanjutkan</strong>.</p>
    </div>
    <a class="btn btn-primary" href="{{ route('invoice-periods.create') }}">+ Periode baru</a>
</div>

@if ($period)
    <form method="GET" class="mb-6">
        <label class="text-sm font-medium">Periode tagihan
            <select name="period" class="input w-auto ml-2" onchange="this.form.submit()">
                @foreach ($periods as $p)
                    <option value="{{ $p->id }}" @selected($period->id === $p->id)>{{ $p->period_name }} ({{ $p->period_code }})</option>
                @endforeach
            </select>
        </label>
        <noscript><button class="btn btn-secondary ml-2">Tampilkan</button></noscript>
    </form>

    <div class="card rounded-xl p-6 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-semibold">Tagihan {{ $period->period_name }}</h2>
                    <span class="badge {{ $state['badge'] }}">{{ $state['label'] }}</span>
                </div>
                <p class="text-gray-500 mt-1">Pemakaian {{ $period->usage_name }}</p>
                <p class="text-sm text-gray-700 mt-3">
                    {{ number_format($lineCount, 0, ',', '.') }} SSID · {{ $changeCount }} SSID berubah · {{ $downtimeCount }} SSID ada downtime
                </p>
            </div>
            <a class="btn btn-primary px-6 py-3 text-base" href="{{ $nextUrl }}">{{ $nextLabel }} &rarr;</a>
        </div>
        <ol class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-6 text-sm">
            @foreach ([1 => ['Layanan & Downtime', $hasLines], 2 => ['Periksa & Hitung', $current && ! $blocking], 3 => ['Terbitkan', $latestReady && $period->isLocked()]] as $number => [$label, $done])
                <li>
                    <a href="{{ route('invoice-periods.step'.$number, $period) }}" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold {{ $done ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600' }}">{!! $done ? '&#10003;' : $number !!}</span>
                        {{ $label }}
                    </a>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($totals)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="card rounded-xl p-5"><p class="text-sm text-gray-500">Subtotal</p><p class="text-2xl font-bold mt-1"><x-sij.money :value="$totals['subtotal']" empty="Belum lengkap" /></p></div>
            <div class="card rounded-xl p-5"><p class="text-sm text-gray-500">PPN</p><p class="text-2xl font-bold mt-1"><x-sij.money :value="$totals['tax']" empty="Belum lengkap" /></p></div>
            <div class="card rounded-xl p-5"><p class="text-sm text-gray-500">Total tagihan</p><p class="text-2xl font-bold mt-1 text-[#E6002D]"><x-sij.money :value="$totals['grand_total']" empty="Belum lengkap" /></p></div>
        </div>
    @endif

    @if ($latestReady)
        <div class="card rounded-xl p-6 mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold">File invoice versi {{ $latestReady->version }}</h2>
                <p class="text-sm text-gray-500">Diterbitkan {{ $latestReady->approved_at?->format('d/m/Y H:i') }}</p>
            </div>
            <div class="flex gap-2">
                @foreach ($latestReady->outputs as $output)
                    <a class="btn btn-secondary" href="{{ route('billing.download', $output) }}">Unduh {{ $output->download_label }}</a>
                @endforeach
            </div>
        </div>
    @endif
@else
    <div class="card rounded-xl p-10 text-center">
        <h2 class="text-xl font-semibold">Belum ada periode tagihan</h2>
        <p class="text-gray-500 mt-2">Buat periode pertama untuk mulai membuat invoice.</p>
        <a class="btn btn-primary mt-6" href="{{ route('invoice-periods.create') }}">Buat periode</a>
    </div>
@endif

<div class="card rounded-xl p-6">
    <h2 class="font-semibold mb-3">Total invoice terbit, 6 bulan terakhir</h2>
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
        @foreach ($monthlyStats as $m)
            <div>
                <p class="text-sm text-gray-500">{{ $m['month'] }}</p>
                <p class="font-semibold">{{ $m['value'] ? 'Rp '.number_format($m['value'], 0, ',', '.') : '—' }}</p>
            </div>
        @endforeach
    </div>
</div>
@endsection
