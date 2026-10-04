<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;

it('lists products with pagination', function (): void {
    Product::factory()->count(5)->create();

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'price_kobo', 'price', 'in_stock']], 'meta', 'links']);
});

it('filters products by category slug', function (): void {
    $spices = Category::factory()->create(['slug' => 'spices-seasonings']);
    $other = Category::factory()->create();
    Product::factory()->for($spices)->create(['name' => 'Ground Crayfish']);
    Product::factory()->for($other)->create(['name' => 'Ripe Plantain']);

    $response = $this->getJson('/api/v1/products?category=spices-seasonings')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Ground Crayfish');
});

it('searches across name, tags and category', function (): void {
    $cat = Category::factory()->create();
    Product::factory()->for($cat)->create(['name' => 'Fresh Pepper (Rodo)', 'tags' => ['pepper']]);
    Product::factory()->for($cat)->create(['name' => 'Pepper Soup Spice Mix', 'tags' => ['pepper soup']]);
    Product::factory()->for($cat)->create(['name' => 'Ripe Plantain', 'tags' => ['plantain'], 'description' => 'sweet']);

    $names = collect($this->getJson('/api/v1/products?search=pepper')->assertOk()->json('data'))
        ->pluck('name');

    expect($names)->toContain('Fresh Pepper (Rodo)')
        ->and($names)->toContain('Pepper Soup Spice Mix')
        ->and($names)->not->toContain('Ripe Plantain');
});

it('exposes meal-kit detail and variants on the product endpoint', function (): void {
    $kit = Product::factory()->mealKit()->create(['serves' => '4–5 people']);
    ProductVariant::factory()->for($kit)->create(['name' => 'Beef', 'price_delta_kobo' => 250_000]);

    $this->getJson("/api/v1/products/{$kit->slug}")
        ->assertOk()
        ->assertJsonPath('data.is_meal_kit', true)
        ->assertJsonPath('data.meal_kit.serves', '4–5 people')
        ->assertJsonPath('data.variants.0.name', 'Beef');
});

it('hides stock quantity from anonymous shoppers', function (): void {
    $product = Product::factory()->create(['stock_quantity' => 42]);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonMissingPath('data.stock_quantity');
});
