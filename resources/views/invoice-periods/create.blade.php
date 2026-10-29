@extends('layouts.sijatim')

@section('content')
<script>
    window.sijCreatePeriod = (month, year, existing) => ({
        month, year, existing,
        names: ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        codes: ['', 'JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGU', 'SEP', 'OKT', 'NOV', 'DES'],
        get code() { return this.codes[this.month] + String(this.year).slice(-2); },
        get usage() {
            const m = this.month === 1 ? 12 : this.month - 1;
            const y = this.month === 1 ? this.year - 1 : this.year;
            return this.names[m] + ' ' + y;
        },
        get exists() { return this.existing.includes(this.code); },
    });
</script>

<div class="max-w-xl mx-auto" x-data="sijCreatePeriod({{ (int) $defaultMonth }}, {{ (int) $defaultYear }}, @js($existing))">
    <a href="{{ route('invoice-periods.index') }}" class="text-gray-500 hover:text-gray-700 text-sm">&larr; Kembali</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-4">Buat periode tagihan</h1>
    <p class="text-gray-500 mt-1">Pilih bulan invoice. Pemakaian otomatis bulan sebelumnya.</p>

    <form action="{{ route('invoice-periods.store') }}" method="POST" class="card rounded-xl p-8 mt-6 space-y-6">
        @csrf
        <div class="grid grid-cols-2 gap-4">
            <label class="block text-sm font-medium">Bulan invoice
                <select name="period_month" class="input mt-1" x-model.number="month">
                    @foreach (\App\Models\InvoicePeriod::MONTH_NAMES as $number => $name)
                        <option value="{{ $number }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium">Tahun
                <input type="number" name="period_year" class="input mt-1" min="2020" max="2100" x-model.number="year" required>
            </label>
        </div>

        <div class="rounded-lg bg-gray-50 border border-gray-200 p-4">
            <p class="text-lg font-bold text-[#E6002D]" x-text="code"></p>
            <p class="text-sm text-gray-700 mt-1">Invoice <strong x-text="names[month] + ' ' + year"></strong> menagih pemakaian <strong x-text="usage"></strong>.</p>
            <p class="text-sm text-red-600 mt-2" x-show="exists" x-cloak>Periode ini sudah ada.</p>
        </div>

        <label class="block text-sm font-medium">Catatan <span class="text-gray-400 font-normal">(opsional)</span>
            <textarea name="notes" rows="2" class="input mt-1">{{ old('notes') }}</textarea>
        </label>

        <div class="flex gap-3 pt-2">
            <a href="{{ route('invoice-periods.index') }}" class="btn btn-secondary flex-1">Batal</a>
            <button type="submit" class="btn btn-primary flex-1" :disabled="exists">Buat periode</button>
        </div>
        <p class="text-xs text-gray-500">Perubahan layanan yang sudah dijadwalkan untuk bulan pemakaian ini akan otomatis dimasukkan.</p>
    </form>
</div>
@endsection
