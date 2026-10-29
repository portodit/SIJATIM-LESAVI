@extends('layouts.sijatim')

@php
    $locked = $period->isLocked();
    $kinds = [
        'outages' => 'Downtime (SSID + detik, atau interval)',
        'events' => 'Perubahan layanan',
        'traffic' => 'Average MRTG (opsional)',
        'workbook' => 'Workbook Telkom lengkap',
        'history' => 'Workbook Telkom bulan lampau',
        'inventory' => 'Inventory / Master SSID',
        'tariffs' => 'Tarif / ADD II',
        'incidents' => 'Bukti insiden (tiket)',
        'adjustments' => 'Kredit / override',
        'notes' => 'Catatan pendukung',
    ];
    $decisionLabels = ['eligible' => 'Dihitung', 'excluded' => 'Tidak dihitung', 'pending' => 'Menunggu review'];

    // Price problems box (Kredit manual / override harga): Rp text, signed difference, and what each fill button puts in the form.
    $rupiah = fn ($value) => 'Rp '.number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2, ',', '.');
    $signed = fn ($value) => ((float) $value > 0 ? '+' : ((float) $value < 0 ? '−' : '')).$rupiah(abs((float) $value));
    $plain = fn ($value) => str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
    $overridden = $adjustments->where('kind', 'tariff_override')->pluck('sid')->flip()->all();
    $fills = [];
    $hints = [];
    foreach ($priceProblems as $problem) {
        if ($problem['code'] === 'tariff') {
            $hints[$problem['sid']] ??= 'Tarif belum ada di Master Harga.';

            continue;
        }
        if ($problem['reference'] === null) {
            continue;
        }
        $telkomPrice = $rupiah($problem['reference']);
        $systemPrice = $rupiah($problem['monthly'] ?? $problem['system']);
        $hints[$problem['sid']] = 'Harga Telkom '.$telkomPrice.' · sistem '.$rupiah($problem['system']).' · selisih '.$signed($problem['difference']);
        if (! isset($overridden[$problem['sid']])) {
            $reference = $problem['source_text'] ?: 'Workbook Telkom';
            $fills[$problem['sid']] = [
                'source' => ['amount' => $plain($problem['reference']), 'reference' => $reference, 'reason' => 'Disamakan dengan harga workbook Telkom '.$telkomPrice.'; tarif sistem '.$systemPrice.'.'],
                'system' => ['amount' => $plain($problem['monthly'] ?? $problem['system']), 'reference' => $reference, 'reason' => 'Tetap memakai tarif sistem '.$systemPrice.'; harga Telkom '.$telkomPrice.' tidak dipakai.'],
            ];
        }
    }
@endphp

@section('content')
<x-sij.period-header :period="$period" :step="null" />

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
    Halaman ini untuk kasus khusus atau admin. Pekerjaan bulanan biasa cukup melalui langkah 1–3 di atas.
    Versi input saat ini: v{{ $period->input_version }}.
</div>

