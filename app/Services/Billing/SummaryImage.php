<?php

namespace App\Services\Billing;

use Brick\Math\BigDecimal;

/**
 * "INVOICE PERIODE ... / TELKOM - MONTHLY DATA SUMMARY" as a PNG, drawn from a frozen invoice
 * snapshot. Rows follow the SUMMARY sheet of the Telkom workbook (A–F by order type, G–J SLA,
 * then total after restitution, PPN and grand total).
 */
final class SummaryImage
{
    private const WIDTH = 1416;

    private const ROW = 40;

    private const LEFT = 15;

    private const COL2 = 750;

    private const COL3 = 1058;

    private const RIGHT = 1400;

    private const TOP = 167;

    /** Telkom red, and the font sizes (px) of the sample. */
    private const RED = [255, 0, 0];

    /**
     * The amount as drawn in the picture, but as a plain number string: rounded half up to whole rupiah,
     * and "-27342" for a credit. The Excel summary sheet writes this value, so both always agree.
     */
    public static function wholeRupiah(?string $amount, bool $negative = false): ?string
    {
        if ($amount === null) {
            return null;
        }
        $whole = BigDecimal::of(BillingMath::round($amount));

        return ($negative && ! $whole->isZero() ? '-' : '').$whole->abs();
    }

    /**
     * Whole rupiah, Indonesian grouping: "Rp1.817.765.242"; a negative amount reads "-Rp27.342".
     */
    public static function rupiah(?string $amount, bool $negative = false): string
    {
        $whole = self::wholeRupiah($amount, $negative);
        if ($whole === null) {
            return '—';
        }

        return (str_starts_with($whole, '-') ? '-' : '').'Rp'.number_format((float) ltrim($whole, '-'), 0, ',', '.');
    }

    /**
     * Table content, independent of drawing so it can be checked on its own. Besides the drawn text
     * (`count`, `value`) a row carries the same figures as numbers: `points` (a number of locations) and
     * `amount` (whole rupiah, signed, see wholeRupiah()), and some rows a `key` naming the total they show.
     *
     * @return array{title: string, subtitle: string, rows: list<array{type: string, label: string, key?: string, count?: string|null, value?: string|null, points?: int, amount?: string|null}>}
     */
    public static function rows(array $snapshot): array
    {
        $t = $snapshot['totals'];
        $counts = $t['order_counts'] ?? [];
        $components = $t['components'] ?? [];
        $groups = [
            ['A. LAYANAN EKSISTING', 'Total Layanan Eksisting', 'existing', 'existing'],
            ['B. LAYANAN BARU (PSB)', 'Total Layanan Baru (PSB)', 'pasang_baru', 'installation'],
            ['C. LAYANAN RELOKASI', 'Total Layanan Relokasi', 'relokasi', 'relocation'],
            ['D. LAYANAN UPGRADE', 'Total Layanan Upgrade', 'upgrade', 'upgrade'],
            ['E. LAYANAN DOWNGRADE', 'Total Layanan Downgrade', 'downgrade', 'downgrade'],
            ['F. CABUT/ PEMUTUSAN JARINGAN', 'Total Cabut/ Pemutusan Jaringan', 'pemutusan', 'termination'],
        ];
        $rows = [['type' => 'header', 'label' => 'KATEGORI', 'count' => 'TOTAL TITIK', 'value' => 'TOTAL NILAI']];
        $pointTotal = 0;
        foreach ($groups as [$band, $label, $countKey, $componentKey]) {
            $count = (int) ($counts[$countKey] ?? 0);
            $pointTotal += $count;
            $rows[] = ['type' => 'band', 'label' => $band];
            $rows[] = ['type' => 'item', 'label' => $label, 'count' => (string) $count, 'value' => self::rupiah($components[$componentKey] ?? '0'), 'points' => $count, 'amount' => self::wholeRupiah($components[$componentKey] ?? '0')];
        }
        $rows[] = ['type' => 'total', 'key' => 'gross', 'label' => 'Total Nilai Tagihan', 'count' => (string) $pointTotal, 'value' => self::rupiah($t['gross']), 'points' => $pointTotal, 'amount' => self::wholeRupiah($t['gross'])];

        // SLA: a service meets it when its actual SLA is known and not below the target.
        $known = count(array_filter($snapshot['lines'] ?? [], fn ($line) => ($line['sla'] ?? null) !== null));
        $below = (int) ($t['below_sla_count'] ?? 0);
        $met = max(0, $known - $below);
        $rows[] = ['type' => 'sla', 'label' => 'G. Total Titik Lokasi SLA Yang Terpenuhi', 'value' => (string) $met, 'points' => $met];
        $rows[] = ['type' => 'sla', 'label' => 'H. Total Titik Lokasi SLA Yang Tidak Terpenuhi', 'value' => (string) $below, 'points' => $below];
        $rows[] = ['type' => 'sla', 'key' => 'sla_credit', 'label' => 'I.  Total Nilai Restitusi SLA Yang Tidak Terpenuhi', 'value' => self::rupiah($t['sla_credit'], true), 'amount' => self::wholeRupiah($t['sla_credit'], true)];
        $rows[] = ['type' => 'sla', 'key' => 'manual_credit', 'label' => 'J. Total Nilai Restitusi Tagihan', 'value' => self::rupiah($t['manual_credit'], true), 'amount' => self::wholeRupiah($t['manual_credit'], true)];
        $rows[] = ['type' => 'final', 'key' => 'subtotal', 'label' => 'Total Nilai Tagihan Setelah Restitusi', 'value' => self::rupiah($t['subtotal']), 'amount' => self::wholeRupiah($t['subtotal']), 'bold' => '1'];
        $rows[] = ['type' => 'final', 'key' => 'tax', 'label' => 'PPN', 'value' => self::rupiah($t['tax']), 'amount' => self::wholeRupiah($t['tax'])];
        $rows[] = ['type' => 'final', 'key' => 'grand_total', 'label' => 'Grand Total Nilai Tagihan', 'value' => self::rupiah($t['grand_total']), 'amount' => self::wholeRupiah($t['grand_total']), 'bold' => '1'];

        return [
            'title' => 'INVOICE PERIODE  '.mb_strtoupper($snapshot['period']['name'] ?? ''),
            'subtitle' => '*TELKOM - MONTHLY DATA SUMMARY',
            'rows' => $rows,
        ];
    }

