<?php

namespace App\Enums;

enum ProductType: string
{
    case Ingredient = 'ingredient';
    case MealKit = 'meal_kit';

    public function label(): string
    {
        return match ($this) {
            self::Ingredient => 'Ingredient',
            self::MealKit => 'Meal Kit',
        };
    }
}
