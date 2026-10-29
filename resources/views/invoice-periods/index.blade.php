@extends('layouts.sijatim')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Periode Tagihan</h1>
        <p class="text-gray-500 mt-1">Satu periode = satu invoice bulanan. Invoice bulan X menagih pemakaian bulan sebelumnya.</p>
    </div>
    <div class="flex items-center gap-3">
        <select onchange="window.location.href = this.value" class="input w-40" aria-label="Filter tahun">
            <option value="{{ route('invoice-periods.index') }}">Semua tahun</option>
            @foreach ($years as $year)
                <option value="{{ route('invoice-periods.index', ['year' => $year]) }}" @selected(request('year') == $year)>{{ $year }}</option>
            @endforeach
        </select>
        <a href="{{ route('invoice-periods.create') }}" class="btn btn-primary whitespace-nowrap">+ Periode baru</a>
    </div>
</div>

<div class="card rounded-xl overflow-x-auto">
    <table class="table">
        <thead>
            <tr>
                <th>Tagihan</th>
                <th>Pemakaian</th>
                <th>Status</th>
                <th>Catatan</th>
                <th class="text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($periods as $period)
                @php($state = $period->workflowState($period->revisions->first()))
                <tr>
                    <td>
                        <a href="{{ route('invoice-periods.step1', $period) }}" class="font-bold text-gray-900 hover:text-[#E6002D]">{{ $period->period_name }}</a>
                        <span class="block text-xs text-gray-500">{{ $period->period_code }}</span>
                    </td>
                    <td class="text-gray-600">{{ $period->usage_name }}</td>
                    <td><span class="badge {{ $state['badge'] }}">{{ $state['label'] }}</span></td>
                    <td class="text-sm text-gray-500 max-w-xs truncate">{{ $period->notes }}</td>
                    <td class="text-right whitespace-nowrap">
                        <a href="{{ route('invoice-periods.step1', $period) }}" class="btn btn-secondary py-1.5">Buka</a>
                        @if ($period->canEdit())
                            <a href="{{ route('invoice-periods.edit', $period) }}" class="btn btn-ghost py-1.5">Catatan</a>
                        @endif
                        @if ($period->status === 'draft')
                            <form action="{{ route('invoice-periods.destroy', $period) }}" method="POST" class="inline" onsubmit="return confirm('Hapus periode {{ $period->period_code }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-ghost py-1.5 text-red-600 hover:bg-red-50">Hapus</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-12 text-center text-gray-500">
                        Belum ada periode tagihan.
                        <a href="{{ route('invoice-periods.create') }}" class="text-[#E6002D] hover:underline ml-1">Buat periode pertama &rarr;</a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if ($periods->hasPages())
        <div class="px-4 py-3 border-t">{{ $periods->links() }}</div>
    @endif
</div>
@endsection
