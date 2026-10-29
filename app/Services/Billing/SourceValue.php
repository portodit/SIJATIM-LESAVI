<?php

namespace App\Services\Billing;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;

final class SourceValue
{
    public static function sid(mixed $v): string
    {
        if (is_int($v) || is_float($v)) {
            if (abs($v) > 999999999999999) {
                throw new \InvalidArgumentException('SID lebih dari 15 digit harus disimpan sebagai teks di sumber Excel.');
            }
            if (! is_finite((float) $v) || floor((float) $v) !== (float) $v) {
                throw new \InvalidArgumentException('SID numerik bukan bilangan bulat.');
            }

            return sprintf('%.0f', $v);
        }
        $sid = trim((string) $v);
        if ($sid === '' || str_starts_with($sid, '=')) {
            throw new \InvalidArgumentException('SID kosong atau berupa formula.');
        }

        return $sid;
    }

    public static function number(mixed $v, bool $decimalFormat = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            if (! is_finite((float) $v)) {
                throw new \InvalidArgumentException('Angka tidak valid.');
            }

            return (string) $v;
        }
        $v = preg_replace('/[\s\x{00a0}]/u', '', trim((string) $v));
        $v = preg_replace('/^Rp\.?/i', '', $v);
        if ($decimalFormat && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return $v;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3}){2,}(,\d+)?$/', $v)) {
            $v = str_replace('.', '', $v);
        } elseif (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace('.', '', $v);
        } elseif (preg_match('/^[1-9]\d*\.\d{3}$/', $v)) {
            throw new \InvalidArgumentException('Format angka ambigu; gunakan angka tanpa pemisah ribuan.');
        }
        $v = str_replace(',', '.', $v);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            throw new \InvalidArgumentException('Format angka tidak valid.');
        }

        return $v;
    }

    public static function date(mixed $v, bool $withTime = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $date = Carbon::instance(Date::excelToDateTimeObject((float) $v, new \DateTimeZone('Asia/Jakarta')));
        } elseif ($v instanceof \DateTimeInterface) {
            $date = Carbon::instance($v);
        } else {
            $text = trim((string) $v);
            if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})(.*)$/', $text, $m)) {
                if ((int) $m[1] <= 12 && (int) $m[2] <= 12 && $m[1] !== $m[2]) {
                    throw new \InvalidArgumentException('Tanggal ambigu; gunakan YYYY-MM-DD.');
                }
                [$day, $month] = (int) $m[2] > 12 ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];
                if (! checkdate($month, $day, (int) $m[3])) {
                    throw new \InvalidArgumentException('Tanggal tidak valid.');
                }
                $text = sprintf('%04d-%02d-%02d', $m[3], $month, $day).$m[4];
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) && ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \InvalidArgumentException('Tanggal tidak valid.');
            }
            $date = Carbon::parse($text, 'Asia/Jakarta');
        }

        return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    public static function header(mixed $v): string
    {
        $header = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $v))), '_');

        return ['ssid' => 'sid', 'downtime_detik' => 'duration_seconds'][$header] ?? $header;
    }

    public static function order(mixed $v): string
    {
        $s = self::header($v);

        return ['eksisting' => 'existing', 'pasang_baru' => 'pasang_baru', 'psb' => 'pasang_baru', 'cabut' => 'pemutusan', 'termination' => 'pemutusan', 'relocation' => 'relokasi', 'new_installation' => 'pasang_baru'][$s] ?? $s;
    }

    public static function reportedMonth(string $title): ?string
    {
        $names = ['januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12];
        foreach ($names as $name => $month) {
            if (preg_match('/'.$name.'\s+(20\d{2})/i', $title, $m)) {
                return sprintf('%s-%02d-01', $m[1], $month);
            }
        }

        return null;
    }
}
