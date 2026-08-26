@extends('layouts.sijatim')

@push('styles')
<style>
    .monthpicker-trigger {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        background: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 14px;
        transition: all 0.15s;
    }
    .monthpicker-trigger:hover { border-color: #9ca3af; }
    .monthpicker-trigger:focus {
        outline: none;
        border-color: #E6002D;
        box-shadow: 0 0 0 2px rgba(230, 0, 45, 0.1);
    }

    .monthpicker-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        margin-top: 4px;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        z-index: 50;
        display: none;
    }
    .monthpicker-dropdown.show { display: block; }

    .monthpicker-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }
    .monthpicker-header button {
        width: 32px;
        height: 32px;
        border: none;
        background: white;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .monthpicker-header button:hover { background: #f3f4f6; }
    .monthpicker-header span { font-weight: 600; color: #111827; }

    .monthpicker-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        padding: 16px;
    }
    .monthpicker-cell {
        padding: 10px 8px;
        text-align: center;
        border: none;
        background: white;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
        color: #374151;
    }
    .monthpicker-cell:hover { background: #fef2f2; color: #E6002D; }
    .monthpicker-cell.selected { background: #E6002D; color: white; }

    .monthpicker-year-nav {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        padding: 12px 16px;
        border-top: 1px solid #e5e7eb;
    }
    .monthpicker-year-nav input {
        width: 80px;
        padding: 6px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        text-align: center;
        font-size: 14px;
    }
    .monthpicker-year-nav input:focus { outline: none; border-color: #E6002D; }
</style>
@endpush

@section('content')
<div class="max-w-xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <a href="{{ route('invoice-periods.index') }}" class="text-gray-500 hover:text-gray-700 flex items-center gap-2 text-sm mb-4">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Kembali
        </a>
        <h1 class="text-2xl font-bold text-gray-900">Buat Periode Invoice Baru</h1>
        <p class="text-gray-500 mt-1">Pilih periode billing untuk folder kerja tagihan Bank Jatim</p>
    </div>

    <!-- Card -->
    <div class="bg-white border border-gray-200 rounded-xl p-8">
        <form id="period-form" action="{{ route('invoice-periods.store') }}" method="POST">
            @csrf

            <!-- Preview Nama -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Kode Periode</label>
                <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
                    <span id="preview_code" class="text-lg font-bold text-[#E6002D]">Pilih periode...</span>
                </div>
            </div>

            <!-- Month Picker -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Periode Billing</label>
                <div class="relative" id="monthpicker-wrapper">
                    <button type="button" class="monthpicker-trigger" id="monthpicker-trigger">
                        <span id="selected_period">Pilih periode...</span>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </button>

                    <div class="monthpicker-dropdown" id="monthpicker-dropdown">
                        <div class="monthpicker-header">
                            <button type="button" id="prev-year">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <span id="picker-year">{{ date('Y') }}</span>
                            <button type="button" id="next-year">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        <div class="monthpicker-grid" id="month-grid">
                            @foreach(['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'] as $i => $m)
                                <button type="button" class="monthpicker-cell" data-month="{{ $i + 1 }}">{{ $m }}</button>
                            @endforeach
                        </div>

                        <div class="monthpicker-year-nav">
                            <span class="text-sm text-gray-500">Tahun:</span>
                            <input type="number" id="year-input" name="year-input" value="{{ date('Y') }}" min="2020" max="2100">
                        </div>
                    </div>
                </div>

                <!-- Hidden inputs for form -->
                <input type="hidden" id="period_month" name="period_month" value="{{ old('period_month') }}">
                <input type="hidden" id="period_year" name="period_year" value="{{ old('period_year') }}">

                @error('period_month')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
                @error('period_year')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Catatan -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Catatan <span class="text-gray-400 font-normal">(opsional)</span></label>
                <textarea name="notes" rows="3" class="input" placeholder="Tambahkan catatan jika diperlukan">{{ old('notes') }}</textarea>
                @error('notes')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-4 border-t border-gray-100">
                <a href="{{ route('invoice-periods.index') }}" class="btn btn-secondary flex-1 justify-center">Batal</a>
                <button type="submit" id="btn_submit" class="btn btn-primary flex-1 justify-center" disabled>
                    Buat Periode
                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>

    <!-- Info -->
    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <p class="text-sm text-blue-800">
            <strong>Tips:</strong> Folder kerja digunakan untuk mengelompokkan data invoice per periode billing. Anda dapat mengimport data Excel dan melakukan validasi setelah periode dibuat.
        </p>
    </div>
</div>

<script>
    const bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const bulanShort = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const bulanCode = ['JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGU', 'SEP', 'OKT', 'NOV', 'DES'];

    const trigger = document.getElementById('monthpicker-trigger');
    const dropdown = document.getElementById('monthpicker-dropdown');
    const selectedPeriod = document.getElementById('selected_period');
    const pickerYear = document.getElementById('picker-year');
    const yearInput = document.getElementById('year-input');
    const monthGrid = document.getElementById('month-grid');
    const prevYear = document.getElementById('prev-year');
    const nextYear = document.getElementById('next-year');
    const previewCode = document.getElementById('preview_code');
    const btnSubmit = document.getElementById('btn_submit');
    const periodMonth = document.getElementById('period_month');
    const periodYear = document.getElementById('period_year');

    let currentYear = new Date().getFullYear();
    let selectedMonth = null;

    pickerYear.textContent = currentYear;
    yearInput.value = currentYear;

    trigger.addEventListener('click', () => {
        dropdown.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
        if (!document.getElementById('monthpicker-wrapper').contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });

    prevYear.addEventListener('click', () => {
        currentYear--;
        pickerYear.textContent = currentYear;
        yearInput.value = currentYear;
    });

    nextYear.addEventListener('click', () => {
        currentYear++;
        pickerYear.textContent = currentYear;
        yearInput.value = currentYear;
    });

    yearInput.addEventListener('change', () => {
        currentYear = parseInt(yearInput.value);
        pickerYear.textContent = currentYear;
    });

    monthGrid.addEventListener('click', (e) => {
        if (e.target.classList.contains('monthpicker-cell')) {
            document.querySelectorAll('.monthpicker-cell').forEach(c => c.classList.remove('selected'));
            e.target.classList.add('selected');
            selectedMonth = parseInt(e.target.dataset.month);

            selectedPeriod.textContent = `${bulanShort[selectedMonth]} ${currentYear}`;
            previewCode.textContent = `${bulanCode[selectedMonth - 1]}${String(currentYear).slice(-2)}`;
            btnSubmit.disabled = false;

            // Set hidden inputs
            periodMonth.value = selectedMonth;
            periodYear.value = currentYear;

            dropdown.classList.remove('show');
        }
    });

    // Form validation
    document.getElementById('period-form').addEventListener('submit', function(e) {
        if (!periodMonth.value || !periodYear.value) {
            e.preventDefault();
            alert('Silakan pilih periode billing terlebih dahulu.');
        }
    });
</script>
@endsection