{{-- Full invoice details --}}
<details class="card rounded-xl p-6 mb-6">
    <summary class="font-semibold cursor-pointer">Detail invoice lengkap</summary>
    <form class="mt-5" method="POST" action="{{ route('billing.metadata', $period) }}">
        @csrf
        <fieldset @disabled($locked)>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="text-sm">Profil billing
                    <select class="input mt-1" name="billing_profile_id">
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->id }}" @selected($period->billing_profile_id === $profile->id)>{{ $profile->name }} v{{ $profile->version }} · PPN {{ \App\Services\Billing\TaxRate::label($profile->settings['tax_rate'] ?? null) }}</option>
                        @endforeach
                    </select>
                </label>
                @foreach (['invoice_number' => 'Nomor invoice', 'issue_date' => 'Tanggal terbit', 'due_date' => 'Jatuh tempo', 'customer_name' => 'Nama pelanggan', 'supplier_name' => 'Nama penyedia', 'account_number' => 'Nomor akun', 'customer_id' => 'Customer ID', 'npwp' => 'NPWP', 'customer_address' => 'Alamat pelanggan', 'supplier_address' => 'Alamat penyedia'] as $key => $label)
                    <label class="text-sm">{{ $label }}
                        <input class="input mt-1" name="metadata[{{ $key }}]" value="{{ old('metadata.'.$key, $metadata[$key] ?? '') }}" type="{{ str_ends_with($key, '_date') ? 'date' : 'text' }}">
                    </label>
                @endforeach
                <label class="text-sm md:col-span-2">Catatan rekonsiliasi
                    <textarea class="input mt-1" name="metadata[reconciliation_notes]">{{ $metadata['reconciliation_notes'] ?? '' }}</textarea>
                </label>
            </div>
            <p class="text-sm text-gray-500 my-4">Kolom kosong memakai nilai dari Master Harga › Data Pelanggan & Invoice. Target SLA tetap 99%.</p>
            <button class="btn btn-primary">Simpan detail invoice</button>
        </fieldset>
    </form>
</details>

{{-- Uploads --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <form method="POST" enctype="multipart/form-data" class="card rounded-xl p-6" action="{{ route('billing.imports', $period) }}">
        @csrf
        <fieldset @disabled($locked)>
            <h2 class="font-semibold text-lg mb-3">Upload file sumber</h2>
            <p class="text-sm text-gray-500 mb-4">Pilih jenis file, upload, periksa hasil pencocokan SSID, lalu terapkan. MRTG opsional dan tidak menahan invoice.</p>
            <select class="input mb-3" name="kind">
                @foreach ($kinds as $kind => $label)
                    <option value="{{ $kind }}">{{ $label }}</option>
                @endforeach
            </select>
            <input class="block w-full text-sm" type="file" name="file" accept=".xlsx,.csv" required>
            <button class="btn btn-primary mt-4">Upload & periksa</button>
        </fieldset>
    </form>
    <div class="card rounded-xl p-6">
        <h2 class="font-semibold text-lg mb-3">Template</h2>
        <a class="btn btn-primary mb-4" href="{{ route('billing.templates.downtime') }}">Template downtime (Excel)</a>
        <div class="flex flex-wrap gap-2 mb-4">
            @foreach (\App\Services\Billing\WorkbookSourceReader::TEMPLATES as $kind => $headers)
                <a class="btn btn-secondary text-sm" href="{{ route('billing.templates', $kind) }}">{{ $kinds[$kind] ?? $kind }} (CSV)</a>
            @endforeach
        </div>
        <p class="text-sm text-gray-500">Pada template CSV gunakan tanggal YYYY-MM-DD dan angka desimal dengan titik. Jangan ubah SSID menjadi angka.</p>
    </div>
</div>

<div class="card rounded-xl p-6 mb-6" id="sumber">
    <h2 class="font-semibold text-lg mb-3">Riwayat file upload</h2>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>File</th><th>Jenis</th><th>Baris</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>
                            <a class="text-[#E6002D] hover:underline" href="{{ route('imports.show', $batch) }}">{{ $batch->original_filename }}</a>
                            <p class="text-xs text-gray-500 break-all">{{ $batch->created_at->format('d/m/Y H:i') }} · SHA-256 {{ $batch->file_hash }}</p>
                        </td>
                        <td>{{ $kinds[$batch->import_type] ?? $batch->import_type }}</td>
                        <td>{{ $batch->total_rows }}</td>
                        <td>{{ ['pending' => 'Belum diterapkan', 'completed' => 'Diterapkan', 'discarded' => 'Ditarik'][$batch->status] ?? $batch->status }}</td>
                        <td><a class="text-[#E6002D] hover:underline" href="{{ route('imports.show', $batch) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500">Belum ada file.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Outage review --}}
