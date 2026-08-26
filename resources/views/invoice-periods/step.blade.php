@extends('layouts.sijatim')

@push('styles')
<style>
/* Hide scrollbar but keep scrolling functionality */
.hide-scrollbar::-webkit-scrollbar {
    display: none;
}
.hide-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
@endpush

@section('content')
<!-- Container with max-width -->
<div class="max-w-7xl mx-auto">

<!-- Header -->
<div class="flex items-center justify-between mb-6">
    <div>
        <div class="flex items-center gap-2 mb-2">
            <span class="badge badge-gray">{{ $period->period_code }}</span>
            <span class="badge bg-[#E6002D]/10 text-[#E6002D]">Step {{ $currentStep->step_number }}</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $currentStep->step_name }}</h1>
        <p class="text-gray-500 mt-1">PT Bank Pembangunan Daerah Jawa Timur Tbk</p>
    </div>
    <a href="{{ route('invoice-periods.show', $period) }}" class="btn btn-secondary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Kembali
    </a>
</div>

<!-- Step Navigation - Clickable for completed steps -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-6 overflow-x-auto hide-scrollbar">
    <div class="flex items-center gap-2">
        @php
            $stepsArray = $steps->where('step_number', '>', 0)->values();
        @endphp
        @foreach($stepsArray as $index => $step)
            @php
                $stepStatus = $period->stepStatuses->firstWhere('invoice_step_id', $step->id);
                $isActive = $step->id === $currentStep->id;
                $isCompleted = ($stepStatus && in_array($stepStatus->status, ['completed', 'skipped'])) || ($stepStatus && $stepStatus->completed_at);
                $statusClass = $isCompleted ? 'bg-emerald-500 text-white' : ($isActive ? 'bg-[#E6002D] text-white' : 'bg-gray-100 text-gray-400');

                // Check if previous steps are completed (for clickable links)
                $canAccess = true;
                $blockedBy = null;
                if (!$isCompleted && !$isActive) {
                    // Check all previous steps
                    for ($i = 0; $i < $index; $i++) {
                        $prevStep = $stepsArray[$i];
                        $prevStatus = $period->stepStatuses->firstWhere('invoice_step_id', $prevStep->id);
                        if (!$prevStatus || !in_array($prevStatus->status, ['completed', 'skipped'])) {
                            $canAccess = false;
                            $blockedBy = $prevStep->step_name;
                            break;
                        }
                    }
                }
            @endphp
            @if($isCompleted && !$isActive)
                {{-- Completed steps are clickable --}}
                <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => $step->step_number]) }}"
                   class="flex items-center gap-2 px-3 py-2 rounded-lg shrink-0 hover:bg-emerald-50 transition-colors cursor-pointer group">
                    <div class="w-7 h-7 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <span class="text-sm whitespace-nowrap text-gray-600 group-hover:text-emerald-600">
                        {{ $step->step_name }}
                    </span>
                </a>
            @elseif($isActive)
                {{-- Current step - not clickable --}}
                <div class="flex items-center gap-2 px-3 py-2 rounded-lg shrink-0 bg-[#E6002D]/5 cursor-not-allowed">
                    <div class="w-7 h-7 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">
                        {{ $step->step_number }}
                    </div>
                    <span class="text-sm whitespace-nowrap text-[#E6002D] font-medium">
                        {{ $step->step_name }}
                    </span>
                </div>
            @elseif($canAccess)
                {{-- Can access because previous steps are done --}}
                <a href="{{ route('invoice-periods.step', ['invoicePeriod' => $period, 'step' => $step->step_number]) }}"
                   class="flex items-center gap-2 px-3 py-2 rounded-lg shrink-0 hover:bg-gray-50 transition-colors cursor-pointer group">
                    <div class="w-7 h-7 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">
                        {{ $step->step_number }}
                    </div>
                    <span class="text-sm whitespace-nowrap text-gray-600 group-hover:text-gray-900">
                        {{ $step->step_name }}
                    </span>
                </a>
            @else
                {{-- Blocked - show warning on click --}}
                <button onclick="showBlockedWarning('{{ $blockedBy }}')"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg shrink-0 hover:bg-gray-50 transition-colors cursor-not-allowed group">
                    <div class="w-7 h-7 rounded-full {{ $statusClass }} flex items-center justify-center text-xs font-semibold">
                        {{ $step->step_number }}
                    </div>
                    <span class="text-sm whitespace-nowrap text-gray-400 group-hover:text-gray-500">
                        {{ $step->step_name }}
                    </span>
                </button>
            @endif
            @if(!$loop->last)
                <div class="w-6 h-0.5 shrink-0 {{ $isCompleted ? 'bg-emerald-400' : 'bg-gray-200' }}"></div>
            @endif
        @endforeach
    </div>
