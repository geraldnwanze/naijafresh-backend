<?php

namespace Database\Factories;

use App\Models\DeliveryWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryWindow>
 */
class DeliveryWindowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->randomElement(['Morning', 'Afternoon', 'Evening']),
            'starts_at' => '08:00',
            'ends_at' => '11:00',
            'is_active' => true,
            'position' => 0,
        ];
    }
}
