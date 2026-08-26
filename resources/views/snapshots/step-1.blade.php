@extends('layouts.sijatim')

@section('content')
@php
$period = $period ?? $snapshot ?? null;
$periodName = $period->period_name ?? $period->name ?? 'PERIODE BARU';
$periodCode = $period->period_code ?? $period->snapshot_code ?? 'N/A';
$hasImportedData = $invoiceLinesCount > 0;
@endphp

<!-- Header -->
<div class="flex items-center justify-between mb-6">
    <div>
        <div class="flex items-center gap-2 mb-2">
            <span class="badge badge-gray">{{ $periodCode }}</span>
            <span class="badge bg-red-100 text-[#E6002D]">Sub Tahap 1.1</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Mengelola List SSID Seluruh Lokasi</h1>
        <p class="text-gray-500 mt-1">{{ $periodName }} &middot; PT Bank Pembangunan Daerah Jawa Timur Tbk</p>
    </div>
</div>

<!-- Action Buttons -->
<div class="flex items-center gap-3 mb-6">
    <button onclick="downloadFormat()" class="btn btn-primary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Download Format Excel
    </button>
    <button onclick="toggleTable()" id="btn-toggle-table" class="btn btn-secondary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        Lihat Tabel SSID
    </button>
    <div class="flex-1"></div>
    <button onclick="markStepComplete()" class="btn bg-emerald-600 text-white hover:bg-emerald-700" id="btn-complete">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        Selesai & Lanjut
    </button>
</div>

<!-- Summary Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-6">
    <div class="card p-4 border border-gray-200 hover:border-blue-400 cursor-pointer transition-colors" onclick="toggleTable()">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500">Total SSID Master</p>
                <p class="text-2xl font-bold text-gray-900" id="stat-total-ssid">{{ $bankJatimLinksTotal }}</p>
            </div>
        </div>
    </div>
    <div class="card p-4 border border-gray-200">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500">Jenis Kantor</p>
                <p class="text-2xl font-bold text-gray-900">{{ $officeTypes->count() }}</p>
            </div>
        </div>
    </div>
    <div class="card p-4 border border-gray-200">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500">Kategori Lokasi</p>
                <p class="text-2xl font-bold text-gray-900">{{ $locationCategories->count() }}</p>
            </div>
        </div>
    </div>
    <div class="card p-4 border border-gray-200">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500">Layanan</p>
                <p class="text-2xl font-bold text-gray-900">{{ $services->count() }}</p>
            </div>
        </div>
    </div>
    <div class="card p-4 border border-gray-200">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500">Bandwidth</p>
                <p class="text-2xl font-bold text-gray-900">{{ $bandwidthOptions->count() }}</p>
            </div>
        </div>
    </div>
</div>

<!-- Import Status Info Card -->
<div id="import-status-card" class="card p-6 mb-6 border-l-4 {{ $hasImportedData ? 'border-green-500' : 'border-yellow-500' }}">
    <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-full {{ $hasImportedData ? 'bg-green-100' : 'bg-yellow-100' }} flex items-center justify-center shrink-0">
            @if($hasImportedData)
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            @else
                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            @endif
        </div>
        <div class="flex-1">
            <h3 class="font-bold text-gray-900 text-lg">
                @if($hasImportedData)
                    Data SSID Periode {{ $periodCode }} Sudah Diimport
                @else
                    Data SSID Periode {{ $periodCode }} Belum Diimport
                @endif
            </h3>
            <p class="text-gray-600 mt-1">
                @if($hasImportedData)
                    Terdapat <strong>{{ $invoiceLinesCount }} SSID</strong> yang sudah diimport untuk periode ini.
                    Klik total SSID di atas untuk melihat atau mengelola daftar SSID.
                @else
                    Untuk periode ini, silakan import data SSID dari file Excel.
                    Download format Excel terlebih dahulu, lalu import dengan data terbaru.
                @endif
            </p>
            <div class="mt-4 flex items-center gap-3">
                @if(!$hasImportedData)
                    <button onclick="downloadFormat()" class="btn btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Download Format
                    </button>
                    <button onclick="showImportModal()" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        Import Excel
                    </button>
                @else
                    <button onclick="toggleTable()" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Lihat Tabel SSID
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- SSID Table Section (Hidden by Default) -->
<div id="ssid-table-section" class="card overflow-hidden hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
        <div>
            <h3 class="font-semibold text-gray-900">Daftar SSID Lokasi</h3>
            <p class="text-sm text-gray-500 mt-1">Total: <span class="font-bold text-[#E6002D]" id="total-ssid-count">0</span> SSID</p>
        </div>
        <div class="flex items-center gap-3">
            <select id="per-page-select" onchange="changePerPage()" class="input py-1.5 text-sm">
                <option value="10">10 per halaman</option>
                <option value="25">25 per halaman</option>
                <option value="50">50 per halaman</option>
                <option value="100">100 per halaman</option>
            </select>
            <button onclick="toggleTable()" class="text-sm text-gray-500 hover:text-gray-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr class="bg-gray-50">
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">NO</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">JENIS KANTOR</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">ALAMAT</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">KATEGORI</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">LAYANAN</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">SSID</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">IP HOST</th>
                    <th class="text-xs uppercase text-gray-500 font-semibold px-4 py-3 text-left">TGL MULAI</th>
                </tr>
            </thead>
            <tbody id="ssid-table-body">
                <tr>
                    <td colspan="8" class="text-center py-8 text-gray-500">
                        <div class="animate-spin w-8 h-8 border-2 border-gray-300 border-t-[#E6002D] rounded-full mx-auto mb-2"></div>
                        Memuat data...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <div class="px-5 py-4 border-t border-gray-200 flex items-center justify-between bg-gray-50">
        <div class="text-sm text-gray-500">
            Menampilkan <span id="pagination-from">0</span>-<span id="pagination-to">0</span> dari <span id="pagination-total">0</span>
        </div>
        <div class="flex items-center gap-1" id="pagination-buttons">
            <!-- Filled by JS -->
        </div>
    </div>
