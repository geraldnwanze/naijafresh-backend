<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StorageType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'category_id' => Category::factory(),
            'type' => ProductType::Ingredient,
            'sold_by' => SoldBy::Unit,
            'storage_type' => StorageType::Ambient,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->paragraph(),
            'price_kobo' => fake()->numberBetween(50_000, 800_000),
            'compare_at_price_kobo' => null,
            'unit' => fake()->randomElement(['bunch', 'kg', 'pack', 'bag', 'piece']),
            'stock_quantity' => fake()->numberBetween(0, 120),
            'is_available' => true,
            'preparation_type' => null,
            'image_url' => null,
            'tags' => [],
            'is_featured' => false,
            'position' => fake()->numberBetween(0, 50),
        ];
    }

    public function mealKit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::MealKit,
            'unit' => 'kit',
            'price_kobo' => fake()->numberBetween(600_000, 2_500_000),
            'serves' => fake()->randomElement(['2–3 people', '3–4 people', '4–5 people', '5–6 people']),
            'prep_time_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'included_items' => fake()->randomElements(
                ['Pepper mix', 'Onions', 'Palm oil', 'Crayfish', 'Seasoning', 'Assorted vegetables'],
                3,
            ),
            'not_included_items' => ['Salt', 'Water'],
            'storage_instructions' => 'Refrigerate on arrival and use within 48 hours.',
            'cooking_instructions' => 'Follow the recipe card included in the kit.',
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
            'stock_quantity' => 0,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
    }

    /**
     * Sold by weight: price_kobo is per kg, stock_quantity is grams.
     */
    public function byWeight(int $stepGrams = 500, ?int $minGrams = null, ?int $maxGrams = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sold_by' => SoldBy::Weight,
            'unit' => 'kg',
            'price_kobo' => 600_000,
            'weight_step_grams' => $stepGrams,
            'min_weight_grams' => $minGrams ?? $stepGrams,
            'max_weight_grams' => $maxGrams,
            'stock_quantity' => 40_000,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes) => ['storage_type' => StorageType::Frozen]);
    }
}