<div class="card rounded-xl p-6 mb-6" id="downtime">
    <h2 class="font-semibold text-lg mb-1">Review downtime</h2>
    <p class="text-sm text-gray-500 mb-3">Pilih satu sumber per SSID (aggregate atau interval). Tandai sumber lain "Tidak dihitung" agar durasi tidak dihitung dua kali. Tiket hanya bukti pendukung.</p>
    <form action="{{ route('billing.outages.review', $period) }}" method="POST">
        @csrf
        <fieldset @disabled($locked)>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" aria-label="Pilih semua baris" onchange="this.form.querySelectorAll('input[name=&quot;outage_ids[]&quot;]').forEach((box) => box.checked = this.checked)"></th>
                            <th>SSID / sumber</th><th>Jenis / waktu</th><th>Detik</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($outages as $outage)
                            <tr>
                                <td><input type="checkbox" name="outage_ids[]" value="{{ $outage->id }}"></td>
                                <td>{{ $outage->sid }}<p class="text-xs text-gray-500">{{ $outage->ticket }} · {{ $outage->source['sheet'] ?? ($outage->source['origin'] ?? 'manual') }} {{ $outage->source['row'] ?? '' }}</p></td>
                                <td>{{ $outage->kind }}@if ($outage->opened_at)<p class="text-xs">{{ $outage->opened_at }} → {{ $outage->closed_at }}</p>@endif</td>
                                <td>{{ $outage->duration_seconds === null ? '—' : number_format($outage->duration_seconds, 0, ',', '.') }}</td>
                                <td>{{ $decisionLabels[$outage->review_status] ?? $outage->review_status }}<p class="text-xs text-gray-500">{{ $outage->reason }}</p></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-gray-500">Belum ada catatan downtime.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($outages->isNotEmpty())
                <div class="flex flex-wrap gap-3 mt-4">
                    <select name="decision" class="input w-auto">
                        @foreach ($decisionLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input class="input flex-1" name="reason" required placeholder="Alasan keputusan dan rujukan review">
                    <button class="btn btn-primary">Simpan untuk baris terpilih</button>
                </div>
            @endif
        </fieldset>
    </form>
    <div class="mt-3">{{ $outages->links() }}</div>
</div>

@unless ($locked)
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <details class="card rounded-xl p-6">
            <summary class="font-semibold cursor-pointer">Deklarasi cakupan sumber downtime (interval)</summary>
            <form method="POST" action="{{ route('billing.coverage', $period) }}" class="mt-4 space-y-3">
                @csrf
                <p class="text-sm text-gray-500">Gunakan bila file downtime interval sudah direview dan mencakup seluruh bulan untuk SSID tertentu. SSID tanpa gangguan pada cakupan ini dianggap 0 detik.</p>
                <select name="batch_id" class="input" required>
                    <option value="">Pilih file downtime</option>
                    @foreach ($batches->where('status', 'completed') as $b)
                        <option value="{{ $b->id }}">{{ $b->original_filename }}</option>
                    @endforeach
                </select>
                <textarea class="input" name="sids" placeholder="SSID yang tercakup, satu per baris"></textarea>
                <label class="block text-sm"><input type="checkbox" name="all_services" value="1"> File mencakup semua layanan periode ini</label>
                <input class="input" name="reason" required placeholder="Alasan dan rujukan sumber">
                <button class="btn btn-primary">Konfirmasi cakupan</button>
            </form>
        </details>
        <script>
            // Fills the adjustment form from the price problem list (nothing is submitted).
            window.sijOverrideBox = ({ fills, hints, initial }) => ({
                fills,
                hints,
                form: initial,
                filled: null,
                get hint() {
                    const sid = (this.form.sid || '').trim();
                    return Object.hasOwn(this.hints, sid) ? this.hints[sid] : '';
                },
                isFilled(sid) {
                    return this.filled === sid && (this.form.sid || '').trim() === sid;
                },
                fill(sid, choice) {
                    const data = this.fills[sid]?.[choice];
                    if (!data) return;
                    Object.assign(this.form, { sid, kind: 'tariff_override', amount: data.amount, reference: data.reference, reason: data.reason });
                    this.filled = sid;
                    this.$nextTick(() => {
                        this.$refs.overrideForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        const reason = this.$refs.reason;
                        reason.focus({ preventScroll: true });
                        reason.setSelectionRange(reason.value.length, reason.value.length);
                    });
                },
            });
        </script>
        <details class="card rounded-xl p-6 {{ $priceProblems ? 'lg:col-span-2' : '' }}" @if ($priceProblems) open @endif
            x-data="sijOverrideBox({ fills: @js((object) $fills), hints: @js((object) $hints), initial: @js(['sid' => (string) old('sid', ''), 'kind' => (string) old('kind', 'manual_credit'), 'amount' => (string) old('amount', ''), 'reference' => (string) old('reference', ''), 'reason' => (string) old('reason', '')]) })">
            <summary class="font-semibold cursor-pointer">Kredit manual / override harga</summary>

            @if ($priceProblems)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold text-gray-900">SSID yang perlu dicek harganya ({{ count($priceProblems) }})</h3>
                            <p class="text-sm text-gray-700 mt-1">Harga di workbook Telkom berbeda dengan tarif sistem, atau tarifnya belum ada. Pilih harga yang benar lalu simpan; atau selesaikan sekaligus di langkah 2.</p>
                        </div>
                        <a class="btn btn-secondary whitespace-nowrap" href="{{ route('invoice-periods.step2', $period) }}">Buka langkah 2</a>
                    </div>
                    @unless ($calculationCurrent)
                        <p class="mt-3 rounded-md border border-amber-300 bg-amber-100 px-3 py-2 text-sm text-amber-900">Daftar ini dari perhitungan terakhir. Hitung ulang di langkah 2 untuk daftar terbaru.</p>
                    @endunless
                    <div class="overflow-auto max-h-96 mt-3 rounded-lg border border-amber-100 bg-white">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>SSID</th><th>Data sistem</th><th class="text-right">Harga Telkom</th><th class="text-right">Harga sistem</th><th class="text-right">Selisih</th><th>Penyebab</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($priceProblems as $problem)
                                    @php($diff = (float) ($problem['difference'] ?? 0))
                                    <tr class="align-top" x-bind:class="{ 'bg-yellow-50': isFilled(@js($problem['sid'])) }">
                                        <td class="min-w-[10rem]">
                                            <span class="font-semibold">{{ $problem['sid'] }}</span>
                                            @if ($problem['branch'])<span class="block text-xs text-gray-500">Cabang {{ $problem['branch'] }}</span>@endif
                                            @if ($problem['address'])<span class="block text-xs text-gray-500" title="{{ $problem['address'] }}">{{ \Illuminate\Support\Str::limit($problem['address'], 50) }}</span>@endif
                                        </td>
                                        <td class="min-w-[9rem]">
                                            <span class="block">{{ $problem['bandwidth'] ?? '—' }}</span>
                                            @if ($problem['category'] || $problem['router_label'])<span class="block text-xs text-gray-500">{{ implode(' · ', array_filter([$problem['category'], $problem['router_label']])) }}</span>@endif
                                        </td>
                                        <td class="text-right whitespace-nowrap font-semibold">{{ $problem['reference'] !== null ? $rupiah($problem['reference']) : '—' }}</td>
                                        <td class="text-right whitespace-nowrap">{{ $problem['system'] !== null ? $rupiah($problem['system']) : '—' }}</td>
                                        <td class="text-right whitespace-nowrap font-semibold {{ $diff > 0 ? 'text-amber-700' : ($diff < 0 ? 'text-red-600' : 'text-gray-500') }}">{{ $problem['difference'] !== null ? $signed($diff) : '—' }}</td>
                                        <td class="min-w-[14rem] text-sm">
                                            {{ $problem['explanation'] }}
                                            @if ($problem['stale'])<span class="block text-xs text-gray-500 mt-1">Klik Hitung ulang di langkah 2 untuk melihat penyebabnya.</span>@endif
                                            @if ($problem['source_text'])<span class="block text-xs text-gray-500 mt-1">Sumber: {{ $problem['source_text'] }}</span>@endif
                                        </td>
                                        <td class="min-w-[9rem]">
                                            @if (isset($overridden[$problem['sid']]))
                                                <span class="badge-gray whitespace-nowrap">Sudah ada override</span>
                                            @elseif ($problem['code'] === 'tariff')
                                                <a class="text-sm text-[#E6002D] hover:underline" href="{{ route('price-masters.index', ['section' => 'tariffs']) }}">Tambah tarif di Master Harga</a>
                                            @elseif (isset($fills[$problem['sid']]))
                                                <div class="flex flex-col items-stretch gap-1.5">
                                                    <button type="button" class="btn btn-secondary px-2 py-1 text-xs whitespace-nowrap" x-on:click="fill(@js($problem['sid']), 'source')">Isi: harga Telkom</button>
                                                    <button type="button" class="btn btn-secondary px-2 py-1 text-xs whitespace-nowrap" x-on:click="fill(@js($problem['sid']), 'system')">Isi: harga sistem</button>
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-500">Isi manual di form bawah.</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($result)
                <p class="mt-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">Tidak ada SSID dengan masalah harga.</p>
                @unless ($calculationCurrent)
                    <p class="mt-2 rounded-md border border-amber-300 bg-amber-100 px-3 py-2 text-sm text-amber-900">Daftar ini dari perhitungan terakhir. Hitung ulang di langkah 2 untuk daftar terbaru.</p>
                @endunless
            @endif

            <form x-ref="overrideForm" method="POST" action="{{ route('billing.adjustments', $period) }}" class="mt-4 space-y-3 {{ $priceProblems ? 'max-w-2xl' : '' }}">
                @csrf
                <div>
                    <input class="input" name="sid" required placeholder="SSID" x-model="form.sid">
                    <p class="mt-1 text-xs text-amber-800" x-show="hint" x-cloak x-text="hint"></p>
                </div>
                <select class="input" name="kind" x-model="form.kind">
                    <option value="manual_credit">Kredit manual (mengurangi tagihan)</option>
                    <option value="tariff_override">Override tarif bulanan periode ini</option>
                </select>
                <input class="input" name="amount" required placeholder="Jumlah, misalnya 19150.54" x-model="form.amount">
                <input class="input" name="installation_price" placeholder="Biaya instalasi override (opsional)">
                <input class="input" name="relocation_price" placeholder="Biaya relokasi override (opsional, 0 boleh)">
                <input class="input" name="reference" required placeholder="Referensi pendukung" x-model="form.reference">
                <textarea class="input" name="reason" required placeholder="Alasan penyesuaian" x-ref="reason" x-model="form.reason"></textarea>
                <button class="btn btn-primary">Simpan penyesuaian</button>
            </form>
        </details>
    </div>
@endunless

<div class="card rounded-xl p-6 mb-6">
    <h2 class="font-semibold text-lg mb-3">Perubahan layanan & penyesuaian periode ini</h2>
    @forelse ($events as $event)
        <p class="text-sm py-2 border-t border-gray-100">
            {{ $event->sid }} · {{ \App\Http\Controllers\BillingWorkflowController::EVENT_LABELS[$event->event_type] ?? $event->event_type }} · {{ $event->effective_date->format('d/m/Y') }}
            <span class="text-gray-500">· {{ ['operator' => 'input operator', 'scheduled' => 'dari jadwal'][$event->source['origin'] ?? ''] ?? (($event->source['sheet'] ?? 'file').' '.($event->source['row'] ?? '')) }}</span>
        </p>
    @empty
        <p class="text-sm text-gray-500">Belum ada perubahan layanan.</p>
    @endforelse
    @foreach ($adjustments as $adjustment)
        <div class="py-2 border-t border-gray-100 text-sm">
            <p>{{ $adjustment->sid }} · {{ $adjustment->kind === 'manual_credit' ? 'Kredit manual' : 'Override tarif' }} · <x-sij.money :value="$adjustment->amount" /> · {{ $adjustment->reason }} · {{ $adjustment->reference }}</p>
            @unless ($locked)
                <form method="POST" action="{{ route('billing.adjustments.remove', [$period, $adjustment]) }}" class="flex gap-2 mt-2">
                    @csrf
                    <input class="input" name="reason" required placeholder="Alasan menarik penyesuaian">
                    <button class="btn btn-secondary whitespace-nowrap">Tarik penyesuaian</button>
                </form>
            @endunless
        </div>
    @endforeach
</div>

@if ($result)
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="card rounded-xl p-6">
            <h2 class="font-semibold text-lg mb-3">Total per cabang</h2>
            @foreach ($result['totals']['branch_totals'] ?? [] as $branch => $t)
                <p class="flex justify-between py-1 text-sm border-b border-gray-100"><span>{{ $branch }} · {{ $t['count'] }} SSID</span><x-sij.money :value="$t['net']" /></p>
            @endforeach
            <p class="text-sm mt-3">Selisih dengan invoice terbit sebelumnya: <x-sij.money :value="$result['variance'] ?? null" empty="Belum ada pembanding" /></p>
        </div>
        <div class="card rounded-xl p-6">
            <h2 class="font-semibold text-lg mb-3">Semua catatan perhitungan ({{ $issues->count() }})</h2>
            <div class="max-h-80 overflow-auto text-sm">
                @forelse ($issues as $issue)
                    <p class="py-1.5 border-b border-gray-100">{{ $issue['sid'] ?? 'Periode' }} · {{ $issue['message'] }} <span class="text-gray-500">({{ $issue['severity'] }}) {{ $issue['reason'] }}</span></p>
                @empty
                    <p class="text-gray-500">Tidak ada catatan.</p>
                @endforelse
            </div>
        </div>
    </div>

    @if (! empty($result['changes']))
        <div class="card rounded-xl p-6 mb-6">
            <h2 class="font-semibold text-lg mb-3">Perbedaan dengan invoice terbit sebelumnya</h2>
            <div class="max-h-80 overflow-auto">
                <table class="table">
                    <thead><tr><th>SSID</th><th>Perubahan</th><th>Bandwidth</th><th class="text-right">Total sebelumnya</th><th class="text-right">Total sekarang</th></tr></thead>
                    <tbody>
                        @foreach ($result['changes'] as $change)
                            <tr>
                                <td>{{ $change['sid'] }}</td>
                                <td>{{ ['added' => 'Baru', 'changed' => 'Berubah', 'removed' => 'Tidak ada lagi'][$change['type']] ?? $change['type'] }}</td>
                                <td>{{ $change['previous_bandwidth'] ?? '—' }} → {{ $change['current_bandwidth'] ?? '—' }}</td>
                                <td class="text-right"><x-sij.money :value="$change['previous_net']" /></td>
                                <td class="text-right"><x-sij.money :value="$change['current_net']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif

<div class="card rounded-xl p-6">
    <h2 class="font-semibold text-lg mb-3">Aktivitas terbaru</h2>
    @forelse ($activities as $activity)
        <p class="text-sm border-t border-gray-100 py-2">{{ \Illuminate\Support\Carbon::parse($activity->created_at)->format('d/m/Y H:i') }} · {{ str_replace('_', ' ', $activity->action) }}</p>
    @empty
        <p class="text-sm text-gray-500">Belum ada aktivitas.</p>
    @endforelse
</div>
@endsection
