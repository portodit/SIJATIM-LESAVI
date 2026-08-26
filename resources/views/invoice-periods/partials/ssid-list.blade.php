{{-- Step: SSID List --}}
<div class="p-6">
    {{-- ========== STATE: BELUM ADA DATA - IMPORT SECTION ========== --}}
    @if($invoiceLinesCount == 0)
        <div id="ssid-placeholder">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Import SSID Periode Ini</h3>
            <p class="text-sm text-gray-500 mb-4">Paste list SSID dari data Bank jatim</p>

        {{-- Import Card --}}
        <div class="max-w-4xl">
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <span class="font-medium text-gray-700">Import SSID</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <span>Total {{ $stepData['bankJatimLinks']->count() }} SSID tersedia di master data</span>
                    </div>
                </div>
                <div class="p-4">
                    <textarea id="ssid-input" class="w-full h-40 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D] resize-none font-mono text-sm"
                        placeholder="Paste SSID dari Excel/CSV Bank Jatim
Contoh:
1155255333
12121212
4700020-1
atau pisahkan dengan koma:
1155255333, 12121212, 4700020-1"></textarea>
                    <div class="flex items-center justify-between mt-4">
                        <label class="btn btn-secondary cursor-pointer text-center">
                            <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            Upload CSV
                            <input type="file" id="file-upload" accept=".csv,.txt" class="hidden" onchange="handleFileUpload(event)">
                        </label>
                        <button onclick="checkSsid()" id="btn-check" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Import SSID
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </div>
    @endif

    {{-- ========== STATE: ADA DATA ========== --}}
    @if($invoiceLinesCount > 0)
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Daftar SSID Lokasi</h3>
            <p class="text-sm text-gray-500 mt-1">Import & kelola data SSID periode ini</p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="downloadFormat()" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Download Format
            </button>
            <button onclick="toggleTable()" id="toggle-table-btn" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                Lihat Data
            </button>
        </div>
    </div>

    {{-- Info Cards --}}
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="bg-blue-50 rounded-lg p-4">
            <p class="text-xs text-blue-600 font-medium">SSID di Invoice</p>
            <p class="text-2xl font-bold text-blue-700" id="total-ssid-count">{{ $invoiceLinesCount }}</p>
            <p class="text-xs text-blue-500 mt-1">dari {{ $stepData['bankJatimLinks']->count() }} master</p>
        </div>
        <div class="bg-purple-50 rounded-lg p-4">
            <p class="text-xs text-purple-600 font-medium">Jenis Kantor</p>
            <p class="text-2xl font-bold text-purple-700">{{ $stepData['officeTypes']->count() }}</p>
        </div>
        <div class="bg-green-50 rounded-lg p-4">
            <p class="text-xs text-green-600 font-medium">Kategori</p>
            <p class="text-2xl font-bold text-green-700">{{ $stepData['locationCategories']->count() }}</p>
        </div>
        <div class="bg-orange-50 rounded-lg p-4">
            <p class="text-xs text-orange-600 font-medium">Layanan</p>
            <p class="text-2xl font-bold text-orange-700">{{ $stepData['services']->count() }}</p>
        </div>
    </div>

    {{-- Quick Filter: New SSIDs --}}
    @if($stepData['pendingNewSsidsCount'] > 0)
    <div class="mb-4">
        <button onclick="showNewSsids()" class="btn bg-amber-500 text-white hover:bg-amber-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Lihat {{ $stepData['pendingNewSsidsCount'] }} SSID Baru (Belum Dilengkapi)
        </button>
    </div>
    @endif

        {{-- CTA BUTTONS --}}
        <div class="flex items-center gap-2 mb-4">
            @if($stepData['pendingNewSsidsCount'] == 0)
            <button onclick="showImportSection()" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Import SSID Tambahan
            </button>
            @else
            <button disabled class="btn btn-primary opacity-50 cursor-not-allowed" title="Selesaikan SSID baru terlebih dahulu">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Import SSID Tambahan
            </button>
            @endif

            <button onclick="openAddSsidModal()" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Tambah SSID
            </button>

            <button onclick="confirmReimport()" class="btn bg-orange-500 text-white hover:bg-orange-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Reset Ulang
            </button>

            @if($stepData['pendingNewSsidsCount'] == 0)
            <button onclick="completeAndContinue()" class="btn bg-emerald-600 text-white hover:bg-emerald-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                Selesai & Lanjut
            </button>
            @endif
        </div>

        {{-- COMPLETE SECTION --}}
        <div id="complete-section" class="hidden mb-6">
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-emerald-800">Sub Tahapan 1.1 Selesai</p>
                        <p class="text-sm text-emerald-600">
                            Sejauh ini <span class="font-bold" id="complete-ssid-count">{{ $invoiceLinesCount }}</span> data SSID tercatat untuk penagihan di periode ini.
                        </p>
                        <p class="text-xs text-emerald-500 mt-1">
                            Terakhir diselesaikan pada <span id="last-complete-date">{{ now()->format('d-m-Y H:i') }}</span>
                        </p>
                        {{-- Countdown & Redirect --}}
                        <div id="countdown-container" class="hidden mt-3">
                            <p class="text-sm text-emerald-700" id="countdown-text">
                                Mengalihkan ke Sub Tahap 2 dalam <span id="countdown-number" class="font-bold text-lg">10</span> detik...
                            </p>
                            <button onclick="cancelRedirect()" class="mt-2 px-3 py-1 bg-emerald-200 hover:bg-emerald-300 text-emerald-800 text-sm rounded-lg transition-colors">
                                Batal & Tetap di Halaman Ini
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- IMPORT SECTION --}}
        <div id="import-section" class="hidden mb-6">
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h4 class="font-semibold text-gray-900">Import SSID Tambahan</h4>
                        <p class="text-xs text-gray-500 mt-1">Paste list SSID dari Bank Jatim</p>
                    </div>
                    <div class="flex gap-2">
                        <label class="btn btn-secondary cursor-pointer text-center">
                            <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            Upload CSV
                            <input type="file" id="file-upload-additional" accept=".csv,.txt" class="hidden" onchange="handleFileUploadAdditional(event)">
                        </label>
                        <button onclick="hideImportSection()" class="btn btn-secondary">Batal</button>
                    </div>
                </div>
                <div class="p-4">
                    <textarea id="ssid-input-additional" class="w-full h-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D] resize-none font-mono text-sm"
                        placeholder="Paste SSID dari Excel/CSV Bank Jatim, contoh:
