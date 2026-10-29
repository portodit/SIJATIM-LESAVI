{{-- Invoice versions and their files. Expects $revisions (with outputs). --}}
@php
    $statusLabels = [
        'ready' => ['File siap', 'badge-success'],
        'failed' => ['Gagal dibuat', 'badge-danger'],
        'generating' => ['Sedang dibuat', 'badge-info'],
        'approved' => ['Menunggu dibuat', 'badge-info'],
        'superseded' => ['Diganti revisi', 'badge-gray'],
    ];
@endphp
<div class="card rounded-xl p-6">
    <h2 class="font-semibold text-lg mb-3">Riwayat versi invoice</h2>
    @forelse ($revisions as $revision)
        @php([$label, $badge] = $statusLabels[$revision->status] ?? [$revision->status, 'badge-gray'])
        <div class="py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p><strong>Versi {{ $revision->version }}</strong> <span class="{{ $badge }} ml-2">{{ $label }}</span></p>
                <p class="text-sm text-gray-500">Difinalisasi {{ $revision->approved_at?->format('d/m/Y H:i') }}@if ($revision->reason) · {{ $revision->reason }}@endif</p>
                @if ($revision->generation_error)
                    <p class="text-sm text-red-600 mt-1">Pembuatan file gagal: {{ $revision->generation_error }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($revision->status === 'ready')
                    @foreach ($revision->outputs as $output)
                        <a class="btn btn-secondary" href="{{ route('billing.download', $output) }}">Unduh {{ $output->download_label }}</a>
                    @endforeach
                    @unless ($revision->outputs->contains('output_type', 'summary_png'))
                        <form method="POST" action="{{ route('billing.summary-image', $revision) }}">
                            @csrf
                            <button class="btn btn-secondary">Buat gambar ringkasan (PNG)</button>
                        </form>
                    @endunless
                @endif
                @if (in_array($revision->status, ['failed', 'approved']) || ($revision->status === 'generating' && $revision->generation_started_at?->lt(now()->subMinutes(6))))
                    <form method="POST" action="{{ route('billing.retry', $revision) }}">
                        @csrf
                        <button class="btn btn-primary">Coba buat file lagi</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <p class="text-gray-500">Belum ada versi yang diterbitkan.</p>
    @endforelse
</div>
