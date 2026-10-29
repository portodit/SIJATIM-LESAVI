<!doctype html>
<html lang="id"><head><meta charset="UTF-8"><style>
@page { margin: 22mm 10mm 14mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 6.2px; line-height: 1.15; color: #000; }
.logo { position: fixed; top: -18mm; right: 6mm; width: 23mm; }
.page-identity { position: fixed; bottom: -9mm; left: 0; color: #888; font-size: 6px; }
.draft { text-align: center; color: #E6002D; font-weight: bold; font-size: 11px; margin: 0 0 4px; }
.title { text-align: center; font-size: 10.5px; margin: 0; }
.title .red { color: #E6002D; }
.subtitle { text-align: center; font-size: 10.5px; font-style: italic; color: #1F3FBF; margin: 0 0 10px; }
.subtitle .red { color: #E6002D; }
.customer { text-align: center; font-size: 9px; margin: 0 0 10px; }
.meta { margin: 0 auto 12px; border-collapse: collapse; font-size: 8px; font-weight: bold; }
.meta td { padding: 1px 3px; }
.meta .label-en { font-size: 8px; }
.meta .small { font-size: 6.5px; }
.section { font-weight: bold; font-size: 7px; margin: 0 0 6px; }
table.lines { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
.lines th { border: 0.6px solid #000; font-weight: bold; text-align: center; vertical-align: middle; padding: 2px 1px; font-size: 6.4px; }
.lines th.hl { background: #FFC000; }
.lines td { border: 0.6px solid #000; padding: 1px 2px; text-align: center; vertical-align: middle; overflow-wrap: break-word; }
.lines td.left { text-align: left; }
.lines td.num { text-align: right; }
table.summary { width: 100%; border-collapse: collapse; margin-top: 14px; page-break-inside: avoid; }
.summary td { background: #A6A6A6; border: 0.6px solid #7F7F7F; font-weight: bold; font-size: 6.8px; padding: 2px 4px 2px 18px; }
.summary td.num { text-align: right; width: 9%; padding-right: 2px; }
</style></head><body>
@php
    // Telkom prints whole rupiah with comma thousands; amounts with cents keep two decimals so the columns still add up.
    $money = function ($v) {
        if ($v === null) {
            return '—';
        }
        $v = (float) $v;

        return number_format($v, fmod($v, 1) == 0 ? 0 : 2, '.', ',');
    };
    $blank = fn ($v) => $v === null || (float) $v == 0 ? '' : $money($v);
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->locale('id')->translatedFormat('j F Y') : '';
    $sum = fn (array $values) => in_array(null, $values, true) ? null : array_sum(array_map('floatval', $values));
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logo-telkom.png')));
    $meta = $snapshot['metadata'];
    $totals = $snapshot['totals'];
    $components = $totals['components'];
    // A service is either upgraded or downgraded within a month, so both share one column.
    $changeTotal = $sum([$components['upgrade'], $components['downgrade']]);
    // The upgrade/downgrade columns only appear in a month that has such a case; their width goes to ALAMAT otherwise.
    $hasChange = collect($snapshot['lines'])->pluck('events')->flatten(1)->whereIn('event_type', ['upgrade', 'downgrade'])->isNotEmpty();
    $restitution = $sum([$totals['sla_credit'], $totals['manual_credit']]);
@endphp
<img class="logo" src="{{ $logo }}" alt="Telkom Indonesia">
<div class="page-identity">{{ $meta['invoice_number'] ?? '' }} · {{ $snapshot['period']['name'] }} · v{{ $version }}</div>

@if(!empty($meta['is_preview']))
<p class="draft">DRAFT - BELUM DIFINALISASI</p>
@endif
<p class="title">RINCIAN BIAYA PENGGUNAAN TELKOM <span class="red">Solution</span></p>
<p class="subtitle">TELKOM <span class="red">Solution</span> DETAIL USAGE</p>
<p class="customer">{{ $meta['customer_name'] ?? '' }}</p>

<table class="meta">
<tr><td>Nomor Tagihan. <span class="label-en">Invoice Number</span></td><td>: {{ $meta['invoice_number'] ?? '' }}</td></tr>
<tr><td>NPWP. NPWP</td><td>: {{ $meta['npwp'] ?? '' }}</td></tr>
<tr><td>CIDNAS. Customer ID</td><td>: {{ $meta['customer_id'] ?? '' }}</td></tr>
<tr><td>Nomor Account. Account</td><td>: {{ $meta['account_number'] ?? '' }}</td></tr>
<tr><td>Bulan Tagihan. Billing Month</td><td>: {{ $snapshot['period']['name'] }}</td></tr>
<tr><td class="small">Tanggal Akhir Pembayaran.Due Date</td><td>: {{ $date($meta['due_date'] ?? null) }}</td></tr>
</table>

<p class="section">&bull; TELKOM - MONTHLY RECURRING CHARGES</p>

<table class="lines">
<thead>
<tr>
<th rowspan="2" style="width:2.2%">NO.</th>
<th rowspan="2" style="width:6.4%">JENIS KANTOR</th>
<th rowspan="2" style="width:{{ $hasChange ? 21 : 30.4 }}%">ALAMAT</th>
<th rowspan="2" style="width:6.4%">KATEGORI LOKASI</th>
<th rowspan="2" style="width:6.6%">LAYANAN</th>
<th rowspan="2" style="width:8.4%">ID</th>
{{-- No width on this header: a percentage width here made Dompdf hang on the full invoice. --}}
<th colspan="{{ $hasChange ? 2 : 1 }}">BANDWIDTH</th>
<th rowspan="2" style="width:4%">Availability</th>
<th style="width:3.6%">Nilai Restitusi</th>
<th colspan="{{ $hasChange ? 5 : 4 }}">HARGA (Rp)</th>
<th rowspan="2" style="width:5.2%">TOTAL</th>
<th rowspan="2" style="width:5.6%">HARGA SETELAH RESTITUSI</th>
</tr>
<tr>
<th class="hl" style="width:4.2%">Eksisting</th>
@if($hasChange)
<th class="hl" style="width:4.2%">Upgrade / Downgrade</th>
@endif
<th class="hl">Restitusi Over SLA</th>
<th style="width:4.6%">Eksisting</th>
@if($hasChange)
<th class="hl" style="width:5.2%">Upgrade / Downgrade</th>
@endif
<th class="hl" style="width:4.4%">Cabut (Prorata)</th>
<th class="hl" style="width:4%">PSB</th>
<th class="hl" style="width:4%">Relokasi</th>
</tr>
</thead>
<tbody>
@foreach($snapshot['lines'] as $line)
@php($c = $line['charges'])
<tr>
<td>{{ $line['number'] }}</td>
<td>{{ $line['branch'] }}</td>
<td class="left">{{ $line['address'] }}</td>
<td>{{ $line['category'] }}</td>
<td>{{ $line['service'] }}</td>
<td>{{ $line['sid'] }}</td>
<td>{{ $line['bandwidth'] }}</td>
@if($hasChange)
<td>{{ $line['bandwidth_change'] ?? '' }}</td>
@endif
<td>{{ $line['sla'] === null ? '—' : number_format((float) $line['sla'] * 100, 2, '.', ',').'%' }}</td>
<td>Rp{{ $money($sum([$line['sla_credit'], $line['manual_credit']])) }}</td>
<td class="num">{{ $money($c['existing']) }}</td>
@if($hasChange)
<td class="num">{{ $blank($sum([$c['upgrade'], $c['downgrade']])) }}</td>
@endif
<td class="num">{{ $blank($c['termination']) }}</td>
<td class="num">{{ $blank($c['installation']) }}</td>
<td class="num">{{ $blank($c['relocation']) }}</td>
<td class="num">{{ $money($line['gross']) }}</td>
<td class="num">{{ $money($line['net']) }}</td>
</tr>
@endforeach
</tbody>
</table>

<table class="summary">
<tr><td>Total Biaya Layanan Eksisting</td><td class="num">{{ $money($components['existing']) }}</td></tr>
@if($hasChange)
<tr><td>Total Biaya Upgrade / Downgrade</td><td class="num">{{ $money($changeTotal) }}</td></tr>
@endif
<tr><td>Total Biaya Pasang Baru Jaringan (PSB)</td><td class="num">{{ $money($components['installation']) }}</td></tr>
<tr><td>Total Biaya Pemutusan Jaringan</td><td class="num">{{ $money($components['termination']) }}</td></tr>
@if($components['relocation'] !== null && (float) $components['relocation'] != 0)
<tr><td>Total Biaya Relokasi</td><td class="num">{{ $money($components['relocation']) }}</td></tr>
@endif
@if($restitution === null || $restitution != 0)
<tr><td>Total Restitusi</td><td class="num">{{ $restitution === null ? '—' : '-'.$money($restitution) }}</td></tr>
@endif
@if($totals['rounding'] !== null && (float) $totals['rounding'] != 0)
<tr><td>Pembulatan</td><td class="num">{{ $money($totals['rounding']) }}</td></tr>
@endif
<tr><td>Total Seluruh Tagihan</td><td class="num">{{ $money($totals['subtotal']) }}</td></tr>
<tr><td>PPN</td><td class="num">{{ $money($totals['tax']) }}</td></tr>
<tr><td>Grand Total Invoice</td><td class="num">{{ $money($totals['grand_total']) }}</td></tr>
</table>
</body></html>
