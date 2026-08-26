@extends('layouts.sijatim')

@section('content')
<!-- Header -->
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Selamat datang, {{ Auth::user()->name }}</h1>
        <p class="text-gray-500 mt-1">Berikut ringkasan aktivitas invoice Bank Jatim</p>
    </div>
    <a href="{{ route('invoice-periods.create') }}" class="btn btn-primary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Periode Baru
    </a>
</div>

<!-- Stats -->
<div class="grid grid-cols-4 gap-6 mb-8">
    <div class="card p-5">
        <p class="text-sm text-gray-500">Total Periode</p>
        <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totalPeriods }}</p>
    </div>
    <div class="card p-5">
        <p class="text-sm text-gray-500">Total Invoice</p>
        <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totalInvoices }}</p>
    </div>
    <div class="card p-5">
        <p class="text-sm text-gray-500">Periode Bulan Ini</p>
        <p class="text-3xl font-bold text-gray-900 mt-1">{{ $periodsThisMonth }}</p>
    </div>
    <div class="card p-5">
        <p class="text-sm text-gray-500">Nilai Bulan Ini</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">
            {{ $totalValueThisMonth > 0 ? 'Rp ' . number_format($totalValueThisMonth, 0, ',', '.') : '-' }}
        </p>
    </div>
</div>

<!-- Content -->
<div class="grid grid-cols-3 gap-6">
    <!-- Recent Periods -->
    <div class="col-span-2 card">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Periode Tagihan Terbaru</h3>
            <a href="{{ route('invoice-periods.index') }}" class="text-sm text-[#E6002D] hover:underline">Lihat semua</a>
        </div>

        @if($recentPeriods->isEmpty())
            <div class="p-12 text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="text-gray-500 mb-2">Belum ada periode tagihan</p>
                <a href="{{ route('invoice-periods.create') }}" class="btn btn-primary mt-4">Buat Periode Baru</a>
            </div>
        @else
            <div class="divide-y divide-gray-100">
                @foreach($recentPeriods as $period)
                    <a href="{{ route('invoice-periods.show', $period) }}" class="flex items-center justify-between px-5 py-4 hover:bg-gray-50 transition-colors group">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg bg-[#E6002D]/10 flex items-center justify-center flex-shrink-0">
                                <span class="text-sm font-bold text-[#E6002D]">{{ $period->month_code }}</span>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 group-hover:text-[#E6002D] transition-colors">{{ $period->period_name }}</p>
                                <p class="text-xs text-gray-400">{{ $period->start_date->format('d M') }} - {{ $period->end_date->format('d M Y') }} · {{ $period->creator?->name ?? 'System' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            @php
                                $statusMap = [
                                    'draft' => ['bg-gray-100 text-gray-600', 'Draft'],
                                    'in_progress' => ['bg-yellow-100 text-yellow-700', 'Dikerjakan'],
                                    'validation' => ['bg-blue-100 text-blue-700', 'Validasi'],
                                    'finalized' => ['bg-green-100 text-green-700', 'Selesai'],
                                    'revision' => ['bg-orange-100 text-orange-700', 'Revisi'],
                                    'archived' => ['bg-gray-100 text-gray-500', 'Diarsipkan'],
                                ];
                                $statusStyle = $statusMap[$period->status] ?? ['bg-gray-100 text-gray-600', 'Unknown'];
                            @endphp
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium {{ $statusStyle[0] }}">
                                {{ $statusStyle[1] }}
                            </span>
                            <svg class="w-5 h-5 text-gray-300 group-hover:text-[#E6002D] transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Quick Links -->
    <div class="card">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Aksi Cepat</h3>
        </div>
        <div class="p-5 space-y-3">
            <a href="{{ route('invoice-periods.create') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 rounded bg-[#E6002D]/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-[#E6002D]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium text-gray-900">Periode Baru</p>
                    <p class="text-xs text-gray-500">Mulai periode billing</p>
                </div>
            </a>
            <a href="{{ route('invoices.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 rounded bg-gray-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium text-gray-900">Invoice</p>
                    <p class="text-xs text-gray-500">Lihat & download</p>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- System Info -->
<div class="mt-6 card p-5">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">Laravel 13.x</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">PHP {{ PHP_VERSION }}</span>
            </div>
        </div>
    </div>
</div>
@endsection
