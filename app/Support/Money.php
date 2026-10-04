<?php

namespace App\Support;

/**
 * Helpers for working with money stored as an integer number of kobo
 * (1 Naira = 100 kobo). Keeping money as integers avoids floating point
 * rounding errors in cart and order totals.
 */
final class Money
{
    public static function toNaira(int $kobo): float
    {
        return round($kobo / 100, 2);
    }

    public static function fromNaira(int|float|string $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }

    public static function format(int $kobo, string $currency = 'NGN'): string
    {
        $symbol = $currency === 'NGN' ? '₦' : $currency.' ';

        return $symbol.number_format($kobo / 100, 2);
    }
}