SSID-001
SSID-002
SSID-003
atau pisahkan dengan koma:
SSID-001, SSID-002, SSID-003"></textarea>
                    <div class="flex items-center justify-end mt-4">
                        <button onclick="checkSsidAdditional()" id="btn-check-additional" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Import SSID
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- SSID BARU PENDING SECTION --}}
        @if($stepData['pendingNewSsidsCount'] > 0)
        <div id="pending-ssid-section" class="bg-amber-50 border border-amber-300 rounded-lg overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-amber-200 flex items-center justify-between bg-gradient-to-r from-amber-50 to-amber-100">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-amber-200 flex items-center justify-center">
                        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div>
                        <h4 class="font-semibold text-amber-800">SSID Baru - Harus Diselesaikan Dahulu</h4>
                        <p class="text-xs text-amber-600">{{ $stepData['pendingNewSsidsCount'] }} SSID tidak ditemukan di master data</p>
                    </div>
                </div>
            </div>
            <div class="p-4 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">SSID</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($stepData['invoiceLines'] as $line)
                                @if($line['is_new_ssid'] && (!$line['office_type']['name'] || $line['office_type']['name'] === '-'))
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-blue-600 font-medium">{{ $line['ssid'] }}</td>
                                    <td class="px-4 py-3">
                                        <button onclick="openAddModal('{{ $line['ssid'] }}', {{ $line['id'] }})" class="btn btn-sm bg-amber-500 text-white hover:bg-amber-600 mr-2">+ Tambah</button>
                                        <button onclick="removePendingSsid({{ $line['id'] }}, '{{ $line['ssid'] }}')" class="btn btn-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Hapus</button>
                                    </td>
                                </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        {{-- DATA TABLE --}}
        <div id="ssid-table-container">
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <h4 class="font-semibold text-gray-900">Data SSID di Periode Ini</h4>
                            <p class="text-xs text-gray-500">Total <span class="font-bold" id="invoice-lines-total">{{ $invoiceLinesCount }}</span> SSID</p>
                        </div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <div class="relative">
                                <input type="text" id="search-ssid" placeholder="Cari SSID..." class="pl-8 pr-4 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D] w-48" oninput="handleSearch()">
                                <svg class="w-4 h-4 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" id="filter-new-ssid" onchange="handleFilter()" class="w-4 h-4 text-[#E6002D] border-gray-300 rounded focus:ring-[#E6002D]">
                                <span class="text-sm text-gray-700">Data Baru Ditambahkan</span>
                                <span id="new-count-badge-small" class="bg-amber-100 text-amber-700 text-xs px-1.5 py-0.5 rounded-full">0</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-500">Tampilkan:</span>
                                <select id="per-page-select" onchange="changePerPage()" class="text-sm border border-gray-300 rounded px-2 py-1 focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto max-h-96">
                    <table class="w-full">
                        <thead class="bg-gray-100 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">No</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Jenis Kantor</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Kategori</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Layanan</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">SSID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Alamat</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">IP Host</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Tgl Mulai</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="ssid-table-body"></tbody>
                    </table>
                </div>
                <div class="bg-gray-50 px-4 py-3 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-500">
                            Menampilkan <span id="showed-count">0</span> dari <span id="total-count">0</span> SSID
                            <span id="filtered-info" class="ml-2 text-amber-600 font-medium hidden"></span>
                        </div>
                        <div class="flex items-center gap-1" id="pagination-buttons"></div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- Add SSID Modal --}}
