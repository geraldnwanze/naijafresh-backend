<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\User;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(300_000, 3_000_000);
        $deliveryFee = 200_000;

        return [
            'reference' => Reference::order(),
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'contact_first_name' => fake()->firstName(),
            'contact_last_name' => fake()->lastName(),
            'contact_phone' => '0'.fake()->numerify('80########'),
            'contact_email' => fake()->safeEmail(),
            'delivery_street' => fake()->streetAddress(),
            'delivery_area' => fake()->randomElement(['Lekki Phase 1', 'Yaba', 'Surulere', 'Wuse 2', 'GRA']),
            'delivery_city' => fake()->randomElement(['Lagos', 'Abuja', 'Port Harcourt', 'Ibadan']),
            'delivery_state' => fake()->randomElement(['Lagos', 'FCT', 'Rivers', 'Oyo']),
            'delivery_country' => 'Nigeria',
            'delivery_notes' => null,
            'delivery_window_id' => DeliveryWindow::factory(),
            'delivery_window_label' => 'Morning',
            'delivery_window_time' => '08:00 – 11:00',
            'delivery_date' => now()->addDay()->toDateString(),
            'payment_method' => PaymentMethod::CashOnDelivery,
            'currency' => 'NGN',
            'subtotal_kobo' => $subtotal,
            'delivery_fee_kobo' => $deliveryFee,
            'discount_kobo' => 0,
            'total_kobo' => $subtotal + $deliveryFee,
            'placed_at' => now(),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
