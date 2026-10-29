@extends('layouts.sijatim')

@php
    $editable = ! $batch->committed_at && ! $period->isLocked() && $batch->status !== 'discarded';
    $problemCount = $batch->rows()->whereIn('status', ['error', 'pending'])->count();
    $rowStatus = ['valid' => ['Siap', 'badge-success'], 'error' => ['Perlu diperbaiki', 'badge-danger'], 'ignored' => ['Diabaikan', 'badge-gray'], 'pending' => ['Menunggu', 'badge-warning']];
    $batchStatus = ['pending' => 'Belum diterapkan', 'completed' => 'Sudah diterapkan', 'discarded' => 'Ditarik'];
    $label = fn ($key) => ucfirst(str_replace('_', ' ', $key));
    $internal = fn ($key) => str_starts_with($key, '_') || $key === 'conflict_choice' || str_ends_with($key, '_id');
    $conflictsOpen = $conflicts->filter(fn ($c) => $c['choice'] === null)->count();
    $show = fn ($value) => is_bool($value) ? ($value ? 'Ya' : 'Tidak') : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);

    // Past-month Telkom workbook: sheet/column choice, what applying it changes, friendlier labels.
    $history = $history ?? null;
    $fields = \App\Services\Billing\HistoryWorkbookReader::FIELDS;
    $missingFields = $history ? $history['summary']['missing_fields'] : [];
    $missingLabels = collect($missingFields)->map(fn ($field) => $fields[$field][0])->implode(', ');
    $historyColumns = $history['analysis']['columns'] ?? [];
    $rowLabel = fn ($row, $key) => $row->kind === 'history' && isset($fields[$key]) ? $fields[$key][0] : $label($key);
    // Values worked out from the file (not typed in it): editing them would have no effect.
    $derived = fn ($row, $key) => $row->kind === 'history' && in_array($key, ['event_type', 'coverage_start', 'coverage_end', 'router_mode'], true);
    $historyChoices = ['office_type_id' => 'Jenis kantor', 'location_category_id' => 'Kategori lokasi', 'service_id' => 'Layanan', 'bandwidth_option_id' => 'Bandwidth eksisting'];
    $originalLabel = function ($row, $key) use ($historyColumns) {
        if ($row->kind !== 'history' || ! is_numeric($key)) {
            return $key;
        }
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex((int) $key);

        $text = $historyColumns[$letter]['label'] ?? '';

        return $text === '' ? $letter : $letter.' · '.$text;
    };
    $money = fn ($value) => 'Rp '.number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2, ',', '.');
@endphp

@section('content')
<div class="flex flex-wrap justify-between items-start gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold">{{ $batch->original_filename }}</h1>
        <p class="text-gray-500 mt-1">
            Tagihan {{ $period->period_name }}@if ($history) · Workbook Telkom bulan lampau @endif · {{ $batchStatus[$batch->status] ?? $batch->status }} · {{ number_format($batch->total_rows, 0, ',', '.') }} baris
        </p>
    </div>
    <a class="btn btn-secondary" href="{{ $backUrl }}">&larr; Kembali</a>
</div>