<div id="add-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full max-h-[80vh] shadow-2xl flex flex-col">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between shrink-0">
            <h5 class="font-semibold text-gray-900">Tambah SSID Baru</h5>
            <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-4 overflow-y-auto flex-1">
            <input type="hidden" id="new-ssid-value">
            <input type="hidden" id="pending-line-id" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">SSID *</label>
                <input type="text" id="form-ssid" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kantor *</label>
                <select id="form-office-type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
                    <option value="">-- Pilih --</option>
                    @foreach($stepData['officeTypes'] as $ot)
                    <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Kategori Lokasi *</label>
                <select id="form-location-category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
                    <option value="">-- Pilih --</option>
                    @foreach($stepData['locationCategories'] as $lc)
                    <option value="{{ $lc->id }}">{{ $lc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Layanan *</label>
                <select id="form-service" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
                    <option value="">-- Pilih --</option>
                    @foreach($stepData['services'] as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">IP Host</label>
                <input type="text" id="form-ip" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" placeholder="192.168.1.1">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Alamat</label>
                <textarea id="form-address" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D] resize-none" rows="2" placeholder="Alamat lengkap"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai</label>
                <input type="date" id="form-start-date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end gap-3 shrink-0">
            <button onclick="closeAddModal()" class="btn btn-secondary">Batal</button>
            <button onclick="saveNewSsid()" class="btn btn-primary">Simpan & Tambah</button>
        </div>
    </div>
</div>

{{-- Edit SSID Modal --}}
<div id="edit-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full max-h-[80vh] shadow-2xl flex flex-col">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between shrink-0">
            <h5 class="font-semibold text-gray-900">Edit SSID</h5>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-4 overflow-y-auto flex-1">
            <input type="hidden" id="edit-line-id">
            <div class="bg-blue-50 rounded-lg p-3 mb-4">
                <p class="text-sm font-medium text-blue-800">SSID: <span id="edit-ssid-display" class="font-mono">-</span></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kantor *</label>
                <select id="edit-office-type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
                    <option value="">-- Pilih --</option>
                    @foreach($stepData['officeTypes'] as $ot)
                    <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Kategori Lokasi *</label>
                <select id="edit-location-category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
                    <option value="">-- Pilih --</option>
                    @foreach($stepData['locationCategories'] as $lc)
                    <option value="{{ $lc->id }}">{{ $lc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Layanan *</label>
                <select id="edit-service" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" required>
                    <option value="">-- Pilih --</option>
                    @foreach($stepData['services'] as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">IP Host</label>
                <input type="text" id="edit-ip" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]" placeholder="192.168.1.1">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Alamat</label>
                <textarea id="edit-address" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D] resize-none" rows="2" placeholder="Alamat lengkap"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai</label>
                <input type="date" id="edit-start-date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end gap-3 shrink-0">
            <button onclick="closeEditModal()" class="btn btn-secondary">Batal</button>
            <button onclick="saveEditSsid()" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
const allSSID = @json($stepData['invoiceLines']);
const hasExistingData = {{ $invoiceLinesCount > 0 ? 'true' : 'false' }};
let PER_PAGE = 20;
let currentPage = 1;
let searchQuery = '';
let filterNewOnly = false;
let editingLineId = null;

// Count new SSIDs for badge
const newSsidCount = allSSID.filter(s => s.is_new_ssid).length;
if (document.getElementById('new-count-badge-small')) {
    document.getElementById('new-count-badge-small').textContent = newSsidCount;
}

function getFilteredData() {
    let data = allSSID;
    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        data = data.filter(item =>
            (item.ssid && item.ssid.toLowerCase().includes(q)) ||
            (item.office_type?.name && item.office_type.name.toLowerCase().includes(q)) ||
            (item.location_category?.name && item.location_category.name.toLowerCase().includes(q)) ||
            (item.service?.name && item.service.name.toLowerCase().includes(q))
        );
    }
    if (filterNewOnly) {
        data = data.filter(item => item.is_new_ssid === true);
    }
    return data;
}

function handleSearch() {
    searchQuery = document.getElementById('search-ssid').value.trim();
    currentPage = 1;
    renderTable();
}

function handleFilter() {
    filterNewOnly = document.getElementById('filter-new-ssid').checked;
    currentPage = 1;
    renderTable();
}

function showNewSsids() {
    // Check the filter for new SSIDs
    document.getElementById('filter-new-ssid').checked = true;
    filterNewOnly = true;
    currentPage = 1;
    renderTable();

    // Scroll to table
    const table = document.getElementById('ssid-table-container');
    if (table) {
        table.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function toggleTable() {
    const container = document.getElementById('ssid-table-container');
    if (container) container.classList.toggle('hidden');
}

function showImportSection() {
    const container = document.getElementById('import-section');
    if (container) {
        container.classList.remove('hidden');
        document.getElementById('ssid-input').value = '';
        document.getElementById('ssid-input').focus();
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function hideImportSection() {
    const container = document.getElementById('import-section');
    if (container) {
        container.classList.add('hidden');
        document.getElementById('ssid-input').value = '';
    }
}

function showPendingSsids() {
    const section = document.getElementById('pending-ssid-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function confirmReimport() {
    if (!confirm('Yakin ingin import ulang? Data akan dihapus.')) return;
    if (!confirm('Ini tidak bisa dibatalkan! Lanjutkan?')) return;

    fetch(`/invoice-periods/${periodId}/ssid-clear`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Gagal: ' + (data.message || 'Error'));
    })
    .catch(() => alert('Terjadi kesalahan'));
}

function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('ssid-input').value = e.target.result;
        checkSsid();
    };
    reader.readAsText(file);
}

function handleFileUploadAdditional(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('ssid-input-additional').value = e.target.result;
    };
    reader.readAsText(file);
}

function checkSsidAdditional() {
    const ssidInput = document.getElementById('ssid-input-additional').value.trim();
    if (!ssidInput) { alert('Masukkan SSID terlebih dahulu'); return; }

    const btn = document.getElementById('btn-check-additional');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="CurrentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Memproses...';

    fetch(`/invoice-periods/${periodId}/ssid-check`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ ssids: ssidInput })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg> Import SSID';
        if (data.success) {
            document.getElementById('ssid-input-additional').value = '';
            hideImportSection();
            alert(data.message);
            location.reload();
        } else {
            alert(data.message || 'Terjadi kesalahan');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg> Import SSID';
        alert('Gagal memproses SSID');
    });
}

function checkSsid() {
    const ssidInput = document.getElementById('ssid-input').value.trim();
    if (!ssidInput) { alert('Masukkan SSID terlebih dahulu'); return; }

    const btn = document.getElementById('btn-check');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="CurrentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Memproses...';

    fetch(`/invoice-periods/${periodId}/ssid-check`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ ssids: ssidInput })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg> Import SSID';
        if (data.success) {
            document.getElementById('ssid-input').value = '';
            alert(data.message);
            location.reload();
        } else {
            alert(data.message || 'Terjadi kesalahan');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg> Import SSID';
        alert('Gagal memproses SSID');
    });
}

