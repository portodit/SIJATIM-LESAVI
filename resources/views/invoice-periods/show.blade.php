@extends('layouts.sijatim')

@section('content')
<!-- Header -->
<div class="flex items-center justify-between mb-8">
    <div>
        <div class="flex items-center gap-2 mb-2">
            <span class="badge badge-gray">{{ $period->period_code }}</span>
            <span class="badge badge-{{ $period->status_badge_color }}">{{ $period->status_label }}</span>
            @if($period->is_locked)
                <span class="badge badge-gray">Terkunci</span>
            @endif
        </div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $period->period_name }}</h1>
        <p class="text-gray-500 mt-1">PT Bank Pembangunan Daerah Jawa Timur Tbk</p>
    </div>
    <a href="{{ route('invoice-periods.index') }}" class="btn btn-secondary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        Kembali
    </a>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-4 gap-6 mb-8">
    <div class="card p-5">
        <p class="text-sm text-gray-500">Total Lokasi</p>
        <p class="text-3xl font-bold text-gray-900 mt-1">{{ $period->lines->count() ?? '-' }}</p>
    </div>
    <div class="card p-5">
        <p class="text-sm text-gray-500">Status</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $period->status_label }}</p>
    </div>
    <div class="card p-5">
        <p class="text-sm text-gray-500">Sub Tahap</p>
        <p class="text-3xl font-bold text-gray-900 mt-1">{{ $completedSteps }}<span class="text-lg font-medium text-gray-400">/{{ $totalSteps }}</span></p>
    </div>
    <div class="card p-5">
        <p class="text-sm text-gray-500">Periode Sebelumnya</p>
        <p class="text-2xl font-bold text-gray-300 mt-1">
            @if($previousPeriod)
                {{ $previousPeriod->period_code }}
            @else
                -
            @endif
        </p>
    </div>
</div>

