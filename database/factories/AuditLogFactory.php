<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'event' => 'updated',
            'auditable_type' => Product::class,
            'auditable_id' => fake()->numberBetween(1, 100),
            'auditable_label' => fake()->words(2, true),
            'old_values' => ['price_kobo' => 100_000],
            'new_values' => ['price_kobo' => 120_000],
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Pest',
        ];
    }
}