function openAddModal(ssid, pendingLineId = null) {
    document.getElementById('new-ssid-value').value = ssid;
    document.getElementById('form-ssid').value = ssid;
    document.getElementById('pending-line-id').value = pendingLineId || '';
    document.getElementById('add-modal').classList.remove('hidden');
}

function openAddSsidModal() {
    document.getElementById('new-ssid-value').value = '';
    document.getElementById('form-ssid').value = '';
    document.getElementById('pending-line-id').value = '';
    document.getElementById('add-modal').classList.remove('hidden');
}

function closeAddModal() {
    document.getElementById('add-modal').classList.add('hidden');
    document.getElementById('form-office-type').value = '';
    document.getElementById('form-location-category').value = '';
    document.getElementById('form-service').value = '';
    document.getElementById('form-ip').value = '';
    document.getElementById('form-address').value = '';
    document.getElementById('form-start-date').value = '';
    document.getElementById('pending-line-id').value = '';
}

function saveNewSsid() {
    const ssid = document.getElementById('new-ssid-value').value || document.getElementById('form-ssid').value;
    const pendingLineId = document.getElementById('pending-line-id').value || null;
    const officeTypeId = document.getElementById('form-office-type').value;
    const locationCategoryId = document.getElementById('form-location-category').value;
    const serviceId = document.getElementById('form-service').value;
    const ipHost = document.getElementById('form-ip').value;
    const address = document.getElementById('form-address').value;
    const startDate = document.getElementById('form-start-date').value;

    if (!ssid || !officeTypeId || !locationCategoryId || !serviceId) {
        alert('Lengkapi form yang wajib diisi'); return;
    }

    const btn = document.querySelector('[onclick="saveNewSsid()"]');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="CurrentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

    fetch(`/invoice-periods/${periodId}/ssid-store`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            ssid, office_type_id: officeTypeId, location_category_id: locationCategoryId,
            service_id: serviceId, ip_host_telkom: ipHost, address, service_start_date: startDate,
            pending_line_id: pendingLineId,
        })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Simpan & Tambah';
        if (data.success) {
            alert(`SSID "${ssid}" berhasil ditambahkan!`);
            location.reload();
        } else {
            alert('Gagal: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = 'Simpan & Tambah';
        alert('Gagal menyimpan SSID');
    });
}

