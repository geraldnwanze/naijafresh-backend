<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(50_000, 800_000);
        $quantity = fake()->numberBetween(1, 4);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'name' => fake()->words(2, true),
            'type' => ProductType::Ingredient,
            'unit' => 'pack',
            'variant_name' => null,
            'image_url' => null,
            'unit_price_kobo' => $unitPrice,
            'quantity' => $quantity,
            'line_total_kobo' => $unitPrice * $quantity,
        ];
    }
}
