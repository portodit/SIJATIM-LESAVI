@extends('layouts.sijatim')

@php
    $locked = $period->isLocked();
    $human = function (?int $seconds): string {
        if ($seconds === null) {
            return '';
        }
        $parts = array_filter([
            intdiv($seconds, 86400) ? intdiv($seconds, 86400).' hari' : null,
            intdiv($seconds % 86400, 3600).' jam',
            intdiv($seconds % 3600, 60).' menit',
            $seconds % 60 ? ($seconds % 60).' detik' : null,
        ]);

        return implode(' ', $parts);
    };
    $chips = [
        'all' => 'Semua',
        'changed' => 'Ada perubahan',
        'downtime' => 'Ada downtime',
        'unconfirmed' => 'Downtime belum diisi / perlu diisi ulang',
        'scheduled' => 'Terjadwal bulan depan',
        'conflict' => 'Downtime bentrok',
        'draft_order' => 'Status draf lama',
        'relocation' => 'Alamat relokasi belum diisi',
    ];
@endphp

@section('content')
<script>
    window.sijStep1 = (rows) => ({
        modal: null,
        rows,
        current: null,
        changeType: '',
        downtime: '',
        open(name, sid = null) {
            this.current = sid ? this.rows[sid] : null;
            // An old draft type order (e.g. Relokasi without a date) preselects that change.
            this.changeType = name === 'change' && ['upgrade', 'downgrade', 'relokasi', 'pemutusan'].includes(this.current?.draft_order) ? this.current.draft_order : '';
            this.downtime = this.current?.downtime ?? '';
            this.modal = name;
        },
        human(value) {
            const sec = parseInt(value, 10);
            if (isNaN(sec) || sec < 0) return '';
            const d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
            return [d ? d + ' hari' : null, h + ' jam', m + ' menit', s ? s + ' detik' : null].filter(Boolean).join(' ');
        },
    });
</script>

