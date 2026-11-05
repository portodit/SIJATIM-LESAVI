@php
    // Same helpers as invoice-pdf.blade.php, so the Word file prints the same figures as the PDF.
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
    $meta = $snapshot['metadata'];
    $totals = $snapshot['totals'];
    $components = $totals['components'];
    // A service is either upgraded or downgraded within a month, so both share one column.
    $changeTotal = $sum([$components['upgrade'], $components['downgrade']]);
    // A column is only generated when some row has data in it; ALAMAT takes the width the left-out columns free.
    $show = \App\Services\Billing\InvoicePrintColumns::visible($snapshot);
    $special = \App\Services\Billing\InvoicePrintColumns::special($snapshot);
    $restitution = $sum([$totals['sla_credit'], $totals['manual_credit']]);

    // Characters XML 1.0 cannot hold are dropped.
    $t = fn ($v) => (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $v);
    // A dynamic value as the PDF shows it: runs of white space collapse to one space, the ends are trimmed.
    $val = fn ($v) => trim((string) preg_replace('/[ \t\n\r]+/', ' ', $t($v)));
    // One run; the only place text is written, so it is always cleaned and escaped.
    $run = function ($text, string $props = '') use ($t) {
        $text = $t($text);
        if ($text === '') {
            return '';
        }

        return '<w:r>'.($props === '' ? '' : '<w:rPr>'.$props.'</w:rPr>').'<w:t'.($text === trim($text) ? '' : ' xml:space="preserve"').'>'.e($text).'</w:t></w:r>';
    };
    // Run properties in schema order: bold, italic, colour, size.
    $rpr = fn (int $size, string $color = '', bool $italic = false) => '<w:b/><w:bCs/>'.($italic ? '<w:i/><w:iCs/>' : '').($color === '' ? '' : '<w:color w:val="'.$color.'"/>').'<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/>';
    // One table cell. Options: span (grid columns), merge (restart|cont), fill (hex), rpr (extra run properties), keep (keep with next).
    $cell = function ($text, int $width, string $style = 'Cell', array $o = []) use ($val, $run) {
        $text = $val($text);
        $pr = '<w:tcW w:w="'.$width.'" w:type="dxa"/>'
            .(($o['span'] ?? 1) > 1 ? '<w:gridSpan w:val="'.$o['span'].'"/>' : '')
            .(isset($o['merge']) ? ($o['merge'] === 'restart' ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>') : '')
            .(isset($o['fill']) ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$o['fill'].'"/>' : '')
            .'<w:vAlign w:val="center"/>';

        return '<w:tc><w:tcPr>'.$pr.'</w:tcPr><w:p><w:pPr><w:pStyle w:val="'.$style.'"/>'.(empty($o['keep']) ? '' : '<w:keepNext/>').'</w:pPr>'
            .$run($text, $o['rpr'] ?? '').'</w:p></w:tc>';
    };
    $head1 = fn ($text, int $width, array $o = []) => $cell($text, $width, 'HeadMain', ['fill' => 'D9E6FA'] + $o);
    $head2 = fn ($text, int $width, array $o = []) => $cell($text, $width, 'HeadSub', ['fill' => 'FFC000'] + $o);
    $headCont = fn (int $width) => $cell('', $width, 'HeadMain', ['merge' => 'cont', 'fill' => 'D9E6FA']);
    $metaLine = fn (string $label, $value) => '<w:p><w:pPr><w:pStyle w:val="Meta"/><w:tabs><w:tab w:val="left" w:pos="4900"/></w:tabs><w:spacing w:before="30" w:after="0"/><w:ind w:left="662"/></w:pPr>'
        .$run($label).'<w:r><w:tab/></w:r>'.$run($val(': '.$value)).'</w:p>';

    // Column widths in twips, in print order. The visible columns together with ALAMAT add up to the 20400 of the page text width.
    $w = [
        'no' => 500, 'branch' => 1250, 'address' => 0, 'category' => 1250, 'service' => 1300, 'sid' => 1850,
        'bandwidth' => 1100, 'bandwidth_change' => 950, 'sla' => 1050, 'credit' => 950,
        'existing' => 1080,
    ];
    foreach ($special as $i => $column) {
        $w['special_'.$i] = 1300;
        $show['special_'.$i] = true;
    }
    $w += ['change' => 1000, 'termination' => 950, 'installation' => 850, 'relocation' => 850, 'gross' => 1100, 'net' => 1150];
    $w = \App\Services\Billing\InvoicePrintColumns::fit(array_intersect_key($w, array_filter($show)), 20400, 1900, 0);
    $grid = array_values($w);
    // The group headers span the grid columns of their visible sub-columns and are left out when none is left.
    $bandwidthCols = array_intersect_key($w, array_flip(['bandwidth', 'bandwidth_change']));
    $priceCols = array_filter($w, fn ($width, $key) => str_starts_with($key, 'special_') || in_array($key, ['existing', 'change', 'termination', 'installation', 'relocation'], true), ARRAY_FILTER_USE_BOTH);
    $border = fn (string $side) => '<w:'.$side.' w:val="single" w:sz="8" w:space="0" w:color="000000"/>';
    $borders = '<w:tblBorders>'.implode('', array_map($border, ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])).'</w:tblBorders>';
    $tblPr = '<w:tblPr><w:tblW w:w="20400" w:type="dxa"/><w:tblInd w:w="43" w:type="dxa"/>'.$borders.'<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="40" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tblCellMar></w:tblPr>';

    // The summary rows, with the same conditions as the PDF.
    $summary = [['Total Biaya Layanan Eksisting', $money($components['existing'])]];
    if ($show['change']) {
        $summary[] = ['Total Biaya Upgrade / Downgrade', $money($changeTotal)];
    }
    $summary[] = ['Total Biaya Pasang Baru Jaringan (PSB)', $money($components['installation'])];
    $summary[] = ['Total Biaya Pemutusan Jaringan', $money($components['termination'])];
    if ($components['relocation'] !== null && (float) $components['relocation'] != 0) {
        $summary[] = ['Total Biaya Relokasi', $money($components['relocation'])];
    }
    foreach ($special as $column) {
        $summary[] = ['Total '.$column['summary'], $money($column['amount'])];
    }
    if ($restitution === null || $restitution != 0) {
        $summary[] = ['Total Restitusi', $restitution === null ? '—' : '-'.$money($restitution)];
    }
    if ($totals['rounding'] !== null && (float) $totals['rounding'] != 0) {
        $summary[] = ['Pembulatan', $money($totals['rounding'])];
    }
    $summary[] = ['Total Seluruh Tagihan', $money($totals['subtotal'])];
    $summary[] = ['PPN', $money($totals['tax'])];
    $summary[] = ['Grand Total Invoice', $money($totals['grand_total'])];
@endphp
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>
@if(!empty($meta['is_preview']))
<w:p><w:pPr><w:jc w:val="center"/></w:pPr>{!! $run('DRAFT - BELUM DIFINALISASI', $rpr(32, 'E6002D')) !!}</w:p>
@endif
<w:p><w:pPr><w:ind w:left="4090"/><w:jc w:val="center"/></w:pPr>{!! $run('RINCIAN BIAYA PENGGUNAAN TELKOM ', $rpr(32)) !!}{!! $run('Solution', $rpr(32, 'FF0000')) !!}</w:p>
<w:p><w:pPr><w:ind w:left="4090"/><w:jc w:val="center"/></w:pPr>{!! $run('TELKOM ', $rpr(32, '0000FF', true)) !!}{!! $run('Solution', $rpr(32, 'FF0000', true)) !!}{!! $run(' DETAIL USAGE', $rpr(32, '0000FF', true)) !!}</w:p>
<w:p><w:pPr><w:spacing w:before="240" w:after="240"/><w:ind w:left="4090"/><w:jc w:val="center"/></w:pPr>{!! $run($val($meta['customer_name'] ?? ''), $rpr(28)) !!}</w:p>
<w:tbl><w:tblPr><w:tblW w:w="20400" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="8478"/><w:gridCol w:w="11922"/></w:tblGrid>
<w:tr><w:tc><w:tcPr><w:tcW w:w="8478" w:type="dxa"/><w:vAlign w:val="bottom"/></w:tcPr><w:p><w:pPr><w:ind w:left="662"/></w:pPr>{!! $run('• TELKOM - MONTHLY RECURRING CHARGES', '<w:b/><w:bCs/><w:sz w:val="22"/><w:szCs w:val="22"/><w:highlight w:val="yellow"/>') !!}</w:p></w:tc>
<w:tc><w:tcPr><w:tcW w:w="11922" w:type="dxa"/></w:tcPr>
{!! $metaLine('Nomor Tagihan. Invoice Number', $meta['invoice_number'] ?? '') !!}
{!! $metaLine('NPWP. NPWP', $meta['npwp'] ?? '') !!}
{!! $metaLine('CIDNAS. Customer ID', $meta['customer_id'] ?? '') !!}
{!! $metaLine('Nomor Account. Account', $meta['account_number'] ?? '') !!}
{!! $metaLine('Bulan Tagihan. Billing Month', $snapshot['period']['name']) !!}
{!! $metaLine('Tanggal Akhir Pembayaran.Due Date', $date($meta['due_date'] ?? null)) !!}
</w:tc></w:tr></w:tbl>
<w:p><w:pPr><w:spacing w:before="120"/></w:pPr></w:p>
<w:tbl>{!! $tblPr !!}<w:tblGrid>@foreach($grid as $col)<w:gridCol w:w="{{ $col }}"/>@endforeach</w:tblGrid>
<w:tr><w:trPr><w:cantSplit/><w:trHeight w:val="464"/><w:tblHeader/></w:trPr>
{!! $head1('NO.', $w['no'], ['merge' => 'restart']) !!}
@if($show['branch'])
{!! $head1('JENIS KANTOR', $w['branch'], ['merge' => 'restart']) !!}
@endif
{!! $head1('ALAMAT', $w['address'], ['merge' => 'restart']) !!}
@if($show['category'])
{!! $head1('KATEGORI LOKASI', $w['category'], ['merge' => 'restart']) !!}
@endif
@if($show['service'])
{!! $head1('LAYANAN', $w['service'], ['merge' => 'restart']) !!}
@endif
{!! $head1('ID', $w['sid'], ['merge' => 'restart']) !!}
@if($bandwidthCols)
{!! $head1('BANDWIDTH', array_sum($bandwidthCols), ['span' => count($bandwidthCols), 'rpr' => '<w:sz w:val="18"/>']) !!}
@endif
@if($show['sla'])
{!! $head1('Availability', $w['sla'], ['merge' => 'restart']) !!}
@endif
@if($show['credit'])
{!! $head1('Nilai Restitusi', $w['credit']) !!}
@endif
@if($priceCols)
{!! $head1('HARGA (Rp)', array_sum($priceCols), ['span' => count($priceCols)]) !!}
@endif
{!! $head1('TOTAL', $w['gross'], ['merge' => 'restart']) !!}
{!! $head1('HARGA SETELAH RESTITUSI', $w['net'], ['merge' => 'restart', 'rpr' => '<w:sz w:val="16"/>']) !!}
</w:tr>
<w:tr><w:trPr><w:cantSplit/><w:trHeight w:val="462"/><w:tblHeader/></w:trPr>
{!! $headCont($w['no']) !!}
@if($show['branch'])
{!! $headCont($w['branch']) !!}
@endif
{!! $headCont($w['address']) !!}
@if($show['category'])
{!! $headCont($w['category']) !!}
@endif
@if($show['service'])
{!! $headCont($w['service']) !!}
@endif
{!! $headCont($w['sid']) !!}
@if($show['bandwidth'])
{!! $head2('Eksisting', $w['bandwidth']) !!}
@endif
@if($show['bandwidth_change'])
{!! $head2('Upgrade / Downgrade', $w['bandwidth_change']) !!}
@endif
@if($show['sla'])
{!! $headCont($w['sla']) !!}
@endif
@if($show['credit'])
{!! $head2('Restitusi Over SLA', $w['credit']) !!}
@endif
@if($show['existing'])
{!! $head2('Eksisting', $w['existing']) !!}
@endif
@foreach($special as $i => $column)
{{-- The name can be long, so it gets a small font. --}}
{!! $head2($column['name'], $w['special_'.$i], ['rpr' => '<w:sz w:val="14"/><w:szCs w:val="14"/>']) !!}
@endforeach
@if($show['change'])
{!! $head2('Upgrade / Downgrade', $w['change']) !!}
@endif
@if($show['termination'])
{!! $head2('Cabut (Prorata)', $w['termination']) !!}
@endif
@if($show['installation'])
{!! $head2('PSB', $w['installation']) !!}
@endif
@if($show['relocation'])
{!! $head2('Relokasi', $w['relocation']) !!}
@endif
{!! $headCont($w['gross']) !!}
{!! $headCont($w['net']) !!}
</w:tr>
@foreach($snapshot['lines'] as $line)
@php($c = $line['charges'])
<w:tr><w:trPr><w:cantSplit/><w:trHeight w:val="282"/></w:trPr>
{!! $cell($line['number'], $w['no']) !!}
@if($show['branch'])
{!! $cell($line['branch'], $w['branch']) !!}
@endif
{!! $cell($line['address'], $w['address'], 'CellLeft') !!}
@if($show['category'])
{!! $cell($line['category'], $w['category']) !!}
@endif
@if($show['service'])
{!! $cell($line['service'], $w['service']) !!}
@endif
{!! $cell($line['sid'], $w['sid']) !!}
@if($show['bandwidth'])
{!! $cell($line['bandwidth'], $w['bandwidth']) !!}
@endif
@if($show['bandwidth_change'])
{!! $cell($line['bandwidth_change'] ?? '', $w['bandwidth_change']) !!}
@endif
@if($show['sla'])
{!! $cell($line['sla'] === null ? '—' : number_format((float) $line['sla'] * 100, 2, '.', ',').'%', $w['sla']) !!}
@endif
@if($show['credit'])
{!! $cell('Rp'.$money($sum([$line['sla_credit'], $line['manual_credit']])), $w['credit']) !!}
@endif
@if($show['existing'])
{!! $cell($money($c['existing']), $w['existing'], 'CellNum') !!}
@endif
@foreach($special as $i => $column)
{!! $cell($blank($line['special'][$column['name']] ?? null), $w['special_'.$i], 'CellNum') !!}
@endforeach
@if($show['change'])
{!! $cell($blank($sum([$c['upgrade'], $c['downgrade']])), $w['change'], 'CellNum') !!}
@endif
@if($show['termination'])
{!! $cell($blank($c['termination']), $w['termination'], 'CellNum') !!}
@endif
@if($show['installation'])
{!! $cell($blank($c['installation']), $w['installation'], 'CellNum') !!}
@endif
@if($show['relocation'])
{!! $cell($blank($c['relocation']), $w['relocation'], 'CellNum') !!}
@endif
{!! $cell($money($line['gross']), $w['gross'], 'CellNum') !!}
{!! $cell($money($line['net']), $w['net'], 'CellNum') !!}
</w:tr>
@endforeach
</w:tbl>
<w:p><w:pPr><w:spacing w:before="280"/></w:pPr></w:p>
<w:tbl>{!! $tblPr !!}<w:tblGrid><w:gridCol w:w="18150"/><w:gridCol w:w="2250"/></w:tblGrid>
@foreach($summary as $i => $row)
<w:tr><w:trPr><w:cantSplit/><w:trHeight w:val="282"/></w:trPr>
{!! $cell($row[0], 18150, 'Sum', ['fill' => '999999', 'keep' => $i < count($summary) - 1]) !!}
{!! $cell($row[1], 2250, 'SumNum', ['fill' => '999999', 'keep' => $i < count($summary) - 1]) !!}
</w:tr>
@endforeach
</w:tbl>
<w:p><w:pPr><w:rPr><w:sz w:val="2"/><w:szCs w:val="2"/></w:rPr></w:pPr></w:p>
<w:sectPr><w:headerReference w:type="default" r:id="rIdHeader"/><w:footerReference w:type="default" r:id="rIdFooter"/><w:pgSz w:w="27790" w:h="21480" w:orient="landscape"/><w:pgMar w:top="2560" w:right="4110" w:bottom="1380" w:left="3118" w:header="336" w:footer="900" w:gutter="0"/><w:cols w:space="720"/></w:sectPr>
</w:body></w:document>
