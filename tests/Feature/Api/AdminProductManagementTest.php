<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->category = Category::factory()->create();
});

it('creates a product and generates a unique slug', function (): void {
    Product::factory()->create(['slug' => 'ugu']);

    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', [
        'category_id' => $this->category->id,
        'type' => 'ingredient',
        'name' => 'Ugu',
        'description' => 'Fresh pumpkin leaves.',
        'price_kobo' => 70_000,
        'unit' => 'bunch',
        'stock_quantity' => 25,
    ])->assertCreated();

    expect($response->json('data.slug'))->toBe('ugu-2')
        ->and($response->json('data.price'))->toBe('₦700.00');
});

it('creates a meal kit with protein variants', function (): void {
    $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', [
        'category_id' => $this->category->id,
        'type' => 'meal_kit',
        'name' => 'Afang Soup Kit',
        'description' => 'Everything for afang soup.',
        'price_kobo' => 1_200_000,
        'unit' => 'kit',
        'stock_quantity' => 15,
        'serves' => '4–5 people',
        'included_items' => ['Afang leaves', 'Waterleaf'],
        'variants' => [
            ['name' => 'No protein', 'price_delta_kobo' => 0, 'is_default' => true],
            ['name' => 'Beef', 'price_delta_kobo' => 250_000],
        ],
    ])->assertCreated();

    expect($response->json('data.variants'))->toHaveCount(2)
        ->and($response->json('data.meal_kit.included_items'))->toContain('Afang leaves');
});

it('updates stock and availability from the inventory endpoint', function (): void {
    $product = Product::factory()->for($this->category)->create(['stock_quantity' => 10, 'is_available' => true]);

    $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/inventory/{$product->id}", [
        'stock_quantity' => 0,
        'is_available' => false,
    ])->assertOk();

    expect($product->refresh()->stock_quantity)->toBe(0)
        ->and($product->is_available)->toBeFalse();
});

it('deletes a product', function (): void {
    $product = Product::factory()->for($this->category)->create();

    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/admin/products/{$product->id}")->assertOk();

    $this->assertDatabaseMissing('products', ['id' => $product->id]);
});

it('forbids a customer from creating products', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/admin/products', [])
        ->assertForbidden();
});