function deleteSsid(lineId, ssid) {
    if (!confirm(`Hapus SSID "${ssid}" dari periode ini? Data master tidak akan dihapus.`)) return;
    fetch(`/invoice-periods/${periodId}/ssid-remove-from-period`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ line_id: lineId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) { alert(`SSID "${ssid}" berhasil dihapus.`); location.reload(); }
        else alert('Gagal: ' + (data.message || 'Error'));
    })
    .catch(() => alert('Gagal menghapus SSID'));
}

function removePendingSsid(lineId, ssid) {
    if (!confirm(`Hapus SSID "${ssid}" dari daftar?`)) return;
    fetch(`/invoice-periods/${periodId}/ssid-remove-from-period`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ line_id: lineId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) { alert(`SSID "${ssid}" berhasil dihapus.`); location.reload(); }
        else alert('Gagal: ' + (data.message || 'Error'));
    })
    .catch(() => alert('Gagal menghapus SSID'));
}

function openEditModal(lineId, lineNumber) {
    editingLineId = lineId;
    const item = allSSID.find(s => s.id === lineId);
    if (!item) { alert('Data tidak ditemukan'); return; }

    const isPendingNew = item.is_new_ssid && (!item.office_type?.name || item.office_type?.name === '-');
    if (isPendingNew) { openAddModal(item.ssid, lineId); return; }

    document.getElementById('edit-ssid-display').textContent = item.ssid || '-';
    document.getElementById('edit-line-id').value = lineId;
    document.getElementById('edit-office-type').value = item.office_type?.id || '';
    document.getElementById('edit-location-category').value = item.location_category?.id || '';
    document.getElementById('edit-service').value = item.service?.id || '';
    document.getElementById('edit-ip').value = item.ip_host_telkom || '';
    document.getElementById('edit-address').value = item.address || '';

    let startDate = item.service_start_date || '';
    if (startDate && startDate.includes('T')) startDate = startDate.split('T')[0];
    document.getElementById('edit-start-date').value = startDate;
    document.getElementById('edit-modal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.add('hidden');
    editingLineId = null;
}

function saveEditSsid() {
    if (!editingLineId) { alert('ID tidak valid'); return; }
    const officeTypeId = document.getElementById('edit-office-type').value;
    const locationCategoryId = document.getElementById('edit-location-category').value;
    const serviceId = document.getElementById('edit-service').value;
    if (!officeTypeId || !locationCategoryId || !serviceId) { alert('Lengkapi form'); return; }

    const btn = document.querySelector('[onclick="saveEditSsid()"]');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="CurrentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

    fetch(`/invoice-periods/${periodId}/ssid-update/${editingLineId}`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            office_type_id: officeTypeId, location_category_id: locationCategoryId,
            service_id: serviceId,
            ip_host_telkom: document.getElementById('edit-ip').value,
            address: document.getElementById('edit-address').value,
            service_start_date: document.getElementById('edit-start-date').value,
        })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Simpan Perubahan';
        if (data.success) { alert('SSID berhasil diupdate!'); closeEditModal(); location.reload(); }
        else alert('Gagal: ' + data.message);
    })
    .catch(err => { btn.disabled = false; btn.innerHTML = 'Simpan Perubahan'; alert('Gagal mengupdate SSID'); });
}