    public function render(array $snapshot, string $path): void
    {
        $regular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext') || ! is_file($regular) || ! is_file($bold)) {
            throw new \RuntimeException('Ekstensi GD dengan FreeType atau font diperlukan untuk gambar ringkasan.');
        }
        $data = self::rows($snapshot);
        $height = self::TOP + count($data['rows']) * self::ROW + 13;
        $im = imagecreatetruecolor(self::WIDTH, $height);
        imageantialias($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        $red = imagecolorallocate($im, ...self::RED);
        imagefilledrectangle($im, 0, 0, self::WIDTH, $height, $white);

        // Title and subtitle
        $this->text($im, $bold, 31, self::WIDTH / 2, 62, 'INVOICE PERIODE  '.mb_strtoupper($snapshot['period']['name'] ?? ''), $black, 'center');
        $subtitleWidth = $this->text($im, $bold, 22, self::LEFT + 7, 128, $data['subtitle'], $black, 'left');
        imagefilledrectangle($im, self::LEFT + 7, 134, self::LEFT + 7 + $subtitleWidth, 135, $black);

        $y = self::TOP;
        foreach ($data['rows'] as $row) {
            $this->drawRow($im, $row, $y, $regular, $bold, $white, $black, $red);
            $y += self::ROW;
        }
        // Outer frame
        imagerectangle($im, self::LEFT, self::TOP - 1, self::RIGHT, $y - 1, $black);
        imagerectangle($im, self::LEFT + 1, self::TOP, self::RIGHT - 1, $y - 2, $black);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (! imagepng($im, $path, 6)) {
            throw new \RuntimeException('Gambar ringkasan gagal disimpan.');
        }
    }

    private function drawRow($im, array $row, int $y, string $regular, string $bold, int $white, int $black, int $red): void
    {
        $bottom = $y + self::ROW;
        $line = fn (int $x1, int $y1, int $x2, int $y2) => imageline($im, $x1, $y1, $x2, $y2, $black);
        $mid = $y + 28; // text baseline
        switch ($row['type']) {
            case 'header':
                $this->cells($im, $y, $line);
                $this->text($im, $bold, 22, (self::LEFT + self::COL2) / 2, $mid, $row['label'], $black, 'center');
                $this->text($im, $bold, 22, (self::COL2 + self::COL3) / 2, $mid, $row['count'], $black, 'center');
                $this->text($im, $bold, 22, (self::COL3 + self::RIGHT) / 2, $mid, $row['value'], $black, 'center');
                break;
            case 'band':
                imagefilledrectangle($im, self::LEFT, $y, self::RIGHT, $bottom - 1, $red);
                $this->text($im, $regular, 22, self::LEFT + 8, $mid, $row['label'], $white, 'left');
                break;
            case 'item':
            case 'total':
                $font = $row['type'] === 'total' ? $bold : $regular;
                $this->cells($im, $y, $line);
                $this->text($im, $font, 21, self::LEFT + 8, $mid, $row['label'], $black, 'left');
                $this->text($im, $font, 21, (self::COL2 + self::COL3) / 2, $mid, $row['count'], $black, 'center');
                $this->text($im, $font, 21, (self::COL3 + self::RIGHT) / 2, $mid, $row['value'], $black, 'center');
                break;
            case 'sla':
                imagefilledrectangle($im, self::LEFT, $y, self::RIGHT, $bottom - 1, $red);
                $this->text($im, $bold, 22, self::LEFT + 8, $mid, $row['label'], $white, 'left');
                $this->text($im, $bold, 22, (self::COL3 + self::RIGHT) / 2, $mid, $row['value'], $white, 'center');
                break;
            case 'final':
                $font = isset($row['bold']) ? $bold : $regular;
                imagefilledrectangle($im, self::LEFT, $y, self::COL2, $bottom - 1, $red);
                $this->text($im, $font, 22, self::LEFT + 8, $mid, $row['label'], $white, 'left');
                $this->cells($im, $y, $line);
                $this->text($im, $font, 22, self::RIGHT - 8, $mid, $row['value'], $black, 'right');
                break;
        }
    }

    /**
     * Cell borders of a white row: bottom edge, and the column dividers.
     */
    private function cells($im, int $y, callable $line): void
    {
        $bottom = $y + self::ROW;
        $line(self::LEFT, $bottom, self::RIGHT, $bottom);
        $line(self::COL2, $y, self::COL2, $bottom);
        $line(self::COL3, $y, self::COL3, $bottom);
    }

    /**
     * Draws text at a baseline position and returns its width in pixels.
     */
    private function text($im, string $font, float $size, float $x, float $baseline, string $text, int $color, string $align): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = (int) ($box[2] - $box[0]);
        $left = match ($align) {
            'center' => (int) round($x - $width / 2),
            'right' => (int) round($x - $width),
            default => (int) round($x),
        };
        imagettftext($im, $size, 0, $left, (int) $baseline, $color, $font, $text);

        return $width;
    }
}
