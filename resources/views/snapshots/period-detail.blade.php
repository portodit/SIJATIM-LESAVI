@extends('layouts.sijatim')

@section('content')
@php
$stepsList = [
    ['name' => 'Mengelola list SSID seluruh lokasi', 'desc' => 'SSID baru, perubahan data, dan duplikasi'],
    ['name' => 'Mengisi type order dan periode', 'desc' => 'Existing, upgrade, downgrade, PSB, pemutusan, relokasi'],
    ['name' => 'Mengisi data bandwidth', 'desc' => 'Bandwidth eksisting, upgrade, dan downgrade'],
    ['name' => 'Benchmarking bandwidth dengan EoS', 'desc' => 'Validasi silang dengan data titik jaringan'],
    ['name' => 'Mengisi data trafik jaringan', 'desc' => 'Pemakaian average dari MRTG'],
    ['name' => 'Mengisi dan menghitung harga layanan', 'desc' => 'Harga eksisting, upgrade, downgrade, PSB, prorate'],
    ['name' => 'Mengisi data gangguan layanan dan SLA', 'desc' => 'Downtime, SLA actual, dan availability'],
    ['name' => 'Menghitung nilai restitusi', 'desc' => 'Restitusi tagihan dan restitusi over SLA'],
    ['name' => 'Benchmarking harga dengan addendum', 'desc' => 'Validasi akhir harga sebelum finalisasi'],
];
@endphp

