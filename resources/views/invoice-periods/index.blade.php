@extends('layouts.sijatim')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Periode Invoice</h1>
    <p class="text-gray-500 mt-1">Kelola folder kerja tagihan Bank Jatim</p>
</div>

<!-- Header Actions -->
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-4">
        <!-- Filter Tahun -->
        <select onchange="window.location.href = this.value" class="input w-40">
            <option value="{{ route('invoice-periods.index') }}">Semua Tahun</option>
            @foreach($years as $year)
                <option value="{{ route('invoice-periods.index', ['year' => $year]) }}"
                    {{ request('year') == $year ? 'selected' : '' }}>
                    {{ $year }}
                </option>
            @endforeach
        </select>
    </div>

    <a href="{{ route('invoice-periods.create') }}" class="btn btn-primary">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Buat Periode Baru
    </a>
</div>

<!-- Periods List -->
<div class="card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Periode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Step Terakhir</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dibuat</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($periods as $period)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-4">
                            <div>
                                <span class="font-bold text-lg text-gray-900">{{ $period->period_code }}</span>
                                <p class="text-sm text-gray-500">{{ $period->period_name }}</p>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-sm text-gray-600">
                            {{ $period->start_date->format('d/m/Y') }} - {{ $period->end_date->format('d/m/Y') }}
                        </td>
                        <td class="px-4 py-4">
                            <span class="badge badge-{{ $period->status_badge_color }}">
                                {{ $period->status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex items-center gap-2">
                                <div class="w-24 h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-[#E6002D] rounded-full" style="width: {{ $period->progress_percentage }}%"></div>
                                </div>
                                <span class="text-sm text-gray-600">{{ $period->progress_percentage }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-sm text-gray-600">
                            @if($period->current_step_code)
                                @php $stepNames = ['overview' => 'Overview', 'ssid_list' => '1. Listing SSID', 'type_order' => '2. Tipe & Bandwidth', 'bandwidth' => '3. Bandwidth', 'bandwidth_benchmark' => '4. Benchmark EoS', 'traffic_usage' => '5. Trafik', 'price' => '6. Harga', 'sla' => '7. SLA', 'restitution' => '8. Restitusi', 'price_benchmark' => '9. Benchmark Addendum', 'finalization' => '10. Finalisasi']; @endphp
                                <span class="text-xs bg-gray-100 px-2 py-1 rounded">{{ $stepNames[$period->current_step_code] ?? $period->current_step_code }}</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-sm text-gray-500">
                            {{ $period->created_at->diffForHumans() }}
                            <br>
                            <span class="text-xs text-gray-400">oleh {{ $period->creator->name ?? '-' }}</span>
                        </td>
                        <td class="px-4 py-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('invoice-periods.show', $period) }}" class="btn btn-secondary btn-sm">
                                    Lihat
                                </a>
                                @if($period->canEdit())
                                    <a href="{{ route('invoice-periods.edit', $period) }}" class="btn btn-ghost btn-sm">
                                        Edit
                                    </a>
                                @endif
                                @if($period->status === 'draft')
                                    <form action="{{ route('invoice-periods.destroy', $period) }}" method="POST" onsubmit="return confirm('Hapus periode {{ $period->period_code }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost btn-sm text-red-600 hover:bg-red-50">
                                            Hapus
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                            <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <p>Belum ada periode invoice.</p>
                            <a href="{{ route('invoice-periods.create') }}" class="text-[#E6002D] hover:underline mt-2 inline-block">
                                Buat periode pertama →
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($periods->hasPages())
        <div class="px-4 py-3 border-t">
            {{ $periods->links() }}
        </div>
    @endif
</div>
@endsection
