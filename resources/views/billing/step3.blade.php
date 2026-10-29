@extends('layouts.sijatim')

@php
    $latest = $revisions->first();
    $locked = $period->isLocked();
    $pending = $locked && $latest && in_array($latest->status, ['approved', 'generating']);
@endphp

@section('content')
<script>
    // While files are being generated, check every 5 seconds and reload once the status changes.
    window.sijGeneration = (url, status) => ({
        modal: null,
        init() {
            if (! status) return;
            const timer = setInterval(async () => {
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const data = await response.json();
                    if (data.generation && data.generation.status !== status) {
                        clearInterval(timer);
                        window.location.reload();
                    }
                } catch (e) {}
            }, 5000);
        },
    });
</script>

<div x-data="sijGeneration(@js(route('billing.readiness', $period)), @js($pending ? $latest->status : null))">
    <x-sij.period-header :period="$period" :step="3" />

    @if ($locked)
        @if ($latest?->status === 'ready')
            <div class="card rounded-xl p-6 mb-6 border-green-300">
                <h2 class="text-xl font-semibold text-green-800">Invoice versi {{ $latest->version }} sudah terbit</h2>
                <p class="text-gray-600 mt-1">Nomor {{ $metadata['invoice_number'] ?? '—' }} · Total <x-sij.money :value="$latest->snapshot['totals']['grand_total'] ?? null" class="font-semibold" /></p>
                <div class="flex flex-wrap gap-3 mt-5">
                    @foreach ($latest->outputs as $output)
                        <a class="btn {{ $output->output_type === 'summary_png' ? 'btn-secondary' : 'btn-primary' }} px-6 py-3 text-base" href="{{ route('billing.download', $output) }}">Unduh {{ $output->download_label }}</a>
                    @endforeach
                </div>
                @php($summary = $latest->outputs->firstWhere('output_type', 'summary_png'))
                @if ($summary)
                    <div class="mt-6">
                        <p class="text-sm font-medium mb-2">Ringkasan invoice (siap dibagikan sebagai gambar)</p>
                        <a href="{{ route('billing.download', $summary) }}" title="Klik untuk mengunduh">
                            <img src="{{ route('billing.download', $summary) }}" alt="Ringkasan invoice {{ $period->period_name }}" class="w-full max-w-3xl rounded-lg border border-gray-200">
                        </a>
                    </div>
                @else
                    <form method="POST" action="{{ route('billing.summary-image', $latest) }}" class="mt-5">
                        @csrf
                        <button class="btn btn-secondary">Buat gambar ringkasan (PNG)</button>
                        <span class="text-xs text-gray-500 ml-2">Versi ini diterbitkan sebelum fitur gambar ringkasan ada.</span>
                    </form>
                @endif
            </div>
        @elseif ($latest?->status === 'failed')
            <div class="card rounded-xl p-6 mb-6 border-red-300">
                <h2 class="text-xl font-semibold text-red-700">File invoice gagal dibuat</h2>
                <p class="text-gray-600 mt-1">{{ $latest->generation_error }}</p>
                <form method="POST" action="{{ route('billing.retry', $latest) }}" class="mt-5">
                    @csrf
                    <button class="btn btn-primary">Coba buat file lagi</button>
                </form>
            </div>
        @else
            <div class="card rounded-xl p-6 mb-6 border-blue-300">
                <h2 class="text-xl font-semibold">File Excel dan PDF sedang dibuat…</h2>
                <p class="text-gray-600 mt-1">Biasanya selesai dalam beberapa menit. Halaman ini akan diperbarui otomatis.</p>
                <p class="text-xs text-gray-500 mt-3">Pembuat file dinyalakan otomatis oleh aplikasi. Jika lebih dari 5 menit belum selesai, klik "Coba buat file lagi" pada riwayat versi di bawah.</p>
            </div>
        @endif
    @elseif (! $ready)
        <div class="card rounded-xl p-6 mb-6 border-amber-300 bg-amber-50">
            <h2 class="text-xl font-semibold">Belum siap diterbitkan</h2>
            <p class="text-gray-700 mt-1">
                @if (! $current)
                    Invoice belum dihitung atau data berubah sejak perhitungan terakhir.
                @else
                    Masih ada {{ $blockingCount }} hal yang perlu dilengkapi.
                @endif
            </p>
            <a class="btn btn-primary mt-5" href="{{ route('invoice-periods.step2', $period) }}">Ke Periksa & Hitung</a>
        </div>
    @else
        <div class="card rounded-xl p-6 mb-6">
            <h2 class="text-xl font-semibold">Siap diterbitkan</h2>
            <dl class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 text-sm">
                <div><dt class="text-gray-500">Nomor invoice</dt><dd class="font-semibold">{{ $metadata['invoice_number'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Jumlah SSID</dt><dd class="font-semibold">{{ number_format($totals['service_count'] ?? 0, 0, ',', '.') }}</dd></div>
                <div><dt class="text-gray-500">Subtotal + PPN</dt><dd class="font-semibold"><x-sij.money :value="$totals['subtotal'] ?? null" /> + <x-sij.money :value="$totals['tax'] ?? null" /></dd></div>
                <div><dt class="text-gray-500">Total tagihan</dt><dd class="text-xl font-bold text-[#E6002D]"><x-sij.money :value="$totals['grand_total'] ?? null" /></dd></div>
            </dl>
            <button type="button" class="btn btn-primary px-6 py-3 text-base mt-6" @click="modal = 'finalize'">Finalisasi & buat file</button>
        </div>

        <x-sij.modal name="finalize" title="Finalisasi invoice {{ $period->period_code }}?">
            <p class="text-sm text-gray-700">Setelah difinalisasi:</p>
            <ul class="list-disc ml-5 mt-2 space-y-1 text-sm text-gray-700">
                <li>Data periode ini dikunci dan tidak bisa diubah.</li>
                <li>File Excel dan PDF dibuat otomatis.</li>
                <li>Perubahan setelahnya harus melalui revisi.</li>
            </ul>
            <form method="POST" action="{{ route('billing.finalize', $period) }}" class="flex justify-end gap-2 mt-6">
                @csrf
                <input type="hidden" name="input_version" value="{{ $period->input_version }}">
                <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                <button class="btn btn-primary">Ya, finalisasi</button>
            </form>
        </x-sij.modal>
    @endif

    @include('billing.revisions')

    @if ($locked)
        <details class="mt-6 text-sm">
            <summary class="cursor-pointer text-gray-600">Perlu mengubah invoice yang sudah terbit?</summary>
            <form class="card rounded-xl p-5 mt-3 max-w-xl" method="POST" action="{{ route('billing.revise', $period) }}">
                @csrf
                <p class="text-gray-600 mb-3">Revisi membuka kembali data periode ini. File yang sudah terbit tetap tersimpan sebagai versi lama.</p>
                <input class="input" name="reason" required minlength="5" placeholder="Alasan revisi">
                <button class="btn btn-secondary mt-3">Buka revisi</button>
            </form>
        </details>
    @endif
</div>
@endsection
