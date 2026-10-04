<?php

use App\Exceptions\CartException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartPricingService;
use App\Services\StoreSettings;

beforeEach(function (): void {
    $this->pricing = app(CartPricingService::class);
    app(StoreSettings::class)->setMany([
        StoreSettings::DELIVERY_FEE_KOBO => 200_000,
        StoreSettings::FREE_DELIVERY_THRESHOLD_KOBO => 0,
    ]);
});

it('sums line totals and adds the delivery fee', function (): void {
    $a = Product::factory()->create(['price_kobo' => 150_000, 'stock_quantity' => 10]);
    $b = Product::factory()->create(['price_kobo' => 90_000, 'stock_quantity' => 10]);

    $cart = $this->pricing->price([
        ['product_id' => $a->id, 'quantity' => 2],
        ['product_id' => $b->id, 'quantity' => 3],
    ]);

    expect($cart['subtotal_kobo'])->toBe(150_000 * 2 + 90_000 * 3)
        ->and($cart['delivery_fee_kobo'])->toBe(200_000)
        ->and($cart['total_kobo'])->toBe($cart['subtotal_kobo'] + 200_000)
        ->and($cart['item_count'])->toBe(5);
});

it('adds the variant price delta to the unit price', function (): void {
    $kit = Product::factory()->mealKit()->create(['price_kobo' => 1_000_000, 'stock_quantity' => 5]);
    $beef = ProductVariant::factory()->for($kit)->create(['price_delta_kobo' => 250_000]);

    $cart = $this->pricing->price([
        ['product_id' => $kit->id, 'variant_id' => $beef->id, 'quantity' => 2],
    ]);

    expect($cart['lines'][0]['unit_price_kobo'])->toBe(1_250_000)
        ->and($cart['subtotal_kobo'])->toBe(2_500_000);
});

it('waives delivery once the free delivery threshold is met', function (): void {
    app(StoreSettings::class)->set(StoreSettings::FREE_DELIVERY_THRESHOLD_KOBO, 1_000_000);
    $product = Product::factory()->create(['price_kobo' => 600_000, 'stock_quantity' => 10]);

    $under = $this->pricing->price([['product_id' => $product->id, 'quantity' => 1]]);
    $over = $this->pricing->price([['product_id' => $product->id, 'quantity' => 2]]);

    expect($under['delivery_fee_kobo'])->toBe(200_000)
        ->and($over['delivery_fee_kobo'])->toBe(0)
        ->and($over['total_kobo'])->toBe(1_200_000);
});

it('rejects an empty cart', function (): void {
    $this->pricing->price([]);
})->throws(CartException::class);

it('rejects an unavailable product', function (): void {
    $product = Product::factory()->unavailable()->create();

    $this->pricing->price([['product_id' => $product->id, 'quantity' => 1]]);
})->throws(CartException::class, 'currently unavailable');

it('rejects a quantity above available stock when stock is enforced', function (): void {
    $product = Product::factory()->create(['stock_quantity' => 2]);

    $this->pricing->price([['product_id' => $product->id, 'quantity' => 5]]);
})->throws(CartException::class);

it('can skip stock enforcement for a cart price preview', function (): void {
    $product = Product::factory()->create(['stock_quantity' => 2, 'price_kobo' => 100_000]);

    $cart = $this->pricing->price([['product_id' => $product->id, 'quantity' => 5]], enforceStock: false);

    expect($cart['subtotal_kobo'])->toBe(500_000);
});