// Complete and redirect immediately without countdown
function completeAndContinue() {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Menyimpan...';

    fetch(`/invoice-periods/${periodId}/step-status`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ step_code: currentStepCode, status: 'completed' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Redirect immediately to step 2
            window.location.href = `/invoice-periods/${periodId}/step/2`;
        } else {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg> Selesai & Lanjut';
            alert('Gagal menyimpan status step');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg> Selesai & Lanjut';
        alert('Terjadi kesalahan');
    });
}

function completeStep() {
    // Disable button to prevent double click
    const btn = event.target;
    btn.disabled = true;

    fetch(`/invoice-periods/${periodId}/step-status`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ step_code: currentStepCode, status: 'completed' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            localStorage.setItem('ssid_step_completed_' + periodId, 'true');
            const now = new Date();
            const formatted = now.toLocaleDateString('id-ID', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'});
            localStorage.setItem('ssid_step_date_' + periodId, formatted);

            const countEl = document.getElementById('complete-ssid-count');
            if (countEl) countEl.textContent = allSSID.length;
            const dateEl = document.getElementById('last-complete-date');
            if (dateEl) dateEl.textContent = formatted;

            // Show complete section
            const completeSection = document.getElementById('complete-section');
            completeSection.classList.remove('hidden');

            // Show countdown and redirect info
            const countdownContainer = document.getElementById('countdown-container');
            const countdownNumEl = document.getElementById('countdown-number');
            if (countdownContainer) countdownContainer.classList.remove('hidden');

            completeSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Start countdown
            let countdown = 10;
            if (countdownNumEl) countdownNumEl.textContent = countdown;

            const countdownInterval = setInterval(() => {
                countdown--;
                if (countdownNumEl) countdownNumEl.textContent = countdown;
                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    // Redirect to next step
                    window.location.href = `/invoice-periods/${periodId}/step/2`;
                }
            }, 1000);

            // Store interval ID for cancel button
            completeSection.dataset.countdownInterval = countdownInterval;
        } else {
            btn.disabled = false;
            alert('Gagal menyimpan status step');
        }
    })
    .catch(() => {
        btn.disabled = false;
        alert('Terjadi kesalahan');
    });
}