</div>

@endsection

<!-- Import Modal -->
<div id="import-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4" onclick="closeImportModal()">
    <div class="bg-white rounded-xl max-w-md w-full shadow-xl" onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Import Data SSID</h3>
            <button onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-5">
            <div id="import-dropzone" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer hover:border-[#E6002D] transition-colors">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                <p class="text-gray-600 font-medium">Drop file Excel di sini</p>
                <p class="text-sm text-gray-400 mt-1">atau klik untuk pilih file</p>
                <p class="text-xs text-gray-400 mt-2">Format: .xlsx, .xls (maks. 10MB)</p>
                <input type="file" id="file-input" accept=".xlsx,.xls" class="hidden">
            </div>
            <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                <p class="text-xs text-blue-700">
                    <strong>Kolom:</strong> NO, JENIS KANTOR, KATEGORI LOKASI, LAYANAN, SSID, IP HOST, TANGGAL MULAI, BANDWIDTH
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Import Progress Modal -->
<div id="import-progress-modal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-sm w-full shadow-xl p-8 text-center">
        <div class="animate-spin w-14 h-14 border-4 border-gray-200 border-t-[#E6002D] rounded-full mx-auto mb-4"></div>
        <h3 class="font-semibold text-gray-900 mb-2">Memproses File...</h3>
        <p class="text-sm text-gray-500" id="progress-text">Membaca data dari file Excel</p>
    </div>
</div>

<!-- Toast Notification -->
<div id="success-toast" class="fixed bottom-4 right-4 bg-emerald-600 text-white px-4 py-3 rounded-lg shadow-lg hidden z-50 flex items-center gap-2">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <span id="toast-message">Berhasil!</span>
</div>

@push('scripts')
<script>
const periodId = {{ ($period->id ?? $snapshot->id ?? 0) }};
let currentPage = 1;
let perPage = 10;
let totalPages = 1;
let tableVisible = false;

document.addEventListener('DOMContentLoaded', () => {
    setupEvents();
});

function setupEvents() {
    // Import dropzone
    const dz = document.getElementById('import-dropzone');
    if (dz) {
        dz.addEventListener('click', () => document.getElementById('file-input').click());
        document.getElementById('file-input')?.addEventListener('change', handleFileSelect);
        dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('border-[#E6002D]'); });
        dz.addEventListener('dragleave', () => dz.classList.remove('border-[#E6002D]'));
        dz.addEventListener('drop', (e) => {
            e.preventDefault();
            dz.classList.remove('border-[#E6002D]');
            if (e.dataTransfer.files[0]) {
                document.getElementById('file-input').files = e.dataTransfer.files;
                handleFileSelect({ target: document.getElementById('file-input') });
            }
        });
    }
}

function toggleTable() {
    tableVisible = !tableVisible;
    const section = document.getElementById('ssid-table-section');
    const btn = document.getElementById('btn-toggle-table');

    if (tableVisible) {
        section.classList.remove('hidden');
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
            </svg>
            Sembunyikan Tabel
        `;
        loadSsidData();
    } else {
        section.classList.add('hidden');
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
            Lihat Tabel SSID
        `;
    }
}

