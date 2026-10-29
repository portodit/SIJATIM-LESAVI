@extends('layouts.sijatim')

@section('content')
<div class="max-w-xl mx-auto">
    <a href="{{ route('invoice-periods.index') }}" class="text-gray-500 hover:text-gray-700 text-sm">&larr; Kembali</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-4">Catatan periode {{ $period->period_code }}</h1>
    <p class="text-gray-500 mt-1">Tagihan {{ $period->period_name }} · pemakaian {{ $period->usage_name }}</p>

    <form action="{{ route('invoice-periods.update', $period) }}" method="POST" class="card rounded-xl p-8 mt-6 space-y-6">
        @csrf
        @method('PUT')
        <label class="block text-sm font-medium">Catatan
            <textarea name="notes" rows="4" class="input mt-1" placeholder="Catatan internal untuk periode ini">{{ old('notes', $period->notes) }}</textarea>
        </label>
        <div class="flex gap-3">
            <a href="{{ route('invoice-periods.index') }}" class="btn btn-secondary flex-1">Batal</a>
            <button type="submit" class="btn btn-primary flex-1">Simpan</button>
        </div>
    </form>
</div>
@endsection