function cancelRedirect() {
    const completeSection = document.getElementById('complete-section');
    const countdownContainer = document.getElementById('countdown-container');

    // Clear the countdown interval
    if (completeSection && completeSection.dataset.countdownInterval) {
        clearInterval(parseInt(completeSection.dataset.countdownInterval));
    }

    // Hide countdown container
    if (countdownContainer) countdownContainer.classList.add('hidden');
}

function renderTable() {
    const tbody = document.getElementById('ssid-table-body');
    const filteredData = getFilteredData();
    if (!tbody || !filteredData.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">Tidak ada data</td></tr>';
        document.getElementById('showed-count').textContent = 0;
        document.getElementById('total-count').textContent = filteredData.length;
        document.getElementById('pagination-buttons').innerHTML = '';
        return;
    }

    const start = (currentPage - 1) * PER_PAGE;
    const end = start + PER_PAGE;
    const pageData = filteredData.slice(start, end);
    const totalPages = Math.ceil(filteredData.length / PER_PAGE);

    tbody.innerHTML = pageData.map((item, idx) => {
        const isNew = item.is_new_ssid;
        const isPendingNew = isNew && (!item.office_type?.name || item.office_type?.name === '-');

        let actionBtn = '';
        if (isPendingNew) {
            actionBtn = `<button onclick="openAddModal('${item.ssid}', ${item.id})" class="text-amber-600 hover:text-amber-800" title="Lengkapi"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                        <button onclick="removePendingSsid(${item.id}, '${item.ssid}')" class="text-red-600 hover:text-red-800 ml-2" title="Hapus"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>`;
        } else {
            actionBtn = `<button onclick="openEditModal(${item.id}, ${item.line_number || (start + idx + 1)})" class="text-blue-600 hover:text-blue-800" title="Edit"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                        <button onclick="deleteSsid(${item.id}, '${item.ssid}')" class="text-red-600 hover:text-red-800 ml-2" title="Hapus"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>`;
        }

        return `<tr class="hover:bg-gray-50 ${isNew ? 'bg-amber-50' : ''}">
            <td class="px-4 py-2 text-sm font-medium text-gray-900">${item.line_number || (start + idx + 1)}</td>
            <td class="px-4 py-2 text-sm text-gray-700">${item.office_type?.name || '-'}${isNew ? '<span class="ml-1 text-xs bg-amber-200 text-amber-700 px-1 rounded">BARU</span>' : ''}</td>
            <td class="px-4 py-2 text-sm text-gray-700">${item.location_category?.name || '-'}</td>
            <td class="px-4 py-2 text-sm text-gray-700">${item.service?.name || '-'}</td>
            <td class="px-4 py-2 text-sm font-mono text-blue-600">${item.ssid || '-'}</td>
            <td class="px-4 py-2 text-sm text-gray-600 max-w-xs truncate" title="${item.address || '-'}">${item.address || '-'}</td>
            <td class="px-4 py-2 text-sm font-mono text-gray-600">${item.ip_host_telkom || '-'}</td>
            <td class="px-4 py-2 text-sm text-gray-600">${item.service_start_date ? formatDate(item.service_start_date) : '-'}</td>
            <td class="px-4 py-2 text-sm">${actionBtn}</td>
        </tr>`;
    }).join('');

    document.getElementById('showed-count').textContent = Math.min(end, filteredData.length);
    document.getElementById('total-count').textContent = filteredData.length;
    renderPagination(totalPages);
}

