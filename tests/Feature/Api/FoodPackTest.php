<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreSettings;
use Database\Seeders\CategorySeeder;
use Database\Seeders\FoodPackSeeder;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function foodPackPayload(array $overrides = []): array
{
    return array_merge([
        'category_id' => Category::factory()->create()->id,
        'type' => 'food_pack',
        'name' => 'Starter Food Pack',
        'description' => 'Rice, pasta, oil and more.',
        'price_kobo' => 2_000_000,
        'unit' => 'pack',
        'stock_quantity' => 30,
        'is_available' => true,
        'included_items' => ['Parboiled rice (2 kg)', 'Spaghetti (4 packs)', 'Groundnut oil (500 ml)'],
    ], $overrides);
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

describe('creating a food pack', function (): void {
    it('creates a pack with its contents, always non-perishable and per pack', function (): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', foodPackPayload())
            ->assertCreated()
            ->assertJsonPath('data.type', 'food_pack')
            ->assertJsonPath('data.is_food_pack', true)
            ->assertJsonPath('data.is_meal_kit', false)
            ->assertJsonPath('data.storage_type', 'ambient')
            ->assertJsonPath('data.is_frozen', false)
            ->assertJsonPath('data.is_sold_by_weight', false)
            ->assertJsonPath('data.price_label', 'per pack')
            ->assertJsonPath('data.food_pack.item_count', 3)
            ->assertJsonPath('data.food_pack.contents.0', 'Parboiled rice (2 kg)');

        expect(Product::query()->sole()->type)->toBe(ProductType::FoodPack);
    });

    it('needs at least two items in the pack', function (array $items): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', foodPackPayload(['included_items' => $items]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('included_items');
    })->with([
        'none' => [[]],
        'one' => [['Rice']],
        'blank lines only' => [['', '  ']],
    ]);

    it('refuses anything perishable or sold by weight', function (array $override, string $field): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', foodPackPayload($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'frozen' => [['storage_type' => 'frozen'], 'storage_type'],
        'chilled' => [['storage_type' => 'chilled'], 'storage_type'],
        'by weight' => [['sold_by' => 'weight', 'weight_step_grams' => 500], 'sold_by'],
    ]);

    it('accepts an explicitly ambient pack', function (): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', foodPackPayload(['storage_type' => 'ambient', 'sold_by' => 'unit']))
            ->assertCreated();
    });

    it('also enforces the rules when a pack is edited', function (): void {
        $pack = Product::factory()->foodPack()->for(Category::factory())->create();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/products/{$pack->id}", foodPackPayload(['category_id' => $pack->category_id, 'storage_type' => 'frozen']))
            ->assertUnprocessable()->assertJsonValidationErrors('storage_type');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/products/{$pack->id}", foodPackPayload(['category_id' => $pack->category_id, 'name' => 'Renamed Pack']))
            ->assertOk()->assertJsonPath('data.name', 'Renamed Pack');
    });

    it('does not apply pack rules to other product types', function (): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', foodPackPayload([
            'type' => 'ingredient', 'storage_type' => 'frozen', 'included_items' => [],
        ]))->assertCreated();
    });

    it('lets a pack be priced as low as the shop wants, down to ₦20', function (): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/products', foodPackPayload(['price_kobo' => 2_000]))
            ->assertCreated()
            ->assertJsonPath('data.price_kobo', 2_000)
            ->assertJsonPath('data.price', '₦20.00');
    });
});

describe('shopping for food packs', function (): void {
    beforeEach(function (): void {
        $this->packs = Product::factory()->foodPack()->for(Category::factory())->count(2)->create(['stock_quantity' => 10]);
        Product::factory()->for(Category::factory())->create(['stock_quantity' => 10]);
        Product::factory()->mealKit()->for(Category::factory())->create(['stock_quantity' => 10]);
    });

    it('filters the catalogue to food packs', function (): void {
        $response = $this->getJson('/api/v1/products?type=food_pack')->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and(collect($response->json('data'))->pluck('is_food_pack')->unique()->all())->toBe([true])
            ->and($response->json('data.0.food_pack.contents'))->toContain('Spaghetti (4 packs)');
    });

    it('shows the contents on the product page, and no cost to shoppers', function (): void {
        $pack = $this->packs->first();
        $pack->update(['cost_price_kobo' => 1_500_000]);

        $this->getJson("/api/v1/products/{$pack->slug}")
            ->assertOk()
            ->assertJsonPath('data.food_pack.item_count', 4)
            ->assertJsonMissingPath('data.cost_price_kobo')
            ->assertJsonMissingPath('data.meal_kit');
    });

    it('is bought like any pack: priced by the server, shipped as a non-frozen order', function (): void {
        config()->set('naijafresh.payments.provider', 'mock');
        app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);
        $pack = $this->packs->first();
        $pack->update(['price_kobo' => 2_000_000, 'stock_quantity' => 10]);

        $response = $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $pack->id, 'quantity' => 2]],
            'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
            'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
            'delivery_window_id' => DeliveryWindow::factory()->create()->id,
            'payment_method' => 'cash_on_delivery',
        ])->assertCreated();

        $order = Order::query()->with('items')->findOrFail($response->json('data.id'));

        expect($order->subtotal_kobo)->toBe(4_000_000)
            ->and($order->total_kobo)->toBe(4_200_000)
            ->and($order->items->sole()->type)->toBe(ProductType::FoodPack)
            ->and($order->items->sole()->storage_type->value)->toBe('ambient')
            ->and($response->json('data.has_frozen_items'))->toBeFalse()
            ->and($pack->fresh()->stock_quantity)->toBe(8);
    });
});

describe('the sample packs', function (): void {
    it('adds the category and packs, from ₦20,000, all shelf-stable and per pack', function (): void {
        $this->seed(FoodPackSeeder::class);

        $category = Category::query()->where('slug', 'food-pack-combos')->sole();
        $packs = Product::query()->where('type', ProductType::FoodPack->value)->get();

        expect($category->name)->toBe('Food Pack Combos')
            ->and($packs)->toHaveCount(6)
            ->and($packs->min('price_kobo'))->toBe(2_000_000)
            ->and($packs->every(fn (Product $p) => $p->category_id === $category->id
                && $p->storage_type->value === 'ambient'
                && $p->sold_by->value === 'unit'
                && count($p->included_items) >= 4
                && $p->cost_price_kobo < $p->price_kobo
                && $p->is_available))->toBeTrue();
    });

    it('can be re-run without duplicating anything', function (): void {
        $this->seed(FoodPackSeeder::class);
        $this->seed(FoodPackSeeder::class);

        expect(Product::query()->where('type', 'food_pack')->count())->toBe(6)
            ->and(Category::query()->where('slug', 'food-pack-combos')->count())->toBe(1);
    });

    it('leaves an existing category the admin has edited alone', function (): void {
        Category::factory()->create(['slug' => 'food-pack-combos', 'name' => 'My Pack Category']);

        $this->seed(FoodPackSeeder::class);

        expect(Category::query()->where('slug', 'food-pack-combos')->sole()->name)->toBe('My Pack Category');
    });

    it('is part of a fresh install: the category comes with the reference data', function (): void {
        $this->seed(CategorySeeder::class);

        expect(Category::query()->where('slug', 'food-pack-combos')->exists())->toBeTrue();
    });
});
