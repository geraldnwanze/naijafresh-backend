<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'status' => DeliveryStatus::Pending,
            'fee_kobo' => 200_000,
            'window_label' => 'Morning',
            'window_time' => '08:00 – 11:00',
            'scheduled_date' => now()->addDay()->toDateString(),
            'rider_name' => null,
            'rider_phone' => null,
            'dispatched_at' => null,
            'delivered_at' => null,
            'notes' => null,
        ];
    }
}
