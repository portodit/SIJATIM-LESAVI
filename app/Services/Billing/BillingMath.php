<?php

namespace App\Services\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

final class BillingMath
{
    public const SLA_TARGET = '0.99';

    public static function decimal(string|int|float $v): BigDecimal
    {
        return BigDecimal::of((string) $v);
    }

    public static function money(string|int|float|BigDecimal $v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp);
    }

    public static function sum(array $values): string
    {
        $sum = BigDecimal::of(0);
        foreach ($values as $v) {
            $sum = $sum->plus((string) ($v ?? 0));
        }

        return self::money($sum);
    }

    public static function prorate(string $monthly, int $days, int $divisor = 30): string
    {
        return self::money(BigDecimal::of($monthly)->dividedBy($divisor, 0, RoundingMode::HalfUp)->multipliedBy($days));
    }

    /** Billing days of a month: always 30, February 28 (also in leap years). */
    public static function monthDays(CarbonInterface $date): int
    {
        return $date->month === 2 ? 28 : 30;
    }

    /** Billing-day index of a date; an end date past the last billing day (31st, 29 Feb) maps to that last day. */
    public static function billingDay(CarbonInterface $date, bool $end = false): int
    {
        return $end ? min($date->day, self::monthDays($date)) : $date->day;
    }

    /** Billing days from $start to $end (inclusive, same month); a PSB is billed from the day after its date (H+1). */
    public static function billingDays(CarbonInterface $start, CarbonInterface $end, bool $psbStart = false): int
    {
        return max(0, self::billingDay($end, true) - self::billingDay($start) - ($psbStart ? 1 : 0) + 1);
    }

    /** Charge for $days billing days: whole month flat at the monthly price, otherwise round(monthly / monthDays) per day. */
    public static function segmentAmount(string $monthly, int $days, int $monthDays): string
    {
        return $days >= $monthDays ? self::money($monthly) : self::prorate($monthly, $days, $monthDays);
    }

    public static function slaCredit(string $gross, int $down, int $seconds, string $target): string
    {
        $allowed = BigDecimal::of(1)->minus($target)->multipliedBy($seconds);
        $excess = BigDecimal::of($down)->minus($allowed);

        return $excess->isLessThanOrEqualTo(0) ? '0.00' : self::money($excess->multipliedBy($gross)->dividedBy($seconds, 12, RoundingMode::HalfUp));
    }

    public static function sla(int $down, int $seconds): string
    {
        return (string) BigDecimal::of($seconds - $down)->dividedBy($seconds, 8, RoundingMode::HalfUp);
    }

    public static function volume(string $avg, int $seconds): string
    {
        return (string) BigDecimal::of($avg)->multipliedBy($seconds)->dividedBy(8000000, 6, RoundingMode::HalfUp);
    }

    public static function round(string $v): string
    {
        return (string) BigDecimal::of($v)->toScale(0, RoundingMode::HalfUp);
    }
}