@if ($history)
    @php
        $analysis = $history['analysis'];
        $summary = $history['summary'];
        $applied = (bool) $batch->committed_at;
        $sheetName = $analysis['sheet'] ?? '—';
        $mapping = array_map(fn ($letter) => $letter ?? '', $analysis['mapping'] ?? []);
        $mappedCount = count(array_filter($mapping));
        $columnTitles = collect($historyColumns)->map(fn ($column, $letter) => $letter.' · '.($column['label'] !== '' ? $column['label'] : 'tanpa judul'));
        $orderLabels = ['existing' => 'EKSISTING', 'pasang_baru' => 'PASANG BARU', 'pemutusan' => 'PEMUTUSAN', 'relokasi' => 'RELOKASI', 'upgrade' => 'UPGRADE', 'downgrade' => 'DOWNGRADE'];
        $newToMaster = array_flip($summary['new_to_master']);
        $removedWithInputs = collect($summary['removed'])->where('inputs', '>', 0)->count();
    @endphp

    {{-- 1. Sheet & columns: open while a required column is missing, a one-line summary otherwise --}}
    <details class="card rounded-xl mb-6" @if ($missingFields) open @endif
             x-data="{ sheet: @js($sheetName), chosen: @js($sheetName), columns: @js($historyColumns), mapping: @js($mapping) }">
        <summary class="flex flex-wrap items-center justify-between gap-2 cursor-pointer px-6 py-4">
            <span>
                <span class="font-semibold text-lg">1. Sheet &amp; kolom</span>
                <span class="block text-sm text-gray-500">Sheet {{ $sheetName }} · {{ $mappedCount }} dari {{ count($fields) }} kolom dikenali</span>
            </span>
            @if ($missingFields)
                <span class="badge-warning">Ada kolom wajib yang belum dipilih</span>
            @else
                <span class="text-sm text-[#E6002D]">{{ $editable ? 'Lihat / ubah' : 'Lihat' }}</span>
            @endif
        </summary>
        <form method="POST" action="{{ route('imports.mapping', $batch) }}" class="border-t border-gray-200 p-6">
            @csrf
            @if ($editable)
                <label class="block max-w-md text-sm font-medium">Sheet yang dipakai
                    <select name="sheet" class="input mt-1" x-model="sheet">
                        @foreach ($analysis['sheets'] ?? [] as $sheet)
                            <option value="{{ $sheet['name'] }}" @selected($sheet['name'] === $sheetName)>{{ $sheet['name'] }} · {{ number_format($sheet['sid_rows'], 0, ',', '.') }} SSID</option>
                        @endforeach
                    </select>
                </label>
                <p x-cloak x-show="sheet !== chosen" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Sheet diganti. Kolom akan dipilih ulang otomatis dari sheet ini setelah Anda klik <strong>Baca ulang dengan pilihan ini</strong>. Setelah itu periksa dan koreksi bila perlu.
                </p>
            @else
                <p class="text-sm">Sheet yang dipakai: <strong>{{ $sheetName }}</strong></p>
            @endif

            <div class="overflow-x-auto mt-4" :class="{ 'opacity-50': sheet !== chosen }">
                <table class="table">
                    <thead><tr><th>Data</th><th>Kolom di file</th><th>Contoh</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($fields as $field => [$fieldLabel, $required])
                            @php($mapped = ($mapping[$field] ?? '') !== '')
                            <tr>
                                <td class="whitespace-nowrap">{{ $fieldLabel }}@if ($required) <span class="text-red-600" title="Wajib dipilih">*</span>@endif</td>
                                <td class="min-w-[16rem]">
                                    @if ($editable)
                                        <select class="input" name="mapping[{{ $field }}]" x-model="mapping.{{ $field }}" :disabled="sheet !== chosen">
                                            <option value="">— tidak dipakai —</option>
                                            @foreach ($columnTitles as $letter => $title)
                                                <option value="{{ $letter }}" @selected($mapping[$field] === (string) $letter)>{{ $title }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{ $mapped ? ($columnTitles[$mapping[$field]] ?? $mapping[$field]) : '—' }}
                                    @endif
                                </td>
                                <td class="text-gray-600 break-all"><span x-text="(columns[mapping.{{ $field }}]?.samples ?? []).join(' · ') || '—'">{{ implode(' · ', $historyColumns[$mapping[$field]]['samples'] ?? []) ?: '—' }}</span></td>
                                <td class="whitespace-nowrap">
                                    <span class="font-medium text-green-700" x-show="mapping.{{ $field }}" @unless ($mapped) x-cloak @endunless>✓ Terpilih</span>
                                    @if ($required)
                                        <span class="badge-warning" x-show="! mapping.{{ $field }}" @if ($mapped) x-cloak @endif>Wajib dipilih</span>
                                    @else
                                        <span class="text-gray-400" x-show="! mapping.{{ $field }}" @if ($mapped) x-cloak @endif>—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($editable)
                <div class="flex flex-wrap items-center gap-3 mt-4">
                    <button class="btn btn-secondary">Baca ulang dengan pilihan ini</button>
                    <p class="text-xs text-gray-500">Perbaikan baris yang sudah Anda simpan akan hilang dan perlu diulang.</p>
                </div>
            @endif
        </form>
    </details>

    {{-- 2. What applying the file changes in the period's SSID list --}}
    <div class="card rounded-xl p-6 mb-6">
        <h2 class="font-semibold text-lg">{{ $applied ? '2. Isi file yang sudah diterapkan' : '2. Perbedaan dengan daftar SSID periode ini' }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div class="rounded-lg border border-gray-200 p-4">
                <p class="text-3xl font-bold">{{ number_format($summary['matched'], 0, ',', '.') }}</p>
                <p class="text-sm font-medium mt-1">{{ $applied ? 'SSID dari file ini' : 'SSID cocok' }}</p>
                @unless ($applied)<p class="text-xs text-gray-500 mt-1">Sudah ada di periode ini dan ada di file.</p>@endunless
            </div>
            @unless ($applied)
                <div class="rounded-lg border p-4 {{ count($summary['added']) ? 'border-green-300 bg-green-50' : 'border-gray-200' }}">
                    <p class="text-3xl font-bold">{{ number_format(count($summary['added']), 0, ',', '.') }}</p>
                    <p class="text-sm font-medium mt-1">SSID ditambahkan</p>
                    @if (count($summary['new_to_master']))
                        <p class="text-xs text-gray-600 mt-1">{{ number_format(count($summary['new_to_master']), 0, ',', '.') }} belum ada di Master → disimpan sebagai tidak aktif, tidak ikut bulan berikutnya</p>
                    @endif
                </div>
                <div class="rounded-lg border p-4 {{ count($summary['removed']) ? 'border-amber-300 bg-amber-50' : 'border-gray-200' }}">
                    <p class="text-3xl font-bold">{{ number_format(count($summary['removed']), 0, ',', '.') }}</p>
                    <p class="text-sm font-medium mt-1">SSID dikeluarkan dari periode ini</p>
                    @if ($removedWithInputs)
                        <p class="text-xs text-amber-900 mt-1">{{ number_format($removedWithInputs, 0, ',', '.') }} di antaranya punya data perubahan/downtime/penyesuaian yang ikut dihapus</p>
                    @endif
                </div>
            @endunless
        </div>

        <div class="mt-5">
            <p class="text-sm font-medium">Type order di file</p>
            <div class="flex flex-wrap gap-2 mt-2">
                @foreach ($orderLabels as $type => $text)
                    <span class="badge-gray {{ ($summary['orders'][$type] ?? 0) ? '' : 'opacity-50' }}">{{ $text }} · {{ number_format($summary['orders'][$type] ?? 0, 0, ',', '.') }}</span>
                @endforeach
            </div>
        </div>
        <p class="text-sm mt-4">Total harga eksisting Telkom: <strong>{{ $money($summary['price_total']) }}</strong></p>
        <p class="text-sm mt-1">Downtime terisi: <strong>{{ number_format($summary['downtime_rows'], 0, ',', '.') }} SSID</strong></p>

        @if (! $applied && $summary['added'])
            <details class="mt-5">
                <summary class="cursor-pointer text-sm font-medium">Lihat {{ number_format(count($summary['added']), 0, ',', '.') }} SSID yang ditambahkan</summary>
                <ul class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-1 text-sm mt-3 max-h-64 overflow-auto">
                    @foreach ($summary['added'] as $sid)
                        <li>{{ $sid }}@if (isset($newToMaster[$sid])) <span class="badge-warning" title="Belum ada di Master. Disimpan sebagai tidak aktif.">baru di Master</span>@endif</li>
                    @endforeach
                </ul>
            </details>
        @endif
        @if (! $applied && $summary['removed'])
            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium">Lihat {{ number_format(count($summary['removed']), 0, ',', '.') }} SSID yang dikeluarkan</summary>
                <div class="overflow-auto max-h-64 mt-3">
                    <table class="table">
                        <thead><tr><th>SSID</th><th>Jenis kantor</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($summary['removed'] as $removed)
                                <tr>
                                    <td class="font-semibold whitespace-nowrap">{{ $removed['sid'] }}</td>
                                    <td>{{ $removed['branch'] ?: '—' }}</td>
                                    <td>@if ($removed['inputs'] > 0)<span class="text-xs text-amber-800">{{ $removed['inputs'] }} data perubahan/downtime/penyesuaian SSID ini ikut dihapus</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </div>
@endif

@if ($editable)
    <div class="card rounded-xl p-6 mb-6 {{ $problemCount || $missingFields ? 'border-amber-300 bg-amber-50' : 'border-green-300 bg-green-50' }}">
        @if ($missingFields)
            <h2 class="font-semibold text-lg">Pilih dulu kolom wajib: {{ $missingLabels }}</h2>
            <p class="text-sm text-gray-700 mt-1">Buka kotak <strong>1. Sheet &amp; kolom</strong> di atas, pilih kolomnya, lalu klik <strong>Baca ulang dengan pilihan ini</strong>.</p>
        @elseif ($problemCount)
            <h2 class="font-semibold text-lg">{{ $problemCount }} baris perlu diperbaiki</h2>
            @if ($conflictsOpen)
                <p class="text-sm text-gray-700 mt-1"><strong>{{ $conflictsOpen }}</strong> di antaranya bentrok dengan downtime yang sudah ada. Pilih nilai yang benar pada kotak merah di bawah.</p>
            @endif
            <p class="text-sm text-gray-700 mt-1">Perbaiki nilainya atau centang <strong>Abaikan baris ini</strong>, isi alasan, lalu simpan. Setelah semua beres, klik <strong>Terapkan</strong>.</p>
        @else
            <h2 class="font-semibold text-lg">Semua baris siap</h2>
            <p class="text-sm text-gray-700 mt-1">{!! $history ? 'Klik <strong>Terapkan</strong> untuk menyesuaikan daftar SSID periode ini dengan file.' : 'Klik <strong>Terapkan</strong> untuk memasukkan data dari file ini.' !!}</p>
        @endif
        <div class="flex flex-wrap gap-3 mt-4">
            <form method="POST" action="{{ route('imports.commit', $batch) }}">
                @csrf
                <button class="btn btn-primary" @disabled($problemCount || $missingFields)>Terapkan</button>
            </form>
            <form method="POST" action="{{ route('imports.revalidate', $batch) }}">
                @csrf
                <button class="btn btn-secondary">Validasi ulang</button>
            </form>
            <a class="btn btn-ghost" href="{{ route('imports.show', [$batch, 'status' => 'error']) }}">Tampilkan yang bermasalah saja</a>
            <a class="btn btn-ghost" href="{{ route('imports.show', $batch) }}">Tampilkan semua</a>
        </div>
    </div>
@endif

@if ($editable && $conflicts->isNotEmpty())
    <form id="conflict-form" method="POST" action="{{ route('imports.conflicts', $batch) }}" class="card rounded-xl border-red-300 mb-6" aria-labelledby="conflict-title">
        @csrf
        <div class="rounded-t-xl border-b border-red-200 bg-red-50 px-6 py-4">
            <h2 id="conflict-title" class="font-semibold text-lg text-red-800">Bentrok: {{ $conflicts->count() }} SSID sudah punya downtime</h2>
            <p class="text-sm text-red-900 mt-1">
                File ini berisi downtime untuk SSID yang sudah diisi sebelumnya (manual atau dari file lain). Satu SSID hanya boleh punya satu nilai,
                jadi pilih mana yang benar. Pilihan yang tidak dipakai tidak dihapus, hanya ditandai "tidak dihitung".
            </p>
        </div>
        <div class="p-6">
            <div class="flex flex-wrap gap-2 mb-4">
                <button type="button" class="btn btn-secondary" onclick="document.querySelectorAll('#conflict-form input[value=use_file]').forEach(function (e) { e.checked = true })">Pilih semua: pakai nilai dari file</button>
                <button type="button" class="btn btn-secondary" onclick="document.querySelectorAll('#conflict-form input[value=keep_existing]').forEach(function (e) { e.checked = true })">Pilih semua: pertahankan yang sudah ada</button>
            </div>
            <div class="overflow-auto max-h-[28rem]">
                <table class="table">
                    <thead>
                        <tr><th>SSID</th><th>Sudah ada di sistem</th><th>Dari file ini</th><th>Pilih yang benar</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($conflicts as $conflict)
                            @php($fileLabel = number_format((float) $conflict['file_seconds'], 0, ',', '.').' detik')
                            @php($same = collect($conflict['existing'])->contains(fn ($e) => $e['value'] === $fileLabel))
                            <tr class="align-top">
                                <td class="font-semibold whitespace-nowrap">{{ $conflict['row']->sid }}<span class="block text-xs font-normal text-gray-500">Baris {{ $conflict['row']->row_number }}</span></td>
                                <td>
                                    @foreach ($conflict['existing'] as $existing)
                                        <p class="font-medium">{{ $existing['value'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $existing['source'] }}</p>
                                    @endforeach
                                </td>
                                <td>
                                    <p class="font-medium">{{ $fileLabel }}</p>
                                    @if ($same)<span class="badge-gray">Nilai sama</span>@endif
                                </td>
                                <td class="min-w-[15rem]">
                                    <label class="flex items-center gap-2 py-1"><input type="radio" class="text-[#E6002D] focus:ring-[#E6002D]" name="choices[{{ $conflict['row']->id }}]" value="use_file" @checked($conflict['choice'] === 'use_file')> Pakai nilai dari file</label>
                                    <label class="flex items-center gap-2 py-1"><input type="radio" class="text-[#E6002D] focus:ring-[#E6002D]" name="choices[{{ $conflict['row']->id }}]" value="keep_existing"> Pertahankan yang sudah ada</label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-3 mt-4">
                <button class="btn btn-primary">Simpan pilihan</button>
                <p class="text-xs text-gray-500">SSID yang belum dipilih tetap menahan tombol Terapkan. Anda bisa memilih sebagian dulu.</p>
            </div>
        </div>
    </form>
@endif

@foreach ($rows as $row)
    @php([$statusText, $statusBadge] = $rowStatus[$row->status] ?? [$row->status, 'badge-gray'])
    <div class="card rounded-xl p-5 mb-4">
        <div class="flex flex-wrap justify-between gap-3">
            <h2 class="font-semibold">Baris {{ $row->row_number }}@if ($row->sid) · {{ $row->sid }}@endif <span class="text-sm font-normal text-gray-500">({{ $row->sheet }})</span></h2>
            <span class="{{ $statusBadge }}">{{ $statusText }}</span>
        </div>
        @foreach ($row->errors ?? [] as $error)
            <p class="text-sm text-red-600 mt-2">{{ $error }}</p>
        @endforeach

        @if ($editable)
            <form method="POST" action="{{ route('imports.resolve', [$batch, $row]) }}" class="mt-4">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach ($row->normalized ?? [] as $key => $value)
                        @if (! is_array($value) && ! $internal($key) && ! $derived($row, $key))
                            <label class="text-sm">{{ $rowLabel($row, $key) }}<input class="input mt-1" name="values[{{ $key }}]" value="{{ $value }}"></label>
                        @endif
                    @endforeach
                    @if (in_array($row->kind, ['inventory', 'tariffs', 'events', 'history']))
                        @foreach ($choices as $key => $options)
                            @if (($row->kind === 'events' && $key !== 'bandwidth_option_id') || ($row->kind === 'tariffs' && $key === 'office_type_id') || ($row->kind === 'history' && ! isset($historyChoices[$key])) || $key === 'addendum_contract_id')
                                @continue
                            @endif
                            <label class="text-sm">Pilih {{ $row->kind === 'history' ? $historyChoices[$key] : $label(str_replace('_id', '', $key)) }}
                                <select class="input mt-1" name="values[{{ $key }}]">
                                    <option value="">Pilih bila diperlukan</option>
                                    @foreach ($options as $id => $name)
                                        <option value="{{ $id }}" @selected(($row->normalized[$key] ?? null) == $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3 mt-4">
                    <input class="input flex-1" required name="reason" placeholder="Alasan koreksi / pengabaian">
                    <label class="text-sm"><input type="checkbox" name="ignore" value="1"> Abaikan baris ini</label>
                    <button class="btn btn-secondary">Simpan</button>
                </div>
            </form>
        @else
            <dl class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-1 mt-3 text-sm">
                @foreach ($row->normalized ?? [] as $key => $value)
                    @if (! $internal($key) && ! $derived($row, $key))
                        <div class="flex justify-between gap-3 border-b border-gray-100 py-1"><dt class="text-gray-500">{{ $rowLabel($row, $key) }}</dt><dd class="text-right break-all">{{ $show($value) }}</dd></div>
                    @endif
                @endforeach
            </dl>
        @endif

        <details class="mt-3">
            <summary class="text-xs text-gray-500 cursor-pointer">Nilai asli dari file & riwayat keputusan</summary>
            <dl class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-1 mt-2 text-xs">
                @foreach ($row->original ?? [] as $key => $value)
                    @continue($row->kind === 'history' && ($value === null || $value === ''))
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1"><dt class="text-gray-500">{{ $originalLabel($row, $key) }}</dt><dd class="text-right break-all">{{ $show($value) }}</dd></div>
                @endforeach
            </dl>
            @if ($row->resolution_reason)
                <p class="text-xs mt-2">Keputusan terakhir: {{ $row->resolution_reason }}</p>
            @endif
        </details>
    </div>
@endforeach
{{ $rows->links() }}

@if ($history && $batch->committed_at)
    <p class="card rounded-xl p-5 mt-6 text-sm text-gray-700">Daftar SSID dari file ini sudah diterapkan. Untuk koreksi, upload workbook yang sudah diperbaiki.</p>
@elseif (! $period->isLocked() && $batch->status !== 'discarded')
    <details class="card rounded-xl p-5 mt-6">
        <summary class="font-semibold cursor-pointer">{{ $batch->committed_at ? 'Tarik kembali data dari file ini' : 'Batalkan file ini' }}</summary>
        <form method="POST" action="{{ route('imports.discard', $batch) }}" class="mt-3">
            @csrf
            <p class="text-sm text-gray-600 mb-3">Perubahan, downtime, trafik, dan penyesuaian dari file ini ditarik. Inventory/tarif yang sudah diterapkan harus dikoreksi lewat Master Harga.</p>
            <input class="input" required name="reason" minlength="3" placeholder="Alasan">
            <button class="btn btn-secondary mt-3">Ya, tarik file ini</button>
        </form>
    </details>
@endif
<p class="text-xs text-gray-400 mt-6 break-all">SHA-256 {{ $batch->file_hash }}</p>
@endsection
