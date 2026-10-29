<?php

namespace App\Services\Billing;

use App\Models\BillingProfile;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The PPN (VAT) rate. A billing profile and a calculation snapshot store it as a multiplier ("0.11");
 * people read and type it as a percentage ("11", "11,5").
 */
final class TaxRate
{
    public const DEFAULT = '0.11';

    /** The stored multiplier of a profile setting, or the default when there is none. */
    public static function multiplier(mixed $rate): string
    {
        try {
            return is_numeric($rate) ? (string) BigDecimal::of((string) $rate) : self::DEFAULT;
        } catch (MathException) {
            return self::DEFAULT;
        }
    }

    /** The multiplier a frozen calculation snapshot was calculated with. */
    public static function ofSnapshot(array $snapshot): string
    {
        return self::multiplier($snapshot['profile']['settings']['tax_rate'] ?? $snapshot['metadata']['tax_rate'] ?? null);
    }

    /** The multiplier configured now: the profile new periods start with. */
    public static function current(): string
    {
        return self::multiplier(BillingProfile::where('is_active', true)->orderByDesc('version')->first()?->settings['tax_rate'] ?? null);
    }

    /** "11" or "11,5": the percentage without trailing zeros, with an Indonesian decimal comma. */
    public static function percent(mixed $rate): string
    {
        return str_replace('.', ',', (string) BigDecimal::of(self::multiplier($rate))->multipliedBy(100)->strippedOfTrailingZeros());
    }

    /** "11%" or "11,5%". */
    public static function label(mixed $rate): string
    {
        return self::percent($rate).'%';
    }

    /**
     * The multiplier for something typed as a percentage ("11", "11,5", "11.5", "11%"), or null when it is
     * not a number from 0 to 100 with at most two decimals.
     */
    public static function fromPercent(mixed $input): ?string
    {
        $text = str_replace(',', '.', trim(rtrim(trim((string) $input), '%')));
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) || BigDecimal::of($text)->isGreaterThan(100)) {
            return null;
        }

        return (string) BigDecimal::of($text)->withPointMovedLeft(2)->strippedOfTrailingZeros();
    }
}
