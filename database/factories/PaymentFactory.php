<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'mock',
            'method' => PaymentMethod::Paystack,
            'status' => PaymentStatus::Pending,
            'currency' => 'NGN',
            'amount_kobo' => fake()->numberBetween(500_000, 3_000_000),
            'reference' => Reference::payment(),
            'provider_reference' => null,
            'authorization_url' => null,
            'paid_at' => null,
            'meta' => [],
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