<!-- Main Screen -->
<div id="main-screen">
    <div class="flex items-center justify-between mb-8">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="badge badge-gray">{{ $period->period_code }}</span>
                <span class="badge badge-warning">{{ ucfirst($period->status) }}</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $period->period_name }}</h1>
            <p class="text-gray-500 mt-1">PT Bank Pembangunan Daerah Jawa Timur Tbk</p>
        </div>
        <a href="{{ route('invoice-periods.index') }}" class="btn btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Kembali
        </a>
    </div>

    <div class="grid grid-cols-4 gap-6 mb-8">
        <div class="card p-5"><p class="text-sm text-gray-500">Total Lokasi</p><p class="text-3xl font-bold text-gray-900 mt-1">-</p></div>
        <div class="card p-5"><p class="text-sm text-gray-500">Status</p><p class="text-2xl font-bold text-gray-900 mt-1 capitalize">{{ $period->status }}</p></div>
        <div class="card p-5"><p class="text-sm text-gray-500">Sub Tahap</p><p class="text-3xl font-bold text-gray-900 mt-1">{{ $completedSteps }}<span class="text-lg font-medium text-gray-400">/{{ $totalSteps }}</span></p></div>
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

    <div class="grid grid-cols-2 gap-6">
        <div class="card cursor-pointer hover:border-[#E6002D] hover:shadow-lg transition-all" onclick="showOverview()">
            <div class="p-5">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-lg bg-[#E6002D] flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900">Excel Invoice Tagihan Telkom</h3>
                        <p class="text-sm text-gray-500 mt-1">Rekap data utama, summary, dan format cetak dalam satu file Excel.</p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between mb-2"><span class="text-xs text-gray-500">Progress</span><span class="text-xs font-medium text-gray-600" id="card-progress-text">{{ $completedSteps }} dari {{ $totalSteps }} tahap</span></div>
                    <div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-[#E6002D] rounded-full transition-all" id="card-progress" style="width: {{ $progressPercentage }}%"></div></div>
                </div>
            </div>
        </div>
        <div class="card opacity-60">
            <div class="p-5">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-400">Lampiran Daftar Lokasi & Rincian Biaya</h3>
                        <p class="text-sm text-gray-400 mt-1">Menunggu Excel invoice selesai sebelum dapat dimulai.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Overview Screen -->
<div id="overview-screen" class="hidden">
    <div class="flex items-center justify-between mb-8">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="badge badge-gray">{{ $period->period_code }}</span>
                <span class="badge badge-primary">Excel Invoice</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">Excel Invoice Tagihan Telkom</h1>
            <p class="text-gray-500 mt-1">Progress dan informasi tahap selanjutnya.</p>
        </div>
        <button onclick="hideOverview()" class="btn btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Kembali
        </button>
    </div>

    <div class="card p-5 mb-6">
        <div class="flex items-center gap-6">
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-600 mb-3">Progress Keseluruhan</p>
                <p class="text-4xl font-bold text-gray-900 mb-3" id="progress-percent">{{ $progressPercentage }}%</p>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden mb-2"><div class="h-full bg-[#E6002D] rounded-full transition-all duration-500" id="progress-fill" style="width: {{ $progressPercentage }}%"></div></div>
                <p class="text-sm text-gray-500" id="progress-detail">{{ $completedSteps }} dari {{ $totalSteps }} tahap selesai</p>
            </div>
            <div class="w-px h-20 bg-gray-200"></div>
            <div class="w-72">
                <p class="text-sm font-medium text-gray-600 mb-2">Tahap Berikutnya</p>
                <p class="font-semibold text-gray-900" id="next-step-name">Sub Tahap 1</p>
                <p class="text-sm text-gray-500 mt-1" id="next-step-desc">Mengelola list SSID seluruh lokasi</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button onclick="showStepsModal()" class="btn btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            Lihat Semua Tahapan
        </button>
        <button onclick="continueToNext()" class="btn btn-primary">Lanjutkan <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>
    </div>
</div>

<!-- Steps Modal -->
<div id="steps-modal" class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4" onclick="closeModal(event)">
    <div class="bg-white rounded-xl max-w-2xl w-full overflow-hidden shadow-xl" onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Semua Tahapan</h3>
            <button onclick="hideStepsModal()" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="p-3 max-h-96 overflow-y-auto">
            @foreach($stepsList as $num => $step)
            @php $stepNum = $num + 1; @endphp
            <a href="{{ route('invoice-periods.step1', $period) }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
                <div class="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center shrink-0 text-xs font-semibold text-gray-500">{{ $stepNum }}</div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">Sub Tahap {{ $stepNum }}. {{ $step['name'] }}</p>
                    <p class="text-xs text-gray-400 truncate">{{ $step['desc'] }}</p>
                </div>
                <span class="badge bg-gray-100 text-gray-600 shrink-0">Pending</span>
            </a>
            @endforeach
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const totalSteps = {{ $totalSteps }};
let completedSteps = new Set();
const stepNames = ['', 'Mengelola list SSID seluruh lokasi', 'Mengisi type order dan periode', 'Mengisi data bandwidth', 'Benchmarking bandwidth dengan EoS', 'Mengisi data trafik jaringan', 'Mengisi dan menghitung harga layanan', 'Mengisi data gangguan layanan dan SLA', 'Menghitung nilai restitusi', 'Benchmarking harga dengan addendum'];
const periodId = {{ $period->id }};

function showOverview() { document.getElementById('main-screen').classList.add('hidden'); document.getElementById('overview-screen').classList.remove('hidden'); window.scrollTo({top:0, behavior:'smooth'}); }
function hideOverview() { document.getElementById('overview-screen').classList.add('hidden'); document.getElementById('main-screen').classList.remove('hidden'); }
function showStepsModal() { const m=document.getElementById('steps-modal'); m.classList.remove('hidden'); m.classList.add('flex'); document.body.style.overflow='hidden'; }
function hideStepsModal() { const m=document.getElementById('steps-modal'); m.classList.add('hidden'); m.classList.remove('flex'); document.body.style.overflow=''; }
function closeModal(e) { if(e.target===e.currentTarget) hideStepsModal(); }

function updateProgress() {
    const p=Math.round((completedSteps.size/totalSteps)*100);
    document.getElementById('progress-percent').textContent=p+'%';
    document.getElementById('progress-detail').textContent=completedSteps.size+' dari '+totalSteps+' tahap selesai';
    document.getElementById('progress-fill').style.width=p+'%';
    document.getElementById('card-progress').style.width=p+'%';
    document.getElementById('card-progress-text').textContent=completedSteps.size+' dari '+totalSteps+' tahap';
}

function getNextStep() { for(let i=1;i<=totalSteps;i++){if(!completedSteps.has(i))return i;} return totalSteps; }

function updateNextStepInfo() {
    const n=getNextStep();
    document.getElementById('next-step-name').textContent='Sub Tahap '+n;
    document.getElementById('next-step-desc').textContent=stepNames[n];
}

function continueToNext() {
    // Redirect to step 1
    window.location.href = '/invoice-periods/' + periodId + '/step/1';
}

updateProgress();
updateNextStepInfo();
</script>
@endpush
