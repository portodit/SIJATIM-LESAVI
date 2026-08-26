@extends('layouts.sijatim')

@section('content')
<div class="mb-6">
    <h1 class="text-xl font-bold text-gray-900">Invoice</h1>
    <p class="text-gray-500 mt-1">Riwayat invoice yang di-generate</p>
</div>

<div class="card">
    @if($invoices->isEmpty())
        <div class="p-12 text-center">
            <p class="text-gray-500">Belum ada invoice</p>
        </div>
    @else
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Snapshot</th>
                    <th>Lokasi</th>
                    <th>Total</th>
                    <th>Tanggal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoices as $invoice)
                    <tr>
                        <td class="font-mono font-medium">{{ $invoice->invoice_number }}</td>
                        <td>
                            <a href="{{ route('snapshots.show', $invoice->snapshot) }}" class="text-gray-900 hover:text-[#E6002D]">
                                {{ $invoice->snapshot->name ?? '-' }}
                            </a>
                        </td>
                        <td class="text-gray-600">{{ $invoice->total_locations }}</td>
                        <td class="font-medium">{{ $invoice->formatted_amount }}</td>
                        <td class="text-gray-500 text-sm">{{ $invoice->generated_at ? $invoice->generated_at->locale('id')->format('d M Y') : '-' }}</td>
                        <td>
                            @if($invoice->file_path)
                                <a href="{{ route('invoices.download', $invoice) }}" class="btn btn-ghost p-2" title="Download">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">
            {{ $invoices->links() }}
        </div>
    @endif
</div>
@endsection
