<?php

namespace App\Services\Billing;

/**
 * Which columns of the printed invoice (PDF and Word) are generated. A column that would show only blanks,
 * dashes or Rp0 on every row is left out; the always-present ones are the number, address, SID and the two totals.
 */
final class InvoicePrintColumns
{
    /**
     * The special columns of the invoice are not in this list: see special().
     *
     * @return array<string, bool> column key => whether the column is printed
     */
    public static function visible(array $snapshot): array
    {
        $lines = $snapshot['lines'] ?? [];
        // Frozen snapshots from before a field existed lack it, so every read falls back to "no data".
        $any = fn (callable $test): bool => array_filter($lines, $test) !== [];
        $sum = fn (array $values): float => array_sum(array_map('floatval', $values));
        $text = fn (string $key): bool => $any(fn (array $line) => ! in_array(trim((string) ($line[$key] ?? '')), ['', '-', '—'], true));
        $charge = fn (string ...$keys): bool => $any(fn (array $line) => $sum(array_map(fn (string $key) => $line['charges'][$key] ?? null, $keys)) != 0);

        return [
            'no' => true,
            'branch' => $text('branch'),
            'address' => true,
            'category' => $text('category'),
            'service' => $text('service'),
            'sid' => true,
            'bandwidth' => $text('bandwidth'),
            'bandwidth_change' => $text('bandwidth_change'),
            // An availability of 0 is data; only a line without one (null) prints a dash.
            'sla' => $any(fn (array $line) => ($line['sla'] ?? null) !== null),
            'credit' => $any(fn (array $line) => $sum([$line['sla_credit'] ?? null, $line['manual_credit'] ?? null]) != 0),
            'existing' => $charge('existing'),
            'change' => $charge('upgrade', 'downgrade'),
            'termination' => $charge('termination'),
            'installation' => $charge('installation'),
            'relocation' => $charge('relocation'),
            'gross' => true,
            'net' => true,
        ];
    }

    /**
     * The special columns of the invoice, in print order; only columns with an amount are in the snapshot.
     *
     * @return list<array{name: string, summary: string, amount: string}>
     */
    public static function special(array $snapshot): array
    {
        return array_values($snapshot['totals']['special_columns'] ?? []);
    }

    /**
     * Column widths that add up to $total: ALAMAT takes what the others leave. When that would be under $minAddress
     * (many columns in one month), the other columns shrink together. Order is kept; a missing ALAMAT is added at the end.
     *
     * @param  array<string, int|float>  $widths  width per printed column
     * @return array<string, int|float>
     */
    public static function fit(array $widths, int|float $total, int|float $minAddress, int $decimals): array
    {
        $rounded = fn (float $value) => $decimals === 0 ? (int) round($value) : round($value, $decimals);
        $others = array_sum(array_diff_key($widths, ['address' => true]));
        $scale = $others > $total - $minAddress ? ($total - $minAddress) / $others : 1;
        foreach ($widths as $key => $width) {
            if ($key !== 'address') {
                $widths[$key] = $rounded($width * $scale);
            }
        }
        $widths['address'] = $rounded($total - array_sum(array_diff_key($widths, ['address' => true])));

        return $widths;
    }
}