</div>

<!-- Step Content -->
<div class="card">
    @php
    $partialName = strtolower(str_replace([' ', '_'], '-', $currentStep->step_code));
    @endphp
    @include('invoice-periods.partials.' . $partialName)
</div>

<!-- Warning Modal - Step belum selesai -->
<div id="warning-modal" class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl overflow-hidden">
        <div class="bg-yellow-50 px-6 py-4 border-b border-yellow-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-bold text-yellow-800">Tahapan Belum Selesai</h3>
                <p class="text-sm text-yellow-600">Selesaikan tahapan sebelumnya</p>
            </div>
        </div>
        <div class="p-6">
            <p class="text-gray-700 mb-2">Anda harus menyelesaikan tahapan sebelumnya terlebih dahulu:</p>
            <p class="font-semibold text-gray-900 bg-gray-50 p-3 rounded-lg mb-4" id="warning-message">
                <!-- Warning message will be injected here -->
            </p>
            <p class="text-sm text-gray-500 mb-4">Silakan selesaikan tahapan tersebut terlebih dahulu, kemudian Anda bisa melanjutkan ke tahapan berikutnya.</p>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
            <button onclick="closeWarningModal()" class="btn bg-gray-200 text-gray-700 hover:bg-gray-300">
                Nanti Saja
            </button>
            <a href="#" id="warning-redirect-link" class="btn bg-[#E6002D] text-white hover:bg-[#cc0029]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
                <span id="warning-redirect-text">Ke Halaman SSID</span>
            </a>
        </div>
    </div>
</div>

</div>
@endsection

@push('scripts')
<script>
const periodId = {{ $period->id }};
const currentStepCode = '{{ $currentStep->step_code }}';
const stepCodes = ['overview', 'ssid_list', 'type_order', 'bandwidth', 'bandwidth_benchmark', 'traffic_usage', 'price', 'sla', 'restitution', 'price_benchmark', 'finalization'];

function showBlockedWarning(stepName) {
    document.getElementById('warning-message').textContent = stepName;
    const modal = document.getElementById('warning-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    // Tentukan redirect berdasarkan step name
    let redirectStep = 1;
    let redirectText = 'Ke Halaman SSID';

    if (stepName.includes('SSID') || stepName.includes('List')) {
        redirectStep = 1;
        redirectText = 'Ke Halaman SSID';
    } else if (stepName.includes('Type Order')) {
        redirectStep = 2;
        redirectText = 'Ke Type Order & Bandwidth';
    } else if (stepName.includes('Bandwidth') || stepName.includes('EoS')) {
        redirectStep = 3;
        redirectText = 'Ke Bandwidth';
    } else if (stepName.includes('Trafik')) {
        redirectStep = 4;
        redirectText = 'Ke Trafik Jaringan';
    } else if (stepName.includes('Harga') || stepName.includes('Layanan')) {
        redirectStep = 5;
        redirectText = 'Ke Harga Layanan';
    } else if (stepName.includes('Gangguan') || stepName.includes('SLA')) {
        redirectStep = 6;
        redirectText = 'Ke Data Gangguan & SLA';
    } else if (stepName.includes('Restitusi')) {
        redirectStep = 7;
        redirectText = 'Ke Restitusi';
    } else if (stepName.includes('Benchmark') || stepName.includes('Addendum')) {
        redirectStep = 8;
        redirectText = 'Ke Benchmark';
    }

    const link = document.getElementById('warning-redirect-link');
    link.href = '/invoice-periods/' + periodId + '/step/' + redirectStep;
    document.getElementById('warning-redirect-text').textContent = redirectText;
}

function closeWarningModal() {
    const modal = document.getElementById('warning-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

// Close on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeWarningModal();
    }
});
</script>
@endpush