function loadSsidData() {
    const tbody = document.getElementById('ssid-table-body');
    tbody.innerHTML = `
        <tr>
            <td colspan="8" class="text-center py-8 text-gray-500">
                <div class="animate-spin w-8 h-8 border-2 border-gray-300 border-t-[#E6002D] rounded-full mx-auto mb-2"></div>
                Memuat data...
            </td>
        </tr>
    `;

    fetch(`/invoice-periods/${periodId}/ssid-data?per_page=${perPage}&page=${currentPage}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderTable(data.data);
                renderPagination(data.pagination);
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Gagal memuat data SSID', 'error');
        });
}

function renderTable(links) {
    const tbody = document.getElementById('ssid-table-body');
    document.getElementById('total-ssid-count').textContent = links.length || 0;

    if (!links || links.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-8 text-gray-500">
                    Tidak ada data SSID
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = links.map((link, idx) => `
        <tr class="hover:bg-gray-50 border-b border-gray-100">
            <td class="px-4 py-3 font-medium text-gray-900">${link.no || (idx + 1)}</td>
            <td class="px-4 py-3">
                <span class="badge badge-blue">${link.office_type?.office_type_name || '-'}</span>
            </td>
            <td class="px-4 py-3 text-gray-600 text-sm max-w-xs truncate" title="${link.address || ''}">${link.address || '-'}</td>
            <td class="px-4 py-3 text-gray-600">${link.location_category?.location_category_name || '-'}</td>
            <td class="px-4 py-3 text-gray-600">${link.service?.service_name || '-'}</td>
            <td class="px-4 py-3 font-mono text-sm text-blue-600 font-medium">${link.ssid || '-'}</td>
            <td class="px-4 py-3 font-mono text-sm text-gray-600">${link.ip_host_telkom || '-'}</td>
            <td class="px-4 py-3 text-gray-600">${formatDate(link.service_start_date)}</td>
        </tr>
    `).join('');
}

function renderPagination(pagination) {
    const { current_page, per_page, total, last_page, from, to } = pagination;
    totalPages = last_page;
    currentPage = current_page;

    // Update info
    document.getElementById('pagination-from').textContent = from || 0;
    document.getElementById('pagination-to').textContent = to || 0;
    document.getElementById('pagination-total').textContent = total;
    document.getElementById('total-ssid-count').textContent = total;

    // Update per page select
    document.getElementById('per-page-select').value = per_page;

    // Render buttons
    const container = document.getElementById('pagination-buttons');
    let buttons = '';

    // Previous button
    buttons += `<button onclick="goToPage(${current_page - 1})" class="px-3 py-1.5 rounded text-sm ${current_page === 1 ? 'text-gray-300 cursor-not-allowed' : 'text-gray-600 hover:bg-gray-100'}" ${current_page === 1 ? 'disabled' : ''}>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
    </button>`;

    // Page numbers
    let startPage = Math.max(1, current_page - 2);
    let endPage = Math.min(last_page, current_page + 2);

    if (startPage > 1) {
        buttons += `<button onclick="goToPage(1)" class="px-3 py-1.5 rounded text-sm text-gray-600 hover:bg-gray-100">1</button>`;
        if (startPage > 2) {
            buttons += `<span class="px-2 text-gray-400">...</span>`;
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        buttons += `<button onclick="goToPage(${i})" class="px-3 py-1.5 rounded text-sm ${i === current_page ? 'bg-[#E6002D] text-white' : 'text-gray-600 hover:bg-gray-100'}">${i}</button>`;
    }

    if (endPage < last_page) {
        if (endPage < last_page - 1) {
            buttons += `<span class="px-2 text-gray-400">...</span>`;
        }
        buttons += `<button onclick="goToPage(${last_page})" class="px-3 py-1.5 rounded text-sm text-gray-600 hover:bg-gray-100">${last_page}</button>`;
    }

    // Next button
    buttons += `<button onclick="goToPage(${current_page + 1})" class="px-3 py-1.5 rounded text-sm ${current_page === last_page ? 'text-gray-300 cursor-not-allowed' : 'text-gray-600 hover:bg-gray-100'}" ${current_page === last_page ? 'disabled' : ''}>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </button>`;

    container.innerHTML = buttons;
}

function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    loadSsidData();
}

function changePerPage() {
    perPage = parseInt(document.getElementById('per-page-select').value);
    currentPage = 1;
    loadSsidData();
}

function formatDate(d) {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

// Import functions
function showImportModal() {
    document.getElementById('import-modal')?.classList.remove('hidden');
    document.getElementById('import-modal')?.classList.add('flex');
}

function closeImportModal() {
    document.getElementById('import-modal')?.classList.add('hidden');
    document.getElementById('import-modal')?.classList.remove('flex');
    document.getElementById('file-input').value = '';
}

function handleFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;
    showToast('Fitur import Excel akan segera hadir', 'error');
}

function downloadFormat() {
    // Direct download CSV
    window.location.href = `/invoice-periods/${periodId}/ssid-format`;
}

function markStepComplete() {
    fetch(`/invoice-periods/${periodId}/step-status`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            step_code: 'ssid_list',
            status: 'completed',
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Sub Tahap 1.1 selesai!');
            setTimeout(() => window.location.href = `/invoice-periods/${periodId}`, 1000);
        } else {
            showToast('Gagal menyimpan progress', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Gagal menyimpan progress', 'error');
    });
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('success-toast');
    document.getElementById('toast-message').textContent = msg;
    t.classList.remove('hidden', 'bg-red-600', 'bg-emerald-600');
    if (type === 'error') t.classList.add('bg-red-600');
    else t.classList.add('bg-emerald-600');
    setTimeout(() => t.classList.add('hidden'), 3000);
}
</script>
@endpush
