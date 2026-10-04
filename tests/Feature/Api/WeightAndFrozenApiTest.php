<?php

use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Testing\TestResponse;

/**
 * @param  list<array{product_id: int, quantity: int}>  $items
 */
function placeCodOrder(User $user, array $items): TestResponse
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

it('exposes weight rules and storage type on the product', function (): void {
    $chicken = Product::factory()->for($this->category)->byWeight(stepGrams: 500, minGrams: 1000, maxGrams: 20_000)->frozen()->create(['price_kobo' => 580_000]);

    $this->getJson("/api/v1/products/{$chicken->slug}")
        ->assertOk()
        ->assertJsonPath('data.sold_by', 'weight')
        ->assertJsonPath('data.is_sold_by_weight', true)
        ->assertJsonPath('data.price_label', 'per kg')
        ->assertJsonPath('data.price_kobo', 580_000)
        ->assertJsonPath('data.weight.min_grams', 1000)
        ->assertJsonPath('data.weight.step_grams', 500)
        ->assertJsonPath('data.weight.max_grams', 20_000)
        ->assertJsonPath('data.storage_type', 'frozen')
        ->assertJsonPath('data.is_frozen', true);
});

it('omits weight rules for items sold per unit', function (): void {
    $kit = Product::factory()->for($this->category)->create(['unit' => 'bunch']);

    $this->getJson("/api/v1/products/{$kit->slug}")
        ->assertOk()
        ->assertJsonPath('data.sold_by', 'unit')
        ->assertJsonPath('data.price_label', 'per bunch')
        ->assertJsonMissingPath('data.weight')
        ->assertJsonPath('data.is_frozen', false);
});

it('filters the catalogue by storage type and by how it is sold', function (): void {
    Product::factory()->for($this->category)->byWeight()->frozen()->create(['name' => 'Frozen Turkey']);
    Product::factory()->for($this->category)->byWeight()->create(['name' => 'Parboiled Rice']);
    Product::factory()->for($this->category)->create(['name' => 'Ugu Bunch']);

    $frozen = collect($this->getJson('/api/v1/products?storage=frozen')->assertOk()->json('data'))->pluck('name');
    $byWeight = collect($this->getJson('/api/v1/products?sold_by=weight')->assertOk()->json('data'))->pluck('name');

    expect($frozen->all())->toBe(['Frozen Turkey'])
        ->and($byWeight)->toContain('Frozen Turkey', 'Parboiled Rice')
        ->and($byWeight)->not->toContain('Ugu Bunch');
});

it('returns weight-aware lines from the cart price preview', function (): void {
    $rice = Product::factory()->for($this->category)->byWeight()->create(['price_kobo' => 190_000]);

    $this->postJson('/api/v1/cart/price', ['items' => [['product_id' => $rice->id, 'quantity' => 2500]]])
        ->assertOk()
        ->assertJsonPath('data.lines.0.sold_by', 'weight')
        ->assertJsonPath('data.lines.0.quantity_label', '2.5 kg')
        ->assertJsonPath('data.lines.0.line_total_kobo', 475_000)
        ->assertJsonPath('data.subtotal_kobo', 475_000);
});

it('places a weight order: decrements stock in grams and snapshots the line', function (): void {
    $turkey = Product::factory()->for($this->category)->byWeight()->frozen()->create([
        'name' => 'Frozen Turkey',
        'price_kobo' => 720_000,
        'stock_quantity' => 10_000,
    ]);

    $response = placeCodOrder(User::factory()->create(), [['product_id' => $turkey->id, 'quantity' => 2500]])
        ->assertCreated();

    expect($turkey->refresh()->stock_quantity)->toBe(7500)
        ->and($response->json('data.subtotal_kobo'))->toBe(1_800_000)
        ->and($response->json('data.total_kobo'))->toBe(2_000_000)
        ->and($response->json('data.has_frozen_items'))->toBeTrue()
        ->and($response->json('data.items.0.sold_by'))->toBe('weight')
        ->and($response->json('data.items.0.quantity'))->toBe(2500)
        ->and($response->json('data.items.0.quantity_label'))->toBe('2.5 kg')
        ->and($response->json('data.items.0.unit_price_kobo'))->toBe(720_000)
        ->and($response->json('data.items.0.is_frozen'))->toBeTrue();
});

