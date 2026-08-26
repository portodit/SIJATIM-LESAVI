@extends('layouts.sijatim')

@section('content')
<div class="mb-6">
    <a href="{{ route('invoices.index') }}" class="inline-flex items-center gap-2 text-gray-500 hover:text-gray-700 mb-4">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Kembali ke riwayat invoice
    </a>

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Detail Invoice</h1>
            <p class="text-gray-500 mt-1">
                <span class="font-mono">{{ $invoice->invoice_number }}</span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($invoice->file_path)
                <a href="{{ route('invoices.download', $invoice) }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Download Excel
                </a>
            @endif
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Info -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Invoice</h3>

            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Invoice Number</label>
                    <p class="font-mono font-medium text-gray-900">{{ $invoice->invoice_number }}</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Total Amount</label>
                    <p class="text-xl font-bold text-green-600">{{ $invoice->formatted_amount }}</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Snapshot</label>
                    <a href="{{ route('snapshots.show', $invoice->snapshot) }}" class="text-red-600 hover:text-red-700 font-medium">
                        {{ $invoice->snapshot->name ?? '-' }}
                    </a>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Periode</label>
                    <p class="text-gray-900">{{ $invoice->snapshot->period ?? '-' }}</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Total Lokasi</label>
                    <p class="text-gray-900">{{ $invoice->total_locations }} lokasi</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Tanggal Generate</label>
                    <p class="text-gray-900">
                        {{ $invoice->generated_at ? $invoice->generated_at->locale('id')->format('d F Y, H:i') : '-' }}
                    </p>
                </div>
            </div>

            @if($invoice->notes)
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <label class="block text-sm text-gray-500 mb-1">Catatan</label>
                    <p class="text-gray-700">{{ $invoice->notes }}</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Tambahan</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Di-generate oleh</label>
                    <p class="text-gray-900">{{ $invoice->generator->name ?? '-' }}</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Tanggal Dibuat</label>
                    <p class="text-gray-900">{{ $invoice->created_at->locale('id')->format('d M Y') }}</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Terakhir Diupdate</label>
                    <p class="text-gray-900">{{ $invoice->updated_at->locale('id')->format('d M Y') }}</p>
                </div>
            </div>
        </div>

        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4">
            <div class="flex gap-3">
                <svg class="w-5 h-5 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <h4 class="text-sm font-medium text-blue-900">Tips</h4>
                    <p class="text-sm text-blue-700 mt-1">
                        File invoice dapat di-download dalam format Excel (.xlsx) untuk proses billing lebih lanjut.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
