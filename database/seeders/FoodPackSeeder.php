<?php

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StorageType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample "food pack" combos: fixed bundles of non-perishable foodstuffs sold per
 * pack, from ₦20,000. Development data only — replace with your real packs.
 *
 * Idempotent: the category is only created if missing, products are matched by
 * slug. Also run on its own (`db:seed --class=FoodPackSeeder`) to add the packs
 * to a store that already has a catalogue.
 */
class FoodPackSeeder extends Seeder
{
    /** What a pack costs us, as a share of its price (sample data). */
    private const COST_SHARE = 0.78;

    public function run(): void
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => CategorySeeder::FOOD_PACK_CATEGORY['slug']],
            [
                'name' => CategorySeeder::FOOD_PACK_CATEGORY['name'],
                'description' => CategorySeeder::FOOD_PACK_CATEGORY['description'],
                'position' => (int) Category::query()->max('position') + 1,
                'is_active' => true,
            ],
        );

        foreach ($this->packs() as $position => $pack) {
            $price = $pack['price_kobo'];

            Product::query()->updateOrCreate(['slug' => Str::slug($pack['name'])], [
                'category_id' => $category->id,
                'type' => ProductType::FoodPack,
                'sold_by' => SoldBy::Unit,
                'storage_type' => StorageType::Ambient,
                'unit' => 'pack',
                'name' => $pack['name'],
                'description' => $pack['description'],
                'price_kobo' => $price,
                'compare_at_price_kobo' => $pack['compare_at_price_kobo'] ?? null,
                'cost_price_kobo' => (int) (round($price * self::COST_SHARE / 1000) * 1000),
                'included_items' => $pack['items'],
                'tags' => ['food pack', 'combo', 'bundle', 'non-perishable'],
                'is_featured' => $pack['is_featured'] ?? false,
                'is_available' => true,
                'stock_quantity' => 30,
                'position' => $position,
            ]);
        }
    }

    /**
     * @return list<array{name: string, description: string, price_kobo: int, compare_at_price_kobo?: int, is_featured?: bool, items: list<string>}>
     */
    private function packs(): array
    {
        return [
            [
                'name' => 'Starter Food Pack',
                'description' => 'The basics for one person or a couple for a couple of weeks: rice, pasta, tomato paste, oil and seasoning. Everything keeps without a fridge.',
                'price_kobo' => 2_000_000,
                'is_featured' => true,
                'items' => ['Parboiled rice (2 kg)', 'Spaghetti (4 packs)', 'Tomato paste (4 tins)', 'Groundnut oil (500 ml)', 'Seasoning cubes (1 pack)', 'Salt (500 g)'],
            ],
            [
                'name' => 'Breakfast Pack',
                'description' => 'Shelf-stable breakfast for the household: oats, cereal, tea, beverage and milk.',
                'price_kobo' => 2_250_000,
                'items' => ['Oats (1 kg)', 'Corn flakes (1 box)', 'Chocolate beverage (500 g)', 'Powdered milk (400 g)', 'Tea bags (1 box)', 'Sugar (1 kg)'],
            ],
            [
                'name' => 'Soup Pantry Pack',
                'description' => 'Dry soup essentials that last: crayfish, stockfish, egusi, ogbono and palm oil.',
                'price_kobo' => 2_800_000,
                'items' => ['Ground crayfish (250 g)', 'Dried stockfish (300 g)', 'Egusi, ground (500 g)', 'Ogbono (250 g)', 'Palm oil (1 L)', 'Seasoning cubes (1 pack)'],
            ],
            [
                'name' => 'Family Food Pack',
                'description' => 'A month of staples for a family: rice, beans, garri, pasta, oil, tomato paste and more, all non-perishable.',
                'price_kobo' => 3_500_000,
                'compare_at_price_kobo' => 4_000_000,
                'is_featured' => true,
                'items' => ['Parboiled rice (10 kg)', 'Honey beans (2 kg)', 'Garri (2 kg)', 'Spaghetti (6 packs)', 'Vegetable oil (1 L)', 'Tomato paste (6 tins)', 'Seasoning cubes (2 packs)', 'Sugar (1 kg)', 'Salt (1 kg)'],
            ],
            [
                'name' => 'Rice & Beans Bulk Combo',
                'description' => 'Bulk buy for big households and small businesses: a bag of rice, beans and oil.',
                'price_kobo' => 4_500_000,
                'items' => ['Parboiled rice (25 kg)', 'Honey beans (5 kg)', 'Vegetable oil (3 L)', 'Seasoning cubes (3 packs)'],
            ],
            [
                'name' => 'Monthly Pantry Combo',
                'description' => 'Our biggest pack: stock the pantry for the month with staples, cooking essentials and breakfast items.',
                'price_kobo' => 5_500_000,
                'compare_at_price_kobo' => 6_400_000,
                'items' => ['Parboiled rice (15 kg)', 'Honey beans (3 kg)', 'Garri (3 kg)', 'Spaghetti (10 packs)', 'Macaroni (4 packs)', 'Vegetable oil (3 L)', 'Tomato paste (10 tins)', 'Oats (1 kg)', 'Powdered milk (900 g)', 'Sugar (2 kg)', 'Seasoning cubes (3 packs)', 'Salt (1 kg)'],
            ],
        ];
    }
}
