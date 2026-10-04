<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Fresh Foodstuff',
                'slug' => 'fresh-foodstuff',
                'description' => 'Everyday market staples sourced fresh and delivered to your door.',
            ],
            [
                'name' => 'Vegetables',
                'slug' => 'vegetables',
                'description' => 'Leafy greens and soup vegetables, cleaned and ready for the pot.',
            ],
            [
                'name' => 'Fruits',
                'slug' => 'fruits',
                'description' => 'Seasonal Nigerian fruits, hand-picked for ripeness.',
            ],
            [
                'name' => 'Spices & Seasonings',
                'slug' => 'spices-seasonings',
                'description' => 'Local spices and seasoning blends that give Nigerian food its flavour.',
            ],
            [
                'name' => 'Staples',
                'slug' => 'staples',
                'description' => 'Rice, yam, garri, beans and the pantry basics every kitchen needs.',
            ],
            [
                'name' => 'Protein',
                'slug' => 'protein',
                'description' => 'Beef, chicken, fish and assorted meat, cut and portioned for cooking.',
            ],
            [
                'name' => 'Frozen Foods',
                'slug' => 'frozen-foods',
                'description' => 'Frozen chicken, turkey, fish and prawns — sold by weight and delivered in insulated cooler bags.',
            ],
            [
                'name' => 'Ready-to-Cook Meal Kits',
                'slug' => 'meal-kits',
                'description' => 'Every ingredient for a Nigerian dish, washed, chopped and portioned. You just cook.',
            ],
            [
                'name' => 'Food Boxes',
                'slug' => 'food-boxes',
                'description' => 'Curated bundles that stock your kitchen for the week or the month.',
            ],
        ];

        foreach ($categories as $index => $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'position' => $index,
                    'is_active' => true,
                ],
            );
        }
    }
}
