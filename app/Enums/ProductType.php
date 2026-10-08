<?php

namespace App\Enums;

enum ProductType: string
{
    case Ingredient = 'ingredient';
    case MealKit = 'meal_kit';
    case FoodPack = 'food_pack';

    public function label(): string
    {
        return match ($this) {
            self::Ingredient => 'Ingredient',
            self::MealKit => 'Meal Kit',
            self::FoodPack => 'Food Pack',
        };
    }
}