it('keeps the order history correct after the product is repriced', function (): void {
    $rice = Product::factory()->for($this->category)->byWeight()->create(['price_kobo' => 190_000]);
    $user = User::factory()->create();

    $orderId = placeCodOrder($user, [['product_id' => $rice->id, 'quantity' => 3000]])->assertCreated()->json('data.id');
    $rice->update(['price_kobo' => 999_000, 'sold_by' => 'unit']);

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.items.0.sold_by', 'weight')
        ->assertJsonPath('data.items.0.quantity_label', '3 kg')
        ->assertJsonPath('data.items.0.line_total_kobo', 570_000);
});

it('refuses an order that exceeds the remaining weight in stock', function (): void {
    $beef = Product::factory()->for($this->category)->byWeight()->create(['stock_quantity' => 1500]);

    placeCodOrder(User::factory()->create(), [['product_id' => $beef->id, 'quantity' => 2000]])
        ->assertStatus(422)
        ->assertJsonPath('message', "Only 1.5 kg of \"{$beef->name}\" left in stock.");

    expect($beef->refresh()->stock_quantity)->toBe(1500)
        ->and(Order::count())->toBe(0);
});

it('lets an admin create a weight-sold frozen product with the unit forced to kg', function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/products', [
        'category_id' => $this->category->id,
        'type' => 'ingredient',
        'sold_by' => 'weight',
        'storage_type' => 'frozen',
        'name' => 'Frozen Croaker',
        'description' => 'Frozen croaker fish.',
        'price_kobo' => 680_000,
        'weight_step_grams' => 500,
        'min_weight_grams' => 1000,
        'stock_quantity' => 30_000,
    ])->assertCreated();

    expect($response->json('data.unit'))->toBe('kg')
        ->and($response->json('data.sold_by'))->toBe('weight')
        ->and($response->json('data.is_frozen'))->toBeTrue()
        ->and($response->json('data.weight.step_grams'))->toBe(500)
        ->and($response->json('data.stock_label'))->toBe('30 kg');
});

it('requires a weight step for weight-sold products and a min that is a multiple of it', function (): void {
    $admin = User::factory()->admin()->create();
    $payload = [
        'category_id' => $this->category->id,
        'type' => 'ingredient',
        'sold_by' => 'weight',
        'name' => 'Frozen Hake',
        'description' => 'Frozen hake.',
        'price_kobo' => 620_000,
        'stock_quantity' => 10_000,
    ];

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/products', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('weight_step_grams');

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/products', $payload + [
        'weight_step_grams' => 500,
        'min_weight_grams' => 750,
    ])->assertUnprocessable()->assertJsonValidationErrors('min_weight_grams');
});

it('clears weight rules when a product is switched back to per-unit selling', function (): void {
    $admin = User::factory()->admin()->create();
    $rice = Product::factory()->for($this->category)->byWeight()->create();

    $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/products/{$rice->id}", [
        'category_id' => $this->category->id,
        'type' => 'ingredient',
        'sold_by' => 'unit',
        'name' => $rice->name,
        'description' => 'Now a fixed bag.',
        'price_kobo' => 950_000,
        'unit' => '5kg bag',
        'stock_quantity' => 20,
    ])->assertOk()->assertJsonPath('data.sold_by', 'unit')->assertJsonMissingPath('data.weight');

    expect($rice->refresh()->weight_step_grams)->toBeNull()
        ->and($rice->min_weight_grams)->toBeNull();
});

it('applies the low-stock threshold per selling mode in the inventory list', function (): void {
    $admin = User::factory()->admin()->create();
    Product::factory()->for($this->category)->create(['name' => 'Few Bunches', 'stock_quantity' => 4]);
    Product::factory()->for($this->category)->create(['name' => 'Plenty Bunches', 'stock_quantity' => 40]);
    Product::factory()->for($this->category)->byWeight()->create(['name' => 'Low Rice', 'stock_quantity' => 4000]);
    Product::factory()->for($this->category)->byWeight()->create(['name' => 'Plenty Rice', 'stock_quantity' => 30_000]);

    $names = collect(
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/inventory?low_stock=1')->assertOk()->json('data')
    )->pluck('name');

    expect($names->all())->toEqualCanonicalizing(['Few Bunches', 'Low Rice']);
});
