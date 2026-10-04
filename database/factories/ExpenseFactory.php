<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(ExpenseCategory::cases()),
            'description' => fake()->sentence(3),
            'amount_kobo' => fake()->numberBetween(500_000, 5_000_000),
            'incurred_on' => now()->subDays(fake()->numberBetween(0, 20))->toDateString(),
            'notes' => null,
        ];
    }
}