<!-- Excel Invoice Card -->
<div class="card mb-6">
    <div class="p-6">
        <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-lg bg-[#E6002D] flex items-center justify-center shrink-0">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div class="flex-1">
                <h3 class="font-bold text-gray-900 text-lg">EXCEL INVOICE TAGIHAN TELKOM</h3>
                <p class="text-sm text-gray-500 mt-1">Progress dan informasi tahap selanjutnya.</p>
            </div>
        </div>

        <!-- Progress Section -->
        <div class="mt-6 border-t border-gray-100 pt-6">
            <div class="flex items-center gap-6">
                <!-- Progress Bar -->
                <div class="flex-1">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-600">Progress Keseluruhan</span>
                        <span class="text-sm font-bold text-[#E6002D]" id="progress-percent">{{ $progressPercentage }}%</span>
                    </div>
                    <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-[#E6002D] rounded-full transition-all duration-500" id="progress-fill" style="width: {{ $progressPercentage }}%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">{{ $completedSteps }} dari {{ $totalSteps }} tahap selesai</p>
                </div>

                <div class="w-px h-16 bg-gray-200"></div>

                <!-- Next Step Info -->
                <div class="w-72">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Tahap Berikutnya</p>
                    <p class="font-bold text-gray-900" id="next-step-name">Sub Tahap 1</p>
                    <p class="text-sm text-gray-500 mt-0.5" id="next-step-desc">Mengelola list SSID seluruh lokasi</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 flex items-center gap-3 border-t border-gray-100 pt-6">
            <button onclick="showStepsModal()" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                </svg>
                Lihat Semua Tahapan
            </button>
            <button onclick="continueToNext()" class="btn bg-[#E6002D] text-white hover:bg-[#cc0029]">
                Lanjutkan
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- Steps Modal -->
<div id="steps-modal" class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" onclick="closeModal(event)">
    <div class="bg-white rounded-2xl max-w-2xl w-full shadow-2xl max-h-[85vh] flex flex-col" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between shrink-0">
            <div>
                <h3 class="font-bold text-gray-900 text-lg">SEMUA TAHAPAN</h3>
                <p class="text-sm text-gray-500">{{ $period->period_name }}</p>
            </div>
            <button onclick="closeStepsModal()" class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 hover:bg-gray-200 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-4 overflow-y-auto flex-1">
            @foreach($steps as $item)
            @php
                $step = $item['step'];
                if ($step->step_number == 0) continue; // Skip overview
                $status = $item['status'];
                $isCompleted = ($status && in_array($status->status, ['completed', 'skipped'])) || ($status && $status->completed_at);
                $isInProgress = $status && $status->status === 'in_progress';
                $stepNum = $step->step_number;
                $stepName = $step->step_name;
                $stepDesc = $step->description ?? '';
            @endphp
            <div class="flex items-center gap-4 p-4 rounded-xl mb-2 border border-gray-100 {{ $isCompleted ? 'bg-green-50 border-green-200' : '' }}">
                <!-- Icon -->
                <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0
                    @if($isCompleted) bg-green-500
                    @elseif($isInProgress) bg-[#E6002D]
                    @else bg-gray-100
                    @endif">
                    @if($isCompleted)
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    @elseif($isInProgress)
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    @else
                        <span class="text-sm font-bold text-gray-500">{{ $stepNum }}</span>
                    @endif
                </div>

                <!-- Info -->
                <div class="flex-1">
                    <p class="font-semibold text-gray-900">
                        <span class="text-gray-400 font-normal">Sub Tahap {{ $stepNum }}.</span>
                        {{ $stepName }}
                    </p>
                    @if($stepDesc)
                        <p class="text-sm text-gray-500 mt-0.5">{{ $stepDesc }}</p>
                    @endif
                </div>

                <!-- Status -->
                <div>
                    @if($isCompleted)
                        <span class="text-xs font-medium text-green-600 bg-green-100 px-2.5 py-1 rounded-full">Selesai</span>
                    @elseif($isInProgress)
                        <span class="text-xs font-medium text-white bg-[#E6002D] px-2.5 py-1 rounded-full">Dikerjakan</span>
                    @else
                        <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full">Pending</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
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
            <p class="text-sm text-gray-500 mb-4">Silakan selesaikan tahapan tersebut di halaman ini, kemudian Anda bisa melanjutkan ke tahapan berikutnya.</p>
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

@endsection

@push('scripts')
<script>
const totalSteps = {{ $totalSteps }};
const completedSteps = {{ $completedSteps }};
const periodId = {{ $period->id }};
const currentStepCode = '{{ $period->current_step_code ?? "overview" }}';

const stepNames = {
    'overview': 'Overview',
    'ssid_list': 'Mengelola list SSID seluruh lokasi',
    'type_order': 'Mengisi type order dan periode',
    'bandwidth': 'Mengisi data bandwidth',
    'bandwidth_benchmark': 'Benchmarking bandwidth dengan EoS',
    'traffic_usage': 'Mengisi data trafik jaringan',
    'price': 'Mengisi dan menghitung harga layanan',
    'sla': 'Mengisi data gangguan layanan dan SLA',
    'restitution': 'Menghitung nilai restitusi',
    'price_benchmark': 'Benchmarking harga dengan addendum',
    'finalization': 'Finalisasi'
};

const stepCodes = ['overview', 'ssid_list', 'type_order', 'bandwidth', 'bandwidth_benchmark', 'traffic_usage', 'price', 'sla', 'restitution', 'price_benchmark', 'finalization'];

// Step order for validation (index = step_number)
const stepOrder = [
    'overview',       // 0
    'ssid_list',      // 1
    'type_order',     // 2
    'bandwidth',      // 3
    'bandwidth_benchmark', // 4
    'traffic_usage',  // 5
    'price',          // 6
    'sla',            // 7
    'restitution',    // 8
    'price_benchmark',// 9
    'finalization'    // 10
];

function getNextStepCode() {
    const currentIndex = stepCodes.indexOf(currentStepCode);
    if (currentIndex >= 0 && currentIndex < stepCodes.length - 1) {
        return stepCodes[currentIndex + 1];
    }
    // Fallback: find first non-completed step
    return 'ssid_list';
}

function updateNextStepInfo() {
    const nextCode = getNextStepCode();
    document.getElementById('next-step-name').textContent = 'Sub Tahap 1';
    document.getElementById('next-step-desc').textContent = stepNames[nextCode] || 'Mengelola list SSID seluruh lokasi';
}

function showStepsModal() {
    const modal = document.getElementById('steps-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeStepsModal() {
    const modal = document.getElementById('steps-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

function closeModal(e) {
    if (e.target === e.currentTarget) {
        closeStepsModal();
    }
}

function showWarningModal(message) {
    document.getElementById('warning-message').textContent = message;
    const modal = document.getElementById('warning-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    // Tentukan redirect berdasarkan step yang belum selesai
    let redirectStep = 1; // default ke step 1 (SSID List)
    let redirectText = 'Ke Halaman SSID';

    if (message.includes('SSID')) {
        redirectStep = 1;
        redirectText = 'Ke Halaman SSID';
    } else if (message.includes('Type Order')) {
        redirectStep = 2;
        redirectText = 'Ke Type Order & Bandwidth';
    } else if (message.includes('Bandwidth')) {
        redirectStep = 3;
        redirectText = 'Ke Bandwidth';
    } else if (message.includes('Trafik')) {
        redirectStep = 4;
        redirectText = 'Ke Trafik Jaringan';
    } else if (message.includes('Harga')) {
        redirectStep = 5;
        redirectText = 'Ke Harga Layanan';
    } else if (message.includes('Gangguan') || message.includes('SLA')) {
        redirectStep = 6;
        redirectText = 'Ke Data Gangguan & SLA';
    } else if (message.includes('Restitusi')) {
        redirectStep = 7;
        redirectText = 'Ke Restitusi';
    } else if (message.includes('Benchmark')) {
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

function continueToNext() {
    const nextCode = getNextStepCode();
    const nextIndex = stepCodes.indexOf(nextCode);

    // Find first non-completed step from current position
    // The API will validate, but we can pre-check for better UX
    // Just redirect - the controller will validate
    window.location.href = '/invoice-periods/' + periodId + '/step/' + (nextIndex);
}

function tryAccessStep(stepNumber) {
    window.location.href = '/invoice-periods/' + periodId + '/step/' + stepNumber;
}

// Show warning modal if there's a flash message
@if(session('warning'))
    document.addEventListener('DOMContentLoaded', function() {
        const msg = {!! json_encode(session('warning')) !!};
        showWarningModal(msg);
    });
@endif

// Init
updateNextStepInfo();

// Close on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStepsModal();
        closeWarningModal();
    }
});
</script>
@endpush
