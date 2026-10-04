<?php

namespace Database\Factories;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'user_name' => fake()->name(),
            'user_email' => fake()->safeEmail(),
            'event' => ActivityEvent::LoginSucceeded,
            'description' => 'Signed in',
            'properties' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Pest',
        ];
    }

    public function event(ActivityEvent $event, ?string $description = null): static
    {
        return $this->state(fn () => ['event' => $event, 'description' => $description ?? $event->label()]);
    }
}