<div x-data="sijStep1(@js($rows->keyBy('sid')))" @if ($errors->any() && old('ssid')) x-init="open('psb')" @elseif ($errors->hasAny(['downtime_seconds', 'choice']) && old('sid')) x-init="open('downtime', @js(old('sid')))" @elseif ($errors->has('new_address') && old('sid')) x-init="open('relocation', @js(old('sid')))" @endif>
    <x-sij.period-header :period="$period" :step="1" />

    {{-- Info invoice --}}
    <div class="card rounded-xl p-5 mb-6 flex flex-wrap items-center justify-between gap-4">
        <dl class="flex flex-wrap gap-x-10 gap-y-2 text-sm">
            <div><dt class="text-gray-500">Nomor invoice</dt><dd class="font-semibold">{{ $metadata['invoice_number'] ?? 'Belum diisi' }}</dd></div>
            <div><dt class="text-gray-500">Tanggal terbit</dt><dd class="font-semibold">{{ isset($metadata['issue_date']) ? \Illuminate\Support\Carbon::parse($metadata['issue_date'])->format('d/m/Y') : '—' }}</dd></div>
            <div><dt class="text-gray-500">Jatuh tempo</dt><dd class="font-semibold">{{ isset($metadata['due_date']) ? \Illuminate\Support\Carbon::parse($metadata['due_date'])->format('d/m/Y') : '—' }}</dd></div>
            <div><dt class="text-gray-500">Pelanggan</dt><dd class="font-semibold">{{ $metadata['customer_name'] ?? 'Belum diisi di Master Harga' }}</dd></div>
        </dl>
        @unless ($locked)
            <button type="button" class="btn btn-secondary" @click="open('invoice')">Ubah info invoice</button>
        @endunless
    </div>

    {{-- Scheduled changes for this month whose SSID is not in the period yet --}}
    @if ($waiting->isNotEmpty() && ! $locked)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <p class="font-semibold">{{ $waiting->count() }} perubahan terjadwal untuk bulan ini belum masuk</p>
            <p class="mt-1">SSID berikut belum ada di daftar layanan periode ini: {{ $waiting->pluck('sid')->unique()->implode(', ') }}. Tambahkan layanannya, lalu klik tombol di bawah.</p>
            <form method="POST" action="{{ route('billing.scheduled.apply', $period) }}" class="mt-3">
                @csrf
                <button class="btn btn-secondary">Masukkan perubahan terjadwal</button>
            </form>
        </div>
    @endif

    @if ($counts['all'] === 0)
        {{-- Empty period --}}
        <div class="card rounded-xl p-10 text-center">
            <h2 class="text-xl font-semibold">Belum ada layanan di periode ini</h2>
            <p class="text-gray-500 mt-2">Pilih salah satu cara untuk mengisi daftar SSID. Semua SSID otomatis berstatus <strong>Existing</strong>.</p>
            @unless ($locked)
                <div class="flex flex-wrap justify-center gap-4 mt-8">
                    <form method="POST" action="{{ route('billing.start-previous', $period) }}">
                        @csrf
                        <button class="btn btn-primary px-6 py-3 text-base" @disabled(! $previous)>Salin dari tagihan bulan lalu</button>
                        <p class="text-xs text-gray-500 mt-2">{{ $previous ? 'Dari invoice terbit terakhir.' : 'Belum ada invoice terbit sebelumnya.' }}</p>
                    </form>
                    <form method="POST" action="{{ route('billing.inventory-all', $period) }}">
                        @csrf
                        <button class="btn btn-secondary px-6 py-3 text-base" @disabled(! $masterCount)>Ambil semua SSID aktif dari Master</button>
                        <p class="text-xs text-gray-500 mt-2">{{ number_format($masterCount, 0, ',', '.') }} SSID aktif di Master.</p>
                    </form>
                    <div>
                        <button type="button" class="btn btn-secondary px-6 py-3 text-base" @click="open('history')">Isi dari workbook Telkom</button>
                        <p class="text-xs text-gray-500 mt-2 max-w-xs">Untuk bulan yang sudah lewat: daftar SSID diambil dari workbook Telkom bulan itu. Master tidak diubah.</p>
                    </div>
                </div>
            @endunless
        </div>
    @else
        @if ($counts['relocation'] > 0 && ! $locked)
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber-300 bg-amber-50 p-5" role="alert">
                <div>
                    <p class="font-semibold text-amber-900">{{ $counts['relocation'] }} SSID ditandai relokasi di tagihan {{ $previousName }}</p>
                    <p class="mt-1 text-sm text-amber-900">Isi alamat barunya. Invoice bulan ini belum bisa diterbitkan sebelum semua alamat baru diisi.</p>
                </div>
                @if ($filter !== 'relocation')
                    <a class="btn btn-primary" href="{{ route('invoice-periods.step1', [$period, 'filter' => 'relocation']) }}">Tampilkan SSID relokasi</a>
                @endif
            </div>
        @endif
        @if ($counts['conflict'] > 0)
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-red-300 bg-red-50 p-5" role="alert">
                <div>
                    <p class="font-semibold text-red-800">Bentrok: {{ number_format($counts['conflict'], 0, ',', '.') }} SSID punya lebih dari satu catatan downtime</p>
                    <p class="mt-1 text-sm text-red-900">
                        Biasanya karena downtime diisi manual lalu diupload lagi lewat Excel. Invoice belum bisa dihitung sampai Anda memilih nilai yang benar untuk setiap SSID.
                    </p>
                </div>
                @if ($filter !== 'conflict')
                    <a class="btn btn-primary" href="{{ route('invoice-periods.step1', [$period, 'filter' => 'conflict']) }}">Tampilkan yang bentrok</a>
                @endif
            </div>
        @endif
        <div class="card rounded-xl p-6 mb-6">
            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-lg">Layanan periode ini · {{ number_format($counts['all'], 0, ',', '.') }} SSID</h2>
                    <p class="text-sm text-gray-500">Semua SSID dianggap <strong>Existing</strong>. Klik <strong>Ubah Layanan</strong> hanya untuk SSID yang berubah.</p>
                </div>
                @unless ($locked)
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary" @click="open('history')">Sesuaikan dengan workbook Telkom</button>
                        <button type="button" class="btn btn-secondary" @click="open('upload')">Upload downtime (Excel)</button>
                        <button type="button" class="btn btn-primary" @click="open('psb')">+ SSID baru (PSB)</button>
                    </div>
                @endunless
            </div>

            {{-- Searching or switching filter keeps the chosen column sort. --}}
            @php($keepSort = array_filter(request()->only('sort', 'dir'), 'is_string'))
            <form method="GET" action="{{ route('invoice-periods.step1', $period) }}" class="flex flex-wrap gap-2 mt-5">
                @if ($filter !== 'all')
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif
                @foreach ($keepSort as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <input class="input flex-1 min-w-[16rem]" name="search" value="{{ $search }}" placeholder="Cari SSID atau alamat">
                <button class="btn btn-secondary">Cari</button>
                @if ($search !== '')
                    <a class="btn btn-ghost" href="{{ route('invoice-periods.step1', array_filter([$period, 'filter' => $filter === 'all' ? null : $filter]) + $keepSort) }}">Hapus pencarian</a>
                @endif
            </form>

            <div class="flex flex-wrap gap-2 mt-4">
                @foreach ($chips as $key => $label)
                    @continue(in_array($key, ['conflict', 'draft_order', 'relocation'], true) && ! $counts[$key] && $filter !== $key)
                    <a href="{{ route('invoice-periods.step1', array_filter([$period, 'filter' => $key === 'all' ? null : $key, 'search' => $search ?: null]) + $keepSort) }}"
                       class="rounded-full border px-3 py-1 text-sm {{ $filter === $key ? 'border-[#E6002D] bg-red-50 text-[#E6002D] font-semibold' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                        {{ $label }} <span class="text-xs">({{ number_format($counts[$key], 0, ',', '.') }})</span>
                    </a>
                @endforeach
            </div>

            {{-- Services table --}}
            <div class="overflow-x-auto mt-5">
                <table class="table">
                    <thead>
                        <tr>
                            <x-sij.sort-th sort="number" class="w-12">No</x-sij.sort-th>
                            <x-sij.sort-th sort="sid">SSID</x-sij.sort-th>
                            <x-sij.sort-th sort="category">Kategori</x-sij.sort-th>
                            <x-sij.sort-th sort="branch">Cabang · Alamat</x-sij.sort-th>
                            <x-sij.sort-th sort="bandwidth" dir="desc">Bandwidth</x-sij.sort-th>
                            <x-sij.sort-th sort="status">Status</x-sij.sort-th>
                            <th>Tanggal efektif</th>
                            <x-sij.sort-th sort="downtime" dir="desc">Downtime</x-sij.sort-th>
                            <x-sij.sort-th sort="sla" dir="desc">SLA</x-sij.sort-th>
                            @unless ($locked)<th class="text-right">Aksi</th>@endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="align-top">
                                <td class="text-gray-500">{{ $row['number'] }}</td>
                                <td>
                                    <span class="font-semibold">{{ $row['sid'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $row['service'] }}</span>
                                </td>
                                <td class="whitespace-nowrap">{{ $row['category'] ?: '—' }}</td>
                                <td class="min-w-[16rem] max-w-md">
                                    <span class="block text-xs font-medium text-gray-600">Cabang {{ $row['branch'] }}</span>
                                    <span class="block text-sm line-clamp-2">{{ $row['address'] }}</span>
                                </td>
                                <td class="whitespace-nowrap">{{ $row['bandwidth'] }}</td>
                                <td class="min-w-[9rem]">
                                    <x-sij.status-badge :status="$row['status']" />
                                    @if ($row['relocation'] === 'pending' && ! $locked)
                                        <div class="mt-1 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
                                            Relokasi bulan lalu: alamat baru belum diisi.
                                            <button type="button" class="block font-semibold underline" @click="open('relocation', @js($row['sid']))">Isi alamat baru</button>
                                        </div>
                                    @elseif ($row['relocation'] === 'done')
                                        <span class="block text-xs text-gray-600 mt-1">Alamat baru (relokasi) sudah diisi
                                            @unless ($locked)<button type="button" class="text-[#E6002D] underline" @click="open('relocation', @js($row['sid']))">Ubah</button>@endunless
                                        </span>
                                    @elseif ($row['relocation'] === 'flagged')
                                        <span class="block text-xs text-gray-600 mt-1">Alamat baru diisi di tagihan {{ $period->next_period_name }}</span>
                                    @endif
                                    @if ($row['draft_order'] && ! $locked)
                                        <div class="mt-1 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
                                            Draf lama: <strong>{{ $row['draft_order_label'] ?? $row['draft_order'] }}</strong> tanpa tanggal.
                                            <div class="mt-1 flex flex-wrap gap-x-3">
                                                <button type="button" class="font-semibold underline" @click="open('change', @js($row['sid']))">Catat perubahan</button>
                                                <form method="POST" action="{{ route('billing.mark-existing', $period) }}">
                                                    @csrf
                                                    <input type="hidden" name="sid" value="{{ $row['sid'] }}">
                                                    <button class="font-semibold underline">Tandai Existing</button>
                                                </form>
                                            </div>
                                        </div>
                                    @endif
                                    @if (count($row['events']) > 1)
                                        <span class="block text-xs text-gray-500 mt-1">{{ count($row['events']) }} perubahan</span>
                                    @endif
                                    @foreach ($row['scheduled'] as $change)
                                        <span class="block text-xs text-gray-600 mt-1">
                                            <x-sij.status-badge status="scheduled" /> {{ $change['label'] }}
                                        </span>
                                    @endforeach
                                </td>
                                <td class="whitespace-nowrap text-sm">
                                    @php($dated = count($row['events']) + count($row['scheduled']))
                                    @foreach ($row['events'] as $event)
                                        <span class="block">
                                            @if ($dated > 1)<span class="text-xs text-gray-500">{{ $event['label'] }}</span>@endif
                                            @if ($event['date'] === 'tanpa tanggal')<span class="text-gray-500">tanpa tanggal</span>@else{{ $event['date'] }}@endif
                                        </span>
                                    @endforeach
                                    @foreach ($row['scheduled'] as $change)
                                        <span class="block text-gray-600">
                                            @if ($dated > 1)<span class="text-xs text-gray-500">{{ $change['label'] }}</span>@endif
                                            {{ $change['date'] }} <span class="text-xs text-gray-500">(terjadwal)</span>
                                        </span>
                                    @endforeach
                                    @if ($dated === 0)<span class="text-gray-300">—</span>@endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($row['downtime_state'] === 'conflict')
                                        <span class="badge-danger">Bentrok</span>
                                        <span class="block text-xs text-red-600">{{ $row['downtime_records'] }} catatan · pilih satu</span>
                                    @elseif ($row['downtime_state'] === 'set')
                                        <span class="font-medium">{{ number_format($row['downtime'], 0, ',', '.') }} detik</span>
                                        @if ($row['downtime'])
                                            <span class="block text-xs text-gray-500">{{ $human($row['downtime']) }}</span>
                                        @endif
                                    @elseif ($row['downtime_state'] === 'stale')
                                        <span class="badge-warning">Isi ulang</span>
                                        <span class="block text-xs text-amber-700">masa aktif berubah</span>
                                    @elseif ($row['downtime_state'] === 'review')
                                        <span class="badge-warning">Perlu dicek</span>
                                    @else
                                        <span class="text-gray-400">Belum diisi</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($row['sla'] === null)
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <span class="{{ $row['sla'] < 99 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($row['sla'], 2, ',', '.') }}%</span>
                                    @endif
                                </td>
                                @unless ($locked)
                                    <td class="text-right whitespace-nowrap">
                                        <button type="button" class="btn btn-secondary py-1.5" @click="open('change', @js($row['sid']))">Ubah Layanan</button>
                                        <button type="button" class="btn {{ $row['downtime_state'] === 'conflict' ? 'btn-primary' : 'btn-secondary' }} py-1.5" @click="open('downtime', @js($row['sid']))">{{ $row['downtime_state'] === 'conflict' ? 'Pilih nilai' : 'Downtime' }}</button>
                                    </td>
                                @endunless
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="py-10 text-center text-gray-500">Tidak ada SSID yang cocok dengan pencarian atau filter ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $lines->links() }}</div>
        </div>

        {{-- Finish step --}}
        <div class="card rounded-xl p-6 flex flex-wrap items-center justify-between gap-6">
            @unless ($locked)
                <form method="POST" action="{{ route('billing.downtime.confirm-zero', $period) }}" class="max-w-xl">
                    @csrf
                    <input type="hidden" name="expected_count" value="{{ $zeroConfirmCount }}">
                    <button class="btn btn-secondary" @disabled(! $zeroConfirmCount)>Konfirmasi {{ number_format($zeroConfirmCount, 0, ',', '.') }} SSID lainnya tanpa downtime</button>
                    <p class="text-xs text-gray-500 mt-2">
                        Klik setelah semua downtime diisi. SSID yang downtime-nya masih kosong dicatat 0 detik (SLA 100%). Downtime yang sudah diisi tidak berubah.
                    </p>
                </form>
            @endunless
            <a class="btn btn-primary px-6 py-3 text-base ml-auto" href="{{ route('invoice-periods.step2', $period) }}">Lanjut: Periksa & Hitung &rarr;</a>
        </div>
    @endif

    @unless ($locked)
        {{-- Modal: change service --}}
        <x-sij.modal name="change" title-expr="'Ubah Layanan · ' + (current?.sid ?? '')" width="max-w-xl">
            <template x-if="current">
                <div>
                    <p class="text-sm text-gray-600">
                        Sekarang: <strong x-text="current.bandwidth"></strong> · <span x-text="current.address"></span>
                    </p>
                    <form method="POST" action="{{ route('billing.service-events', $period) }}" class="mt-5 space-y-4">
                        @csrf
                        <input type="hidden" name="sid" :value="current.sid">
                        <fieldset>
                            <legend class="text-sm font-medium mb-2">Jenis perubahan</legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (['upgrade' => ['Upgrade', 'Bandwidth naik'], 'downgrade' => ['Downgrade', 'Bandwidth turun'], 'relokasi' => ['Relokasi', 'Pindah alamat (tanpa tanggal)'], 'pemutusan' => ['Cabut', 'Layanan berhenti']] as $type => [$label, $hint])
                                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3"
                                           :class="changeType === '{{ $type }}' ? 'border-[#E6002D] bg-red-50' : 'border-gray-300'">
                                        <input type="radio" name="event_type" value="{{ $type }}" x-model="changeType" required class="mt-1 text-[#E6002D] focus:ring-[#E6002D]">
                                        <span><span class="block font-medium">{{ $label }}</span><span class="block text-xs text-gray-500">{{ $hint }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label class="block text-sm font-medium" x-show="changeType !== 'relokasi'">
                            Tanggal berlaku
                            <input type="date" name="effective_date" class="input mt-1" min="{{ $period->start_date->toDateString() }}"
                                   :required="changeType !== 'relokasi'" :disabled="changeType === 'relokasi'">
                            <span class="block text-xs font-normal text-gray-500 mt-1">
                                Tanggal dalam {{ $period->usage_name }} masuk ke tagihan ini. Tanggal setelah {{ $period->end_date->format('d/m/Y') }} otomatis dijadwalkan ke tagihan bulan berikutnya.
                            </span>
                        </label>
                        <label class="block text-sm font-medium" x-show="['upgrade', 'downgrade'].includes(changeType)">
                            Bandwidth baru
                            <select name="bandwidth_option_id" class="input mt-1"
                                    :disabled="! ['upgrade', 'downgrade'].includes(changeType)"
                                    :required="['upgrade', 'downgrade'].includes(changeType)">
                                <option value="">Pilih bandwidth</option>
                                @foreach ($bandwidths as $option)
                                    <option value="{{ $option->id }}">{{ $option->bandwidth_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="rounded-lg bg-purple-50 p-3 text-xs text-purple-900" x-show="changeType === 'relokasi'">
                            Relokasi tidak memakai tanggal. Tagihan bulan ini tetap dihitung penuh. Alamat baru wajib diisi di tagihan {{ $period->next_period_name }}.
                        </p>
                        <p class="rounded-lg bg-red-50 p-3 text-xs text-red-800" x-show="changeType === 'pemutusan'">
                            Tagihan dihitung sampai tanggal ini. Mulai bulan berikutnya SSID ini tidak ikut disalin lagi.
                        </p>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                            <button class="btn btn-primary">Simpan perubahan</button>
                        </div>
                    </form>

                    <div class="mt-6 border-t border-gray-200 pt-4" x-show="current.events.length || current.scheduled.length">
                        <h3 class="text-sm font-semibold mb-2">Riwayat perubahan SSID ini</h3>
                        <template x-for="(item, index) in [...current.events, ...current.scheduled]" :key="index">
                            <div class="border-b border-gray-100 py-2 text-sm" x-data="{ confirm: false }">
                                <div class="flex items-center justify-between gap-3">
                                    <span>
                                        <strong x-text="item.label"></strong> · <span x-text="item.date"></span>
                                        <span class="text-gray-500" x-show="item.detail" x-text="'· ' + item.detail"></span>
                                        <span class="block text-xs text-gray-500" x-show="item.target" x-text="'Terjadwal untuk tagihan ' + item.target"></span>
                                    </span>
                                    <button type="button" class="text-xs text-red-600 hover:underline" @click="confirm = ! confirm">Batalkan</button>
                                </div>
                                <form method="POST" :action="item.cancel_url" class="mt-2 flex gap-2" x-show="confirm">
                                    @csrf
                                    <input class="input" name="reason" required minlength="3" placeholder="Alasan pembatalan">
                                    <button class="btn btn-secondary whitespace-nowrap">Ya, batalkan</button>
                                </form>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </x-sij.modal>

        {{-- Modal: downtime --}}
        <x-sij.modal name="downtime" title-expr="'Downtime · ' + (current?.sid ?? '')">
            <template x-if="current">
                <div>
                    {{-- Several competing records: choose the right one --}}
                    <template x-if="current.downtime_state === 'conflict' && current.downtime_choosable">
                        <form method="POST" action="{{ route('billing.downtime.choose', $period) }}" class="space-y-4" x-data="{ pick: '', seconds: '' }">
                            @csrf
                            <input type="hidden" name="sid" :value="current.sid">
                            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                                <p class="font-semibold">Bentrok: ada <span x-text="current.downtime_records"></span> catatan downtime untuk SSID ini.</p>
                                <p class="mt-1">Hanya satu yang boleh dipakai. Pilih yang benar. Catatan lain tidak dihitung, tetapi tidak dihapus.</p>
                            </div>
                            <div class="space-y-2">
                                <template x-for="option in current.downtime_options" :key="option.id">
                                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3"
                                           :class="String(pick) === String(option.id) ? 'border-[#E6002D] bg-red-50' : 'border-gray-300'">
                                        <input type="radio" name="choice" class="mt-1 text-[#E6002D] focus:ring-[#E6002D]" :value="option.id" x-model="pick" required>
                                        <span>
                                            <span class="block font-medium" x-text="option.value"></span>
                                            <span class="block text-xs text-gray-500" x-text="option.source"></span>
                                        </span>
                                    </label>
                                </template>
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3"
                                       :class="pick === 'new' ? 'border-[#E6002D] bg-red-50' : 'border-gray-300'">
                                    <input type="radio" name="choice" class="mt-1 text-[#E6002D] focus:ring-[#E6002D]" value="new" x-model="pick" required>
                                    <span class="font-medium">Semuanya salah, isi angka lain</span>
                                </label>
                                <div x-show="pick === 'new'">
                                    <input type="number" name="downtime_seconds" class="input" inputmode="numeric" min="0" step="1" :max="current.active_seconds"
                                           x-model="seconds" :required="pick === 'new'" :disabled="pick !== 'new'" placeholder="Detik, contoh: 3600">
                                    <p class="mt-1 text-sm" x-show="seconds !== ''">= <strong x-text="human(seconds)"></strong></p>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 pt-2">
                                <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                                <button class="btn btn-primary">Simpan pilihan</button>
                            </div>
                        </form>
                    </template>

                    {{-- Conflict that includes unreviewed or interval records: expert page --}}
                    <template x-if="current.downtime_state === 'conflict' && ! current.downtime_choosable">
                        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                            <p class="font-semibold">Bentrok: ada <span x-text="current.downtime_records"></span> catatan downtime, sebagian belum direview atau berupa interval.</p>
                            <p class="mt-1">Buka <a class="font-semibold underline" :href="current.review_url">Menu Lanjutan › Review downtime</a>, centang yang tidak dipakai, pilih <strong>Tidak dihitung</strong>, lalu simpan.</p>
                        </div>
                    </template>

                    {{-- One record or none: normal entry --}}
                    <template x-if="current.downtime_state !== 'conflict'">
                        <div>
                            <template x-if="current.downtime_state === 'stale'">
                                <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                                    Masa aktif layanan berubah (PSB/Cabut) menjadi <strong x-text="current.window_label"></strong>.
                                    Downtime lama diisi untuk masa yang berbeda, jadi periksa angkanya lalu simpan lagi.
                                </p>
                            </template>
                            <template x-if="current.downtime_state === 'review'">
                                <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                                    Catatan downtime SSID ini belum disetujui atau berupa interval. Selesaikan di
                                    <a class="font-semibold underline" :href="current.review_url">Menu Lanjutan › Review downtime</a>.
                                </p>
                            </template>
                            <template x-if="current.downtime_from_file">
                                <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                                    Nilai saat ini berasal dari file (<span x-text="current.downtime_source"></span>). Mengganti dengan angka lain perlu dikonfirmasi di bawah.
                                </p>
                            </template>
                            <form method="POST" action="{{ route('billing.downtime', $period) }}" class="space-y-4">
                                @csrf
                                <input type="hidden" name="sid" :value="current.sid">
                                <label class="block text-sm font-medium">
                                    Total downtime bulan {{ $period->usage_name }} (detik)
                                    <input type="number" name="downtime_seconds" class="input mt-1 text-lg" inputmode="numeric" min="0" step="1" required
                                           :max="current.active_seconds" x-model="downtime" placeholder="Contoh: 3600">
                                </label>
                                <p class="text-sm" x-show="downtime !== ''">= <strong x-text="human(downtime)"></strong></p>
                                <p class="text-xs text-gray-500">
                                    Isi 0 bila tidak ada gangguan. Maksimum <span x-text="current.active_seconds.toLocaleString('id-ID')"></span> detik
                                    (<span x-text="human(current.active_seconds)"></span>, masa aktif bulan ini). SLA dan potongan dihitung otomatis.
                                </p>
                                <label class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm" x-show="current.downtime_from_file">
                                    <input type="checkbox" name="replace_existing" value="1" class="mt-0.5 text-[#E6002D] focus:ring-[#E6002D]"
                                           :required="current.downtime_from_file" :disabled="! current.downtime_from_file">
                                    <span>Ganti nilai dari file dengan angka yang saya isi di atas</span>
                                </label>
                                <div class="flex justify-end gap-2 pt-2">
                                    <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                                    <button class="btn btn-primary" :disabled="current.downtime_state === 'review'">Simpan downtime</button>
                                </div>
                            </form>
                        </div>
                    </template>
                </div>
            </template>
        </x-sij.modal>

        {{-- Modal: new address of an SSID flagged Relokasi last month --}}
        <x-sij.modal name="relocation" title-expr="'Alamat baru · ' + (current?.sid ?? '')">
            <template x-if="current">
                <form method="POST" action="{{ route('billing.relocation-address', $period) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="sid" :value="current.sid">
                    <p class="text-sm text-gray-600">SSID ini ditandai relokasi di tagihan {{ $previousName ?? 'bulan lalu' }}.</p>
                    <div class="text-sm">
                        <span class="block text-gray-500">Alamat lama</span>
                        <span x-text="current.relocation_old_address ?? current.address"></span>
                    </div>
                    <label class="block text-sm font-medium">Alamat baru
                        <textarea name="new_address" rows="3" class="input mt-1" required minlength="5" x-text="current.relocation_new_address ?? ''"></textarea>
                    </label>
                    <p class="text-xs text-gray-500">Alamat baru dipakai di invoice bulan ini dan disimpan juga ke Master › Daftar SSID.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                        <button class="btn btn-primary">Simpan alamat baru</button>
                    </div>
                </form>
            </template>
        </x-sij.modal>

        {{-- Modal: new SSID (PSB) --}}
        <x-sij.modal name="psb" title="SSID baru (PSB)" width="max-w-2xl">
            <form method="POST" action="{{ route('billing.new-service', $period) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-gray-600">SSID baru otomatis didaftarkan ke Master. Jika SSID sudah ada di Master, cukup isi SSID, tanggal aktif, dan bandwidth.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block text-sm font-medium">SSID
                        <input name="ssid" class="input mt-1" required maxlength="100" value="{{ old('ssid') }}">
                    </label>
                    <label class="block text-sm font-medium">Tanggal aktif
                        <input type="date" name="effective_date" class="input mt-1" required min="{{ $period->start_date->toDateString() }}" value="{{ old('effective_date') }}">
                    </label>
                    <label class="block text-sm font-medium">Bandwidth
                        <select name="bandwidth_option_id" class="input mt-1" required>
                            <option value="">Pilih bandwidth</option>
                            @foreach ($bandwidths as $option)
                                <option value="{{ $option->id }}" @selected(old('bandwidth_option_id') == $option->id)>{{ $option->bandwidth_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium">Layanan
                        <select name="service_id" class="input mt-1">
                            <option value="">Pilih layanan</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}" @selected(old('service_id') == $service->id)>{{ $service->service_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium">Cabang
                        <select name="office_type_id" class="input mt-1">
                            <option value="">Pilih cabang</option>
                            @foreach ($offices as $office)
                                <option value="{{ $office->id }}" @selected(old('office_type_id') == $office->id)>{{ $office->office_type_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium">Kategori lokasi
                        <select name="location_category_id" class="input mt-1">
                            <option value="">Pilih kategori</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('location_category_id') == $category->id)>{{ $category->location_category_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium">Router
                        <select name="billable_router_status" class="input mt-1">
                            <option value="none" @selected(old('billable_router_status', 'none') === 'none')>Tanpa router</option>
                            <option value="managed" @selected(old('billable_router_status') === 'managed')>Dengan managed router (harga lebih tinggi)</option>
                        </select>
                        <span class="block text-xs font-normal text-gray-500 mt-1">Hanya untuk SSID baru. Untuk SSID yang sudah ada, ubah di Master Harga › Daftar SSID.</span>
                    </label>
                    <label class="block text-sm font-medium md:col-span-2">Alamat
                        <textarea name="address" rows="2" class="input mt-1">{{ old('address') }}</textarea>
                    </label>
                </div>
                <p class="text-xs text-gray-500">Tanggal aktif setelah {{ $period->end_date->format('d/m/Y') }} otomatis dijadwalkan ke tagihan bulan berikutnya. Biaya instalasi diambil dari Master Harga.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                    <button class="btn btn-primary">Simpan PSB</button>
                </div>
            </form>
        </x-sij.modal>

        {{-- Modal: upload downtime --}}
        <x-sij.modal name="upload" title="Upload downtime dari Excel">
            <ol class="list-decimal ml-5 space-y-2 text-sm">
                <li><a class="text-[#E6002D] font-semibold hover:underline" href="{{ route('billing.templates.downtime') }}">Unduh template Excel</a> (2 kolom: <strong>SSID</strong> dan <strong>Downtime (detik)</strong>).</li>
                <li>Isi satu baris per SSID yang mengalami gangguan.</li>
                <li>Upload file, periksa hasilnya, lalu klik <strong>Terapkan</strong>.</li>
            </ol>
            <form method="POST" enctype="multipart/form-data" action="{{ route('billing.imports', $period) }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="kind" value="outages">
                <input type="file" name="file" accept=".xlsx,.csv" required class="block w-full text-sm">
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                    <button class="btn btn-primary">Upload & periksa</button>
                </div>
            </form>
        </x-sij.modal>

        {{-- Modal: fill the period from the Telkom workbook of a past month --}}
        <x-sij.modal name="history" title="Isi dari workbook Telkom">
            <ol class="list-decimal ml-5 space-y-2 text-sm">
                <li>Upload workbook Telkom bulan {{ $period->period_name }} apa adanya.</li>
                <li>Sistem mengenali sheet dan kolom otomatis. Anda bisa mengoreksinya.</li>
                <li>Periksa perbedaannya, lalu klik <strong>Terapkan</strong>.</li>
            </ol>
            <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">SSID yang tidak ada di file akan dikeluarkan dari periode ini setelah Anda klik <strong>Terapkan</strong>.</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('billing.imports', $period) }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="kind" value="history">
                <input type="file" name="file" accept=".xlsx" required class="block w-full text-sm">
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                    <button class="btn btn-primary">Upload & periksa</button>
                </div>
            </form>
        </x-sij.modal>

        {{-- Modal: invoice info --}}
        <x-sij.modal name="invoice" title="Info invoice">
            @if ($profileId)
                <form method="POST" action="{{ route('billing.metadata', $period) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="billing_profile_id" value="{{ $profileId }}">
                    <label class="block text-sm font-medium">Nomor invoice
                        <input name="metadata[invoice_number]" class="input mt-1" required value="{{ old('metadata.invoice_number', $metadata['invoice_number'] ?? '') }}">
                    </label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="block text-sm font-medium">Tanggal terbit
                            <input type="date" name="metadata[issue_date]" class="input mt-1" required value="{{ old('metadata.issue_date', $metadata['issue_date'] ?? '') }}">
                        </label>
                        <label class="block text-sm font-medium">Jatuh tempo
                            <input type="date" name="metadata[due_date]" class="input mt-1" required value="{{ old('metadata.due_date', $metadata['due_date'] ?? '') }}">
                        </label>
                    </div>
                    <p class="text-xs text-gray-500">Nama pelanggan, alamat, NPWP, dan nomor rekening diambil dari Master Harga › Data Pelanggan & Invoice.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-secondary" @click="modal = null">Batal</button>
                        <button class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            @else
                <p class="text-sm">Data pelanggan & invoice belum dibuat. Lengkapi dulu di
                    <a class="text-[#E6002D] font-semibold hover:underline" href="{{ route('price-masters.index', ['section' => 'profiles']) }}">Master Harga › Data Pelanggan & Invoice</a>.
                </p>
            @endif
        </x-sij.modal>
    @endunless
</div>
@endsection
