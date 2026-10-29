@extends('layouts.sijatim')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Master Harga</h1>
    <p class="text-gray-500 mt-1">Daftar harga layanan Bank Jatim</p>
</div>

<!-- Header Actions -->
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-4">
        <span class="text-sm text-gray-500">{{ $priceMasters->total() }} harga</span>
    </div>
</div>

<!-- Price List -->
<div class="card">
    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Lokasi</th>
                    <th>Layanan</th>
                    <th>Bandwidth</th>
                    <th class="text-right">Harga Bulanan</th>
                    <th class="text-right">+ PPN {{ \App\Services\Billing\TaxRate::label($ppnRate) }}</th>
                    <th class="text-right">Harga Pasang</th>
                    <th class="text-right">+ PPN {{ \App\Services\Billing\TaxRate::label($ppnRate) }}</th>
                    <th>Berlaku</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($priceMasters as $price)
                    <tr>
                        <td class="font-medium text-gray-900">{{ $price->locationCategory->name ?? '-' }}</td>
                        <td class="text-gray-600">{{ $price->service->name ?? '-' }}</td>
                        <td class="text-gray-600">{{ $price->bandwidthOption->name ?? '-' }}</td>
                        <td class="text-right font-mono">Rp {{ number_format($price->monthly_price, 0, ',', '.') }}</td>
                        <td class="text-right font-mono text-gray-600">Rp {{ number_format($price->calculateWithPpn($ppnRate), 0, ',', '.') }}</td>
                        <td class="text-right font-mono">Rp {{ number_format($price->installation_price, 0, ',', '.') }}</td>
                        <td class="text-right font-mono text-gray-600">Rp {{ number_format($price->calculateInstallationWithPpn($ppnRate), 0, ',', '.') }}</td>
                        <td class="text-sm text-gray-600">
                            @if($price->effective_start_date)
                                {{ \Carbon\Carbon::parse($price->effective_start_date)->format('d/m/Y') }}
                                @if($price->effective_end_date)
                                    - {{ \Carbon\Carbon::parse($price->effective_end_date)->format('d/m/Y') }}
                                @else
                                    - sekarang
                                @endif
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @if($price->is_active)
                                <span class="badge badge-success">Aktif</span>
                            @else
                                <span class="badge badge-gray">Nonaktif</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-gray-500">
                            <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p>Tidak ada data harga</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($priceMasters->hasPages())
        <div class="px-4 py-3 border-t">
            {{ $priceMasters->links() }}
        </div>
    @endif
</div>
@endsection
