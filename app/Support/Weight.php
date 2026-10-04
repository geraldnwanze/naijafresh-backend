<?php

namespace App\Support;

/**
 * Helpers for weight-based selling. Weights are integer grams everywhere
 * (quantities, stock, steps) and prices for weight-sold products are per
 * kilogram in kobo — mirroring how Money keeps currency in integer kobo.
 */
final class Weight
{
    /**
     * 1500 -> "1.5 kg", 2000 -> "2 kg", 500 -> "500 g".
     */
    public static function format(int $grams): string
    {
        if ($grams < 1000) {
            return $grams.' g';
        }

        $kilograms = rtrim(rtrim(number_format($grams / 1000, 3, '.', ''), '0'), '.');

        return $kilograms.' kg';
    }

    /**
     * Price of $grams at $pricePerKgKobo, rounded half-up to the nearest kobo.
     */
    public static function priceKobo(int $pricePerKgKobo, int $grams): int
    {
        return intdiv($pricePerKgKobo * $grams + 500, 1000);
    }
}
