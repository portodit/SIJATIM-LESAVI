@extends('layouts.sijatim')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">PERIODE INVOICE</h1>
        <p class="text-gray-500 mt-1">Kelola data periode invoice Bank jatim</p>
    </div>
    <a href="{{ route('snapshots.create') }}" class="btn btn-primary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Periode Baru
    </a>
</div>

@if($periods->isEmpty())
    <div class="card p-16 text-center">
        <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Belum Ada Periode Invoice</h3>
        <p class="text-gray-500 mb-6">Buat periode baru untuk memulai proses invoice.</p>
        <a href="{{ route('snapshots.create') }}" class="btn btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Buat Periode Baru
        </a>
    </div>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($periods as $period)
        @php
            $statusColors = [
                'draft' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-700', 'border' => 'border-gray-200'],
                'in_progress' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'border' => 'border-yellow-200'],
                'validation' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200'],
                'finalized' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'border' => 'border-green-200'],
                'revision' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'border' => 'border-orange-200'],
                'archived' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'border' => 'border-gray-300'],
            ];
            $color = $statusColors[$period->status] ?? $statusColors['draft'];
            $statusLabels = [
                'draft' => 'Draft',
                'in_progress' => 'Sedang Dikerjakan',
                'validation' => 'Validasi',
                'finalized' => 'Final',
                'revision' => 'Revisi',
                'archived' => 'Diarsipkan',
            ];
            $statusLabel = $statusLabels[$period->status] ?? ucfirst($period->status);
            $progress = $period->progress_percentage ?? 0;
        @endphp
        <a href="{{ route('invoice-periods.show', $period) }}" class="card p-5 hover:shadow-lg hover:border-[#E6002D] transition-all border-l-4 {{ $color['border'] }}">
            <!-- Header -->
            <div class="flex items-start justify-between mb-4">
                <div>
                    <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $period->period_code }}</span>
                    <h3 class="font-bold text-gray-900 mt-1">{{ $period->period_name }}</h3>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $color['bg'] }} {{ $color['text'] }}">
                    {{ $statusLabel }}
                </span>
            </div>

            <!-- Info -->
            <div class="flex items-center gap-4 text-sm text-gray-500 mb-4">
                <div class="flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    {{ $period->period_name }}
                </div>
                <div class="flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    </svg>
                    {{ $period->lines->count() }} Lokasi
                </div>
            </div>

            <!-- Progress -->
            <div class="mb-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs text-gray-500">Progress</span>
                    <span class="text-xs font-medium text-gray-700">{{ $progress }}%</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-[#E6002D] rounded-full transition-all" style="width: {{ $progress }}%"></div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                <span class="text-xs text-gray-400">{{ $period->created_at->format('d M Y') }}</span>
                <span class="text-xs font-medium text-[#E6002D] flex items-center gap-1">
                    Lihat Detail
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </span>
            </div>
        </a>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $periods->links() }}
    </div>
@endif
@endsection
