<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Home', 'Office', 'Mum\'s place']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '0'.fake()->numerify('80########'),
            'street' => fake()->streetAddress(),
            'area' => fake()->randomElement(['Lekki Phase 1', 'Yaba', 'Surulere', 'Wuse 2', 'GRA']),
            'city' => fake()->randomElement(['Lagos', 'Abuja', 'Port Harcourt', 'Ibadan']),
            'state' => fake()->randomElement(['Lagos', 'FCT', 'Rivers', 'Oyo']),
            'country' => 'Nigeria',
            'notes' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