function renderPagination(totalPages) {
    const container = document.getElementById('pagination-buttons');
    if (!container) return;
    let html = '';
    html += `<button onclick="prevPage()" ${currentPage === 1 ? 'disabled' : ''} class="w-8 h-8 rounded border border-gray-300 text-sm ${currentPage === 1 ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white hover:bg-gray-50'}">‹</button>`;
    if (totalPages <= 7) { for (let i = 1; i <= totalPages; i++) html += renderPageButton(i); }
    else {
        html += renderPageButton(1);
        if (currentPage > 3) html += '<span class="px-1 text-gray-400">...</span>';
        for (let i = Math.max(2, currentPage - 1); i <= Math.min(totalPages - 1, currentPage + 1); i++) html += renderPageButton(i);
        if (currentPage < totalPages - 2) html += '<span class="px-1 text-gray-400">...</span>';
        html += renderPageButton(totalPages);
    }
    html += `<button onclick="nextPage()" ${currentPage >= totalPages ? 'disabled' : ''} class="w-8 h-8 rounded border border-gray-300 text-sm ${currentPage >= totalPages ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white hover:bg-gray-50'}">›</button>`;
    container.innerHTML = html;
}

function renderPageButton(page) {
    const isActive = page === currentPage;
    return `<button onclick="goToPage(${page})" class="w-8 h-8 rounded border text-sm ${isActive ? 'bg-[#E6002D] text-white border-[#E6002D]' : 'bg-white border-gray-300 hover:bg-gray-50'}">${page}</button>`;
}

function goToPage(page) {
    const totalPages = Math.ceil(getFilteredData().length / PER_PAGE);
    if (page >= 1 && page <= totalPages) { currentPage = page; renderTable(); }
}

function changePerPage() { PER_PAGE = parseInt(document.getElementById('per-page-select').value); currentPage = 1; renderTable(); }
function prevPage() { if (currentPage > 1) { currentPage--; renderTable(); } }
function nextPage() { const totalPages = Math.ceil(getFilteredData().length / PER_PAGE); if (currentPage < totalPages) { currentPage++; renderTable(); } }

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

function downloadFormat() { window.location.href = `/invoice-periods/${periodId}/ssid-format`; }

document.addEventListener('DOMContentLoaded', function() {
    if (allSSID.length > 0) {
        renderTable();
        const isStepCompleted = localStorage.getItem('ssid_step_completed_' + periodId);
        const savedDate = localStorage.getItem('ssid_step_date_' + periodId);
        if (isStepCompleted === 'true') {
            const completeSection = document.getElementById('complete-section');
            if (completeSection) completeSection.classList.remove('hidden');
            const countEl = document.getElementById('complete-ssid-count');
            if (countEl) countEl.textContent = allSSID.length;
            if (savedDate) {
                const dateEl = document.getElementById('last-complete-date');
                if (dateEl) dateEl.textContent = savedDate;
            }
        }
    }
});
</script>
@endpush
