@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-6">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ route('invoice-periods.show', $period) }}" class="hover:text-gray-700">{{ $period->period_code }}</a>
            <span>/</span>
            <a href="{{ route('invoice-periods.step1', $period) }}" class="hover:text-gray-700">SSID List</a>
            <span>/</span>
            <span class="text-gray-700">Tambah SSID Baru</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Tambah SSID Baru</h1>
        <p class="text-gray-500 mt-1">SSID yang belum ada di database master</p>
    </div>

    {{-- SSID List Info --}}
    @if(count($ssidList) > 0)
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-amber-800 font-medium">{{ count($ssidList) }} SSID dipilih untuk ditambahkan</span>
        </div>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach($ssidList as $ssid)
            <span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-sm font-mono">{{ $ssid }}</span>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Form --}}
    <form id="ssid-form" class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="p-6 space-y-6">
            {{-- SSID Input --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">SSID <span class="text-red-500">*</span></label>
                <input type="text" id="form-ssid" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]"
                    placeholder="Contoh: BANKJTM-KPU-001">
                <input type="hidden" id="ssid-queue" value="{{ json_encode($ssidList) }}">
            </div>

            {{-- Office Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kantor <span class="text-red-500">*</span></label>
                <select id="form-office-type" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
                    <option value="">-- Pilih --</option>
                    @foreach($officeTypes as $ot)
                    <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Location Category --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Kategori Lokasi <span class="text-red-500">*</span></label>
                <select id="form-location-category" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
                    <option value="">-- Pilih --</option>
                    @foreach($locationCategories as $lc)
                    <option value="{{ $lc->id }}">{{ $lc->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Service --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Layanan <span class="text-red-500">*</span></label>
                <select id="form-service" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
                    <option value="">-- Pilih --</option>
                    @foreach($services as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- IP Host --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">IP Host</label>
                <input type="text" id="form-ip"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]"
                    placeholder="192.168.1.1">
            </div>

            {{-- Address --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Alamat</label>
                <textarea id="form-address" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D] resize-none"
                    placeholder="Alamat lengkap"></textarea>
            </div>

            {{-- Start Date --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai</label>
                <input type="date" id="form-start-date"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#E6002D] focus:border-[#E6002D]">
            </div>
        </div>

        {{-- Actions --}}
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-between">
            <a href="{{ route('invoice-periods.step1', $period) }}" class="btn btn-secondary">
                Kembali
            </a>
            <div class="flex gap-3">
                <button type="button" onclick="saveAndContinue()" class="btn bg-blue-600 text-white hover:bg-blue-700">
                    Simpan & Lanjut
                </button>
                <button type="button" onclick="saveAndClose()" class="btn bg-green-600 text-white hover:bg-green-700">
                    Simpan & Tutup
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
const ssidQueue = JSON.parse(document.getElementById('ssid-queue').value || '[]');
let currentIndex = 0;
const periodId = {{ $period->id }};
const returnUrl = `/invoice-periods/${periodId}/step/1`;

function getNextSsid() {
    if (currentIndex < ssidQueue.length) {
        return ssidQueue[currentIndex];
    }
    return null;
}

function loadNextSsid() {
    const nextSsid = getNextSsid();
    if (nextSsid) {
        document.getElementById('form-ssid').value = nextSsid;
    }
    return nextSsid !== null;
}

function saveAndClose() {
    saveSsid(true);
}

function saveAndContinue() {
    saveSsid(false);
}

function saveSsid(closeAfterSave) {
    const ssid = document.getElementById('form-ssid').value.trim();
    const officeTypeId = document.getElementById('form-office-type').value;
    const locationCategoryId = document.getElementById('form-location-category').value;
    const serviceId = document.getElementById('form-service').value;
    const ipHost = document.getElementById('form-ip').value;
    const address = document.getElementById('form-address').value;
    const startDate = document.getElementById('form-start-date').value;

    if (!ssid || !officeTypeId || !locationCategoryId || !serviceId) {
        alert('Lengkapi form yang wajib diisi');
        return;
    }

    const btn = closeAfterSave
        ? document.querySelector('[onclick="saveAndClose()"]')
        : document.querySelector('[onclick="saveAndContinue()"]');
    btn.disabled = true;
    btn.innerHTML = `<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

    fetch(`/invoice-periods/${periodId}/ssid-store`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            ssid: ssid,
            office_type_id: officeTypeId,
            location_category_id: locationCategoryId,
            service_id: serviceId,
            ip_host_telkom: ipHost,
            address: address,
            service_start_date: startDate,
        })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = closeAfterSave ? 'Simpan & Tutup' : 'Simpan & Lanjut';

        if (data.success) {
            currentIndex++;

            if (closeAfterSave || currentIndex >= ssidQueue.length) {
                // Done - return to SSID list
                alert(`SSID "${ssid}" berhasil ditambahkan!`);
                window.location.href = returnUrl;
            } else {
                // Load next SSID
                alert(`SSID "${ssid}" berhasil ditambahkan!`);
                loadNextSsid();

                // Clear optional fields
                document.getElementById('form-ip').value = '';
                document.getElementById('form-address').value = '';
                document.getElementById('form-start-date').value = '';

                // Keep the dropdowns as they might be the same
            }
        } else {
            alert('Gagal: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = closeAfterSave ? 'Simpan & Tutup' : 'Simpan & Lanjut';
        alert('Terjadi kesalahan');
    });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    if (ssidQueue.length > 0) {
        loadNextSsid();
    }
});
</script>
@endpush
