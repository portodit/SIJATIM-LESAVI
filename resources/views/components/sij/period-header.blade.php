@props(['period', 'step' => null])
@php
    $state = $period->workflowState();
    $steps = [
        1 => ['Layanan & Downtime', 'Catat perubahan dan downtime', $period->lines()->exists()],
        2 => ['Periksa & Hitung', 'Lengkapi kekurangan, hitung total', $period->calculationIsCurrent()],
        3 => ['Terbitkan', 'Kunci invoice, unduh Excel & PDF', $period->isLocked()],
    ];
@endphp
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold">Tagihan {{ $period->period_name }}</h1>
            <span class="badge {{ $state['badge'] }}">{{ $state['label'] }}</span>
        </div>
        <p class="text-gray-500 mt-1">
            Pemakaian {{ $period->usage_name }} ({{ $period->start_date->format('d/m/Y') }} – {{ $period->end_date->format('d/m/Y') }}) · {{ $period->period_code }}
        </p>
    </div>
    <a href="{{ route('invoice-periods.advanced', $period) }}"
       class="text-sm {{ $step === null ? 'text-[#E6002D] font-semibold' : 'text-gray-500 hover:text-gray-800' }}">
        Menu Lanjutan
    </a>
</div>
<nav class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6" aria-label="Langkah">
    @foreach ($steps as $number => [$label, $hint, $done])
        <a href="{{ route('invoice-periods.step'.$number, $period) }}"
           @if ($step === $number) aria-current="step" @endif
           class="card rounded-xl p-4 flex items-center gap-3 transition-colors {{ $step === $number ? 'border-[#E6002D] ring-1 ring-[#E6002D]' : 'hover:border-gray-400' }}">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold
                         {{ $done ? 'bg-green-600 text-white' : ($step === $number ? 'bg-[#E6002D] text-white' : 'bg-gray-100 text-gray-600') }}">
                {!! $done ? '&#10003;' : $number !!}
            </span>
            <span>
                <span class="block font-semibold {{ $step === $number ? 'text-[#E6002D]' : '' }}">{{ $label }}</span>
                <span class="block text-xs text-gray-500">{{ $hint }}</span>
            </span>
        </a>
    @endforeach
</nav>
@if ($period->isLocked())
    <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        Invoice ini sudah difinalisasi sehingga data tidak dapat diubah. Unduh file atau buat revisi di langkah
        <a class="font-semibold underline" href="{{ route('invoice-periods.step3', $period) }}">Terbitkan</a>.
    </div>
@endif
