<?php

use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Testing\TestResponse;

/**
 * @param  list<array{product_id: int, variant_id?: int|null, quantity: int}>  $items
 */
function placeCostOrder(User $user, array $items): TestResponse
{
    $window = DeliveryWindow::factory()->create();

    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
        'items' => $items,
        'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => $window->id,
        'payment_method' => 'cash_on_delivery',
    ]);
}

beforeEach(function (): void {
    app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);
    $this->category = Category::factory()->create();
});

it('computes profit and margin from the cost price', function (): void {
    $rice = Product::factory()->byWeight()->make(['price_kobo' => 190_000, 'cost_price_kobo' => 155_800]);

    expect($rice->profitPerUnitKobo())->toBe(34_200)
        ->and($rice->marginPercent())->toBe(18.0);
});

it('has no profit or margin until a cost price is entered', function (): void {
    $product = Product::factory()->make(['price_kobo' => 100_000, 'cost_price_kobo' => null]);

    expect($product->profitPerUnitKobo())->toBeNull()
        ->and($product->marginPercent())->toBeNull();
});

it('lets an admin save a cost price and see profit and margin', function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/products', [
        'category_id' => $this->category->id,
        'type' => 'ingredient',
        'name' => 'Palm Oil',
        'description' => 'Fresh palm oil.',
        'price_kobo' => 250_000,
        'cost_price_kobo' => 200_000,
        'unit' => '1 litre',
        'stock_quantity' => 10,
    ])->assertCreated();

    expect($response->json('data.cost_price_kobo'))->toBe(200_000)
        ->and($response->json('data.profit_per_unit_kobo'))->toBe(50_000)
        ->and($response->json('data.margin_pct'))->toEqual(20); // JSON drops the trailing .0
});

it('never shows cost or margin to shoppers', function (): void {
    $kit = Product::factory()->for($this->category)->mealKit()->create(['cost_price_kobo' => 400_000]);
    ProductVariant::factory()->for($kit)->create(['cost_delta_kobo' => 150_000]);

    $guest = $this->getJson("/api/v1/products/{$kit->slug}")->assertOk();
    $customer = $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/products/{$kit->slug}")->assertOk();

    foreach ([$guest, $customer] as $response) {
        $response->assertJsonMissingPath('data.cost_price_kobo')
            ->assertJsonMissingPath('data.margin_pct')
            ->assertJsonMissingPath('data.profit_per_unit_kobo')
            ->assertJsonMissingPath('data.variants.0.cost_delta_kobo');
    }

    expect($guest->getContent())->not->toContain('cost');
});

it('does not leak cost through the public cart price preview', function (): void {
    $product = Product::factory()->for($this->category)->create(['cost_price_kobo' => 70_000, 'stock_quantity' => 5]);

    $response = $this->postJson('/api/v1/cart/price', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertOk();

    expect($response->getContent())->not->toContain('cost');
});

it('snapshots the cost on each order line, including weight lines and variant add-ons', function (): void {
    $user = User::factory()->create();
    $rice = Product::factory()->for($this->category)->byWeight()->create(['price_kobo' => 190_000, 'cost_price_kobo' => 150_000]);
    $kit = Product::factory()->for($this->category)->mealKit()->create(['price_kobo' => 1_000_000, 'cost_price_kobo' => 600_000, 'stock_quantity' => 5]);
    $beef = ProductVariant::factory()->for($kit)->create(['price_delta_kobo' => 250_000, 'cost_delta_kobo' => 200_000]);

    $orderId = placeCostOrder($user, [
        ['product_id' => $rice->id, 'quantity' => 2500],
        ['product_id' => $kit->id, 'variant_id' => $beef->id, 'quantity' => 2],
    ])->assertCreated()->json('data.id');

    $items = Order::findOrFail($orderId)->items->keyBy('product_id');

    // 2.5 kg at ₦1,500/kg cost = ₦3,750
    expect($items[$rice->id]->unit_cost_kobo)->toBe(150_000)
        ->and($items[$rice->id]->line_cost_kobo)->toBe(375_000)
        // kit (₦6,000) + beef add-on (₦2,000) = ₦8,000 each, two of them
        ->and($items[$kit->id]->unit_cost_kobo)->toBe(800_000)
        ->and($items[$kit->id]->line_cost_kobo)->toBe(1_600_000);
});

it('keeps the sold cost when the product cost changes later', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($this->category)->create(['price_kobo' => 500_000, 'cost_price_kobo' => 300_000, 'stock_quantity' => 5]);

    $orderId = placeCostOrder($user, [['product_id' => $product->id, 'quantity' => 1]])->assertCreated()->json('data.id');
    $product->update(['cost_price_kobo' => 450_000]);

    expect(Order::findOrFail($orderId)->items->first()->line_cost_kobo)->toBe(300_000);
});

it('leaves the cost empty when the product has no cost price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($this->category)->create(['price_kobo' => 500_000, 'cost_price_kobo' => null, 'stock_quantity' => 5]);

    $orderId = placeCostOrder($user, [['product_id' => $product->id, 'quantity' => 1]])->assertCreated()->json('data.id');
    $item = Order::findOrFail($orderId)->items->first();

    expect($item->unit_cost_kobo)->toBeNull()
        ->and($item->line_cost_kobo)->toBeNull();
});

it('shows order cost and profit to admins only', function (): void {
    $customer = User::factory()->create();
    $product = Product::factory()->for($this->category)->create(['price_kobo' => 500_000, 'cost_price_kobo' => 300_000, 'stock_quantity' => 5]);
    $orderId = placeCostOrder($customer, [['product_id' => $product->id, 'quantity' => 2]])->assertCreated()->json('data.id');

    $asCustomer = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/orders/{$orderId}")->assertOk();
    expect($asCustomer->getContent())->not->toContain('cost')->not->toContain('profit');

    $this->actingAs(User::factory()->admin()->create(), 'sanctum')
        ->getJson("/api/v1/admin/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.cost_kobo', 600_000)
        ->assertJsonPath('data.profit_kobo', 400_000) // ₦10,000 sold − ₦6,000 cost; delivery fee excluded
        ->assertJsonPath('data.has_uncosted_items', false)
        ->assertJsonPath('data.items.0.line_profit_kobo', 400_000);
});
