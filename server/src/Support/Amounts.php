<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Casts\Money;

/**
 * QuickBooks speaks major units. Fleetbase stores integer minor units.
 * Money::apply("10.00") is 1000 and Money::apply(10) is 10, so major units
 * are normalized to a 2-decimal string before apply. Floats are rejected.
 */
class Amounts
{
    public static function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $cents    = $negative ? -$cents : $cents;
        $decimal  = intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-' . $decimal : $decimal;
    }

    /**
     * Decimal unit price such that quantity times the price, rounded half-up
     * to minor units, equals the line amount. Even splits stay at 2 decimals.
     * Otherwise the search continues through 7 decimal places.
     */
    public static function unitPriceDecimal(int $amountMinor, int $quantity): string
    {
        if ($quantity <= 0) {
            return self::centsToDecimal($amountMinor);
        }

        $negative    = $amountMinor < 0;
        $amountCents = $negative ? -$amountMinor : $amountMinor;
        $decimal     = '0.00';

        for ($places = 2; $places <= 7; $places++) {
            $divisor      = self::pow10($places - 2);
            $scaled       = $amountCents * $divisor;
            $unitScaled   = intdiv($scaled + intdiv($quantity, 2), $quantity);
            $productCents = intdiv($quantity * $unitScaled + intdiv($divisor, 2), $divisor);
            $decimal      = self::formatScaled($unitScaled, $places);
            if ($productCents === $amountCents) {
                break;
            }
        }

        return $negative ? '-' . $decimal : $decimal;
    }

    public static function toMinorUnits(int|string $amount): int
    {
        $negative = false;
        if (is_int($amount)) {
            if ($amount < 0) {
                $negative = true;
                $amount   = -$amount;
            }
            $normalized = $amount . '.00';
        } else {
            $amount = trim($amount);
            if ($amount === '' || $amount === '-') {
                return 0;
            }
            if (str_starts_with($amount, '-')) {
                $negative = true;
                $amount   = substr($amount, 1);
            }
            $normalized = self::twoDecimals($amount);
        }

        $minor = Money::apply($normalized);

        return $negative ? -$minor : $minor;
    }

    private static function twoDecimals(string $amount): string
    {
        $amount = str_replace(',', '', $amount);
        if (!str_contains($amount, '.')) {
            return ($amount === '' ? '0' : $amount) . '.00';
        }

        [$whole, $fraction]  = explode('.', $amount, 2);
        $whole               = $whole === '' ? '0' : $whole;
        $digits              = preg_replace('/\D/', '', $fraction) ?? '';
        if (strlen($digits) <= 2) {
            return $whole . '.' . str_pad($digits, 2, '0', STR_PAD_RIGHT);
        }

        $keep = (int) substr($digits, 0, 2);
        if ((int) $digits[2] >= 5) {
            $keep++;
        }
        if ($keep >= 100) {
            $whole = (string) ((int) $whole + intdiv($keep, 100));
            $keep %= 100;
        }

        return $whole . '.' . str_pad((string) $keep, 2, '0', STR_PAD_LEFT);
    }

    private static function pow10(int $exponent): int
    {
        $value = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $value *= 10;
        }

        return $value;
    }

    private static function formatScaled(int $scaled, int $places): string
    {
        $scale    = self::pow10($places);
        $whole    = intdiv($scaled, $scale);
        $fraction = $scaled % $scale;

        return $whole . '.' . str_pad((string) $fraction, $places, '0', STR_PAD_LEFT);
    }
}
