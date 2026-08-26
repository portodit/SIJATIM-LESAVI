@extends('layouts.sijatim')

@section('content')
@php
$periodData = session('pending_period') ?? [
    'name' => 'TAGIHAN LAYANAN TELKOM - BANK JATIM PERIODE',
    'month' => date('n'),
    'year' => date('Y'),
    'notes' => '',
];
$bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthCode = strtoupper(substr(date('F', mktime(0, 0, 0, $periodData['month'], 1)), 0, 3));
$yearCode = substr($periodData['year'], -2);
$previewCode = $monthCode . $yearCode;
$steps = [
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
                <span class="badge badge-gray">{{ $previewCode }}</span>
                <span class="badge badge-warning">Draft</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $periodData['name'] ?? 'PERIODE INVOICE BARU' }}</h1>
            <p class="text-gray-500 mt-1">{{ $bulanNama[$periodData['month']] }} {{ $periodData['year'] }} &middot; PT Bank Pembangunan Daerah Jawa Timur Tbk</p>
        </div>
        <a href="{{ route('invoice-periods.index') }}" class="btn btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Kembali
        </a>
    </div>

    <div class="grid grid-cols-4 gap-6 mb-8">
        <div class="card p-5"><p class="text-sm text-gray-500">Total Lokasi</p><p class="text-3xl font-bold text-gray-900 mt-1">-</p></div>
        <div class="card p-5"><p class="text-sm text-gray-500">Status</p><p class="text-2xl font-bold text-gray-900 mt-1">Baru</p></div>
        <div class="card p-5"><p class="text-sm text-gray-500">Sub Tahap</p><p class="text-3xl font-bold text-gray-900 mt-1">0<span class="text-lg font-medium text-gray-400">/9</span></p></div>
        <div class="card p-5"><p class="text-sm text-gray-500">Periode Sebelumnya</p><p class="text-2xl font-bold text-gray-300 mt-1">-</p></div>
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
                    <div class="flex items-center justify-between mb-2"><span class="text-xs text-gray-500">Progress</span><span class="text-xs font-medium text-gray-600" id="card-progress-text">0 dari 9 tahap</span></div>
                    <div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-[#E6002D] rounded-full transition-all" id="card-progress" style="width: 0%"></div></div>
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
                <span class="badge badge-gray">{{ $previewCode }}</span>
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
                <p class="text-4xl font-bold text-gray-900 mb-3" id="progress-percent">0%</p>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden mb-2"><div class="h-full bg-[#E6002D] rounded-full transition-all duration-500" id="progress-fill" style="width: 0%"></div></div>
                <p class="text-sm text-gray-500" id="progress-detail">0 dari 9 tahap selesai</p>
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
        <button onclick="createPeriod()" id="btn-continue" class="btn btn-primary">
            Lanjutkan
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
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
            @foreach($steps as $num => $step)
            @php $stepNum = $num + 1; @endphp
            <div class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors cursor-pointer" data-step="{{ $stepNum }}" onclick="selectStep({{ $stepNum }})">
                <div class="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center shrink-0 text-xs font-semibold text-gray-500" id="modal-step-icon-{{ $stepNum }}">{{ $stepNum }}</div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">Sub Tahap {{ $stepNum }}. {{ $step['name'] }}</p>
                    <p class="text-xs text-gray-400 truncate">{{ $step['desc'] }}</p>
                </div>
                <span class="badge bg-gray-100 text-gray-600 shrink-0" id="modal-step-badge-{{ $stepNum }}">Pending</span>
            </div>
            @endforeach
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const totalSteps = 9;
let completedSteps = new Set();
const stepNames = ['','Mengelola list SSID seluruh lokasi','Mengisi type order dan periode','Mengisi data bandwidth','Benchmarking bandwidth dengan EoS','Mengisi data trafik jaringan','Mengisi dan menghitung harga layanan','Mengisi data gangguan layanan dan SLA','Menghitung nilai restitusi','Benchmarking harga dengan addendum'];
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

function showOverview() { document.getElementById('main-screen').classList.add('hidden'); document.getElementById('overview-screen').classList.remove('hidden'); window.scrollTo({top:0,behavior:'smooth'}); }
function hideOverview() { document.getElementById('overview-screen').classList.add('hidden'); document.getElementById('main-screen').classList.remove('hidden'); }
function showStepsModal() { const m=document.getElementById('steps-modal'); m.classList.remove('hidden'); m.classList.add('flex'); document.body.style.overflow='hidden'; }
function hideStepsModal() { const m=document.getElementById('steps-modal'); m.classList.add('hidden'); m.classList.remove('flex'); document.body.style.overflow=''; }
function closeModal(e) { if(e.target===e.currentTarget) hideStepsModal(); }
function selectStep(num) { hideStepsModal(); toggleStep(num); }

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

function updateStepInModal(num) {
    const icon=document.getElementById('modal-step-icon-'+num);
    const badge=document.getElementById('modal-step-badge-'+num);
    const row=document.querySelector('[data-step="'+num+'"]');
    if(completedSteps.has(num)){
        icon.className='w-7 h-7 rounded-full bg-green-500 flex items-center justify-center shrink-0';icon.innerHTML='<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        badge.className='badge bg-green-100 text-green-700 shrink-0';badge.textContent='Selesai';
        row.classList.add('bg-green-50');} else {
        icon.className='w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center shrink-0 text-xs font-semibold text-gray-500';icon.textContent=num;
        badge.className='badge bg-gray-100 text-gray-600 shrink-0';badge.textContent='Pending';
        row.classList.remove('bg-green-50');}}

function toggleStep(num) {
    if(completedSteps.has(num)){completedSteps.delete(num);} else {completedSteps.add(num);}
    updateProgress();updateNextStepInfo();updateStepInModal(num);}

async function createPeriod() {
    const btn = document.getElementById('btn-continue');
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full mr-2"></span>Membuat...';

    try {
        const response = await fetch('/invoice-periods', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                period_month: {{ $periodData['month'] }},
                period_year: {{ $periodData['year'] }},
                notes: '',
            })
        });

        const result = await response.json();

        if (response.ok) {
            // Redirect to the new period detail page
            window.location.href = '/invoice-periods/' + result.id;
        } else {
            const errorMsg = result.message || result.errors?.period_month?.[0] || 'Gagal membuat periode';
            alert(errorMsg);
            btn.disabled = false;
            btn.innerHTML = 'Lanjutkan <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat membuat periode');
        btn.disabled = false;
        btn.innerHTML = 'Lanjutkan <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
    }
}

updateProgress();
updateNextStepInfo();
</script>
@endpush
