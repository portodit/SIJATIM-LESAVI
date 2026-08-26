@extends('layouts.sijatim')

@section('content')
<div class="max-w-xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <a href="{{ route('invoice-periods.show', $period) }}" class="text-gray-500 hover:text-gray-700 flex items-center gap-2 text-sm mb-4">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Kembali ke Detail
        </a>
        <h1 class="text-2xl font-bold text-gray-900">Edit Periode Invoice</h1>
        <p class="text-gray-500 mt-1">{{ $period->period_code }} - {{ $period->period_name }}</p>
    </div>

    <!-- Card -->
    <div class="bg-white border border-gray-200 rounded-xl p-8">
        <form action="{{ route('invoice-periods.update', $period) }}" method="POST">
            @csrf
            @method('PUT')

            <!-- Period Info (Read-only) -->
            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-gray-500">Kode Periode</p>
                        <p class="font-bold text-lg">{{ $period->period_code }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Periode</p>
                        <p class="font-bold text-lg">{{ $period->period_name }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Tanggal Mulai</p>
                        <p>{{ $period->start_date->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Tanggal Akhir</p>
                        <p>{{ $period->end_date->format('d/m/Y') }}</p>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="input">
                    <option value="draft" {{ $period->status === 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="in_progress" {{ $period->status === 'in_progress' ? 'selected' : '' }}>Sedang Dikerjakan</option>
                    <option value="validation" {{ $period->status === 'validation' ? 'selected' : '' }}>Validasi</option>
                    <option value="finalized" {{ $period->status === 'finalized' ? 'selected' : '' }}>Final</option>
                    <option value="revision" {{ $period->status === 'revision' ? 'selected' : '' }}>Revisi</option>
                    <option value="archived" {{ $period->status === 'archived' ? 'selected' : '' }}>Diarsipkan</option>
                </select>
                @error('status')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Notes -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Catatan <span class="text-gray-400 font-normal">(opsional)</span></label>
                <textarea name="notes" rows="4" class="input" placeholder="Tambahkan catatan jika diperlukan">{{ $period->notes }}</textarea>
                @error('notes')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-4 border-t border-gray-100">
                <a href="{{ route('invoice-periods.show', $period) }}" class="btn btn-secondary flex-1 justify-center">Batal</a>
                <button type="submit" class="btn btn-primary flex-1 justify-center">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
