<?php

use App\Exceptions\CartException;
use App\Models\Product;
use App\Services\Cart\CartPricingService;
use App\Services\StoreSettings;

beforeEach(function (): void {
    $this->pricing = app(CartPricingService::class);
    app(StoreSettings::class)->setMany([
        StoreSettings::DELIVERY_FEE_KOBO => 200_000,
        StoreSettings::FREE_DELIVERY_THRESHOLD_KOBO => 0,
    ]);
});

it('prices weight-sold products by grams at the per-kg price', function (): void {
    $beef = Product::factory()->byWeight()->create(['price_kobo' => 650_000]);

    $cart = $this->pricing->price([['product_id' => $beef->id, 'quantity' => 1500]]);

    expect($cart['lines'][0]['unit_price_kobo'])->toBe(650_000)
        ->and($cart['lines'][0]['line_total_kobo'])->toBe(975_000)
        ->and($cart['subtotal_kobo'])->toBe(975_000)
        ->and($cart['total_kobo'])->toBe(975_000 + 200_000);
});

it('rounds a weight line half-up to the nearest kobo', function (): void {
    $rice = Product::factory()->byWeight()->create(['price_kobo' => 333_333]);

    $cart = $this->pricing->price([['product_id' => $rice->id, 'quantity' => 500]]);

    expect($cart['subtotal_kobo'])->toBe(166_667);
});

it('mixes unit and weight lines in one cart', function (): void {
    $kit = Product::factory()->create(['price_kobo' => 100_000, 'stock_quantity' => 10]);
    $rice = Product::factory()->byWeight()->create(['price_kobo' => 190_000]);

    $cart = $this->pricing->price([
        ['product_id' => $kit->id, 'quantity' => 2],
        ['product_id' => $rice->id, 'quantity' => 2500],
    ]);

    expect($cart['subtotal_kobo'])->toBe(200_000 + 475_000)
        // 2 kits + 1 weight line (its quantity is grams, not a count)
        ->and($cart['item_count'])->toBe(3);
});

it('rejects a weight below the product minimum', function (): void {
    $rice = Product::factory()->byWeight(stepGrams: 500, minGrams: 1000)->create();

    $this->pricing->price([['product_id' => $rice->id, 'quantity' => 500]]);
})->throws(CartException::class, 'minimum order');

it('rejects a weight that is not a multiple of the step', function (): void {
    $beef = Product::factory()->byWeight(stepGrams: 500)->create();

    $this->pricing->price([['product_id' => $beef->id, 'quantity' => 1250]]);
})->throws(CartException::class, 'steps of 500 g');

it('rejects a weight above the product maximum', function (): void {
    $prawns = Product::factory()->byWeight(stepGrams: 250, maxGrams: 5000)->create();

    $this->pricing->price([['product_id' => $prawns->id, 'quantity' => 5250]]);
})->throws(CartException::class, 'maximum order');

it('enforces stock in grams and reports it in kilograms', function (): void {
    $beef = Product::factory()->byWeight()->create(['stock_quantity' => 1500]);

    $this->pricing->price([['product_id' => $beef->id, 'quantity' => 2000]]);
})->throws(CartException::class, 'Only 1.5 kg');

it('caps unit-sold products at 99 per line', function (): void {
    $product = Product::factory()->create(['stock_quantity' => 500]);

    $this->pricing->price([['product_id' => $product->id, 'quantity' => 100]]);
})->throws(CartException::class, 'up to 99');

it('treats a weight product with stock below its minimum as not purchasable', function (): void {
    $rice = Product::factory()->byWeight(stepGrams: 500, minGrams: 1000)->create(['stock_quantity' => 700]);

    expect($rice->isPurchasable())->toBeFalse();
});
