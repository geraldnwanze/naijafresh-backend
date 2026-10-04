<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'kind' => 'protein',
            'name' => fake()->randomElement(['No protein', 'Beef', 'Chicken', 'Fish', 'Assorted']),
            'price_delta_kobo' => fake()->randomElement([0, 150_000, 200_000, 250_000]),
            'is_default' => false,
            'position' => 0,
        ];
    }
}
