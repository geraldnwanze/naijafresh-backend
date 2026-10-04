<?php

use App\Support\Weight;

it('formats grams as grams or kilograms', function (int $grams, string $expected): void {
    expect(Weight::format($grams))->toBe($expected);
})->with([
    [250, '250 g'],
    [500, '500 g'],
    [999, '999 g'],
    [1000, '1 kg'],
    [1500, '1.5 kg'],
    [1250, '1.25 kg'],
    [2000, '2 kg'],
    [12500, '12.5 kg'],
]);

it('prices a weight at a per-kg price in whole kobo', function (int $pricePerKg, int $grams, int $expected): void {
    expect(Weight::priceKobo($pricePerKg, $grams))->toBe($expected);
})->with([
    'exact kilograms' => [650_000, 2000, 1_300_000],
    'half a kilo' => [650_000, 500, 325_000],
    'one and a half kilos' => [650_000, 1500, 975_000],
    'rounds half up' => [333_333, 500, 166_667],
    'rounds down below half' => [100_001, 1, 100],
]);
