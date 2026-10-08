<?php

namespace Database\Seeders;

use App\Enums\PreparationType;
use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StorageType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Standard protein add-ons offered on every meal kit. Prices are sample
     * development prices in kobo.
     *
     * @var list<array{name: string, price_delta_kobo: int, is_default?: bool}>
     */
    private array $proteinOptions = [
        ['name' => 'No protein', 'price_delta_kobo' => 0, 'is_default' => true],
        ['name' => 'Beef', 'price_delta_kobo' => 250_000],
        ['name' => 'Chicken', 'price_delta_kobo' => 250_000],
        ['name' => 'Fish', 'price_delta_kobo' => 200_000],
        ['name' => 'Assorted meat', 'price_delta_kobo' => 350_000],
    ];

    public function run(): void
    {
        $categories = Category::pluck('id', 'slug');

        foreach ($this->ingredients() as $data) {
            $this->upsertProduct($categories, $data);
        }

        // Frozen foods: sold by weight (price per kg, stock in grams) unless a
        // row overrides it (e.g. fixed 1 kg packs).
        foreach ($this->frozenFoods() as $data) {
            $this->upsertProduct($categories, array_merge([
                'category' => 'frozen-foods',
                'unit' => 'kg',
                'sold_by' => SoldBy::Weight,
                'weight_step_grams' => 500,
                'min_weight_grams' => 1000,
                'storage_type' => StorageType::Frozen,
                'stock_quantity' => 60_000,
            ], $data));
        }

        // Food pack combos (non-perishable bundles) live in their own seeder so they can be
        // added to an existing store without touching the rest of the catalogue.
        $this->call(FoodPackSeeder::class);

        foreach ($this->mealKits() as $data) {
            $this->upsertProduct($categories, array_merge([
                'type' => ProductType::MealKit,
                'storage_type' => StorageType::Chilled,
                'unit' => 'kit',
                'preparation_type' => PreparationType::ReadyToCook,
                'not_included_items' => ['Salt', 'Water', 'Cooking gas'],
                'storage_instructions' => 'Keep refrigerated on arrival. Cook within 48 hours for best results.',
                'variants' => $this->proteinOptions,
            ], $data));
        }
    }

    /**
     * @param  Collection<string, int>  $categories
     * @param  array<string, mixed>  $data
     */
    private function upsertProduct($categories, array $data): void
    {
        $variants = $data['variants'] ?? [];
        unset($data['variants']);

        // Sample cost price (what the product costs us) unless the row sets one.
        $data['cost_price_kobo'] ??= $this->sampleCost($data['category'], $data['price_kobo']);

        $data['category_id'] = $categories[$data['category']];
        unset($data['category']);

        $data['slug'] ??= Str::slug($data['name']);
        $data['type'] ??= ProductType::Ingredient;
        $data['is_available'] ??= true;
        // Weight-sold stock is grams (40 kg); everything else is a unit count.
        $data['stock_quantity'] ??= ($data['sold_by'] ?? null) === SoldBy::Weight ? 40_000 : 40;

        $product = Product::updateOrCreate(['slug' => $data['slug']], $data);

        if ($variants !== []) {
            $product->variants()->delete();

            foreach (array_values($variants) as $position => $variant) {
                $product->variants()->create([
                    'kind' => 'protein',
                    'name' => $variant['name'],
                    'price_delta_kobo' => $variant['price_delta_kobo'],
                    // Sample cost of the add-on: about 80% of what we charge for it.
                    'cost_delta_kobo' => $variant['cost_delta_kobo'] ?? (int) (round($variant['price_delta_kobo'] * 0.8 / 1000) * 1000),
                    'is_default' => $variant['is_default'] ?? false,
                    'position' => $position,
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ingredients(): array
    {
        return [
            // --- Vegetables ---
            [
                'category' => 'vegetables', 'name' => 'Ugu (Pumpkin Leaves)', 'unit' => 'bunch',
                'price_kobo' => 70_000, 'preparation_type' => PreparationType::Cleaned, 'is_featured' => true,
                'tags' => ['soup', 'vegetable', 'ugu', 'efo'],
                'description' => 'Freshly cut fluted pumpkin leaves, de-stemmed and washed. Perfect for edikang ikong, egusi and vegetable soup.',
            ],
            [
                'category' => 'vegetables', 'name' => 'Waterleaf', 'unit' => 'bunch', 'price_kobo' => 60_000,
                'preparation_type' => PreparationType::Cleaned, 'tags' => ['soup', 'vegetable', 'waterleaf'],
                'description' => 'Tender waterleaf, picked and rinsed. Used as a softening base for afang and edikang ikong soup.',
            ],
            [
                'category' => 'vegetables', 'name' => 'Scent Leaf (Efirin)', 'unit' => 'bunch', 'price_kobo' => 40_000,
                'tags' => ['herb', 'pepper soup', 'efirin', 'nchanwu'],
                'description' => 'Aromatic scent leaf for pepper soup, yam porridge and stews. Sold in a generous bunch.',
            ],
            [
                'category' => 'vegetables', 'name' => 'Bitter Leaf (Washed)', 'unit' => 'pack', 'price_kobo' => 90_000,
                'preparation_type' => PreparationType::Cleaned, 'tags' => ['soup', 'onugbu', 'bitter leaf'],
                'description' => 'Bitter leaf that has been thoroughly washed and squeezed to reduce the bitterness, ready for onugbu soup.',
            ],
            [
                'category' => 'vegetables', 'name' => 'Green Spinach (Efo Tete)', 'unit' => 'bunch', 'price_kobo' => 70_000,
                'preparation_type' => PreparationType::Cleaned, 'tags' => ['efo riro', 'vegetable', 'spinach'],
                'description' => 'Green amaranth leaves, cleaned and chopped for efo riro and quick vegetable sides.',
            ],

            // --- Fresh foodstuff ---
            [
                'category' => 'fresh-foodstuff', 'name' => 'Fresh Tomatoes', 'unit' => 'kg', 'price_kobo' => 250_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500,
                'is_featured' => true, 'tags' => ['stew', 'jollof', 'tomato', 'pepper'],
                'description' => 'Firm, ripe tomatoes for stew and jollof. Sold by the kilogram.',
            ],
            [
                'category' => 'fresh-foodstuff', 'name' => 'Fresh Pepper (Rodo)', 'unit' => 'cup', 'price_kobo' => 120_000,
                'tags' => ['stew', 'pepper', 'rodo', 'scotch bonnet'],
                'description' => 'Hot scotch bonnet peppers for stew, sauces and pepper mixes.',
            ],
            [
                'category' => 'fresh-foodstuff', 'name' => 'Red Onions', 'unit' => 'kg', 'price_kobo' => 200_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500,
                'tags' => ['onion', 'stew', 'base'],
                'description' => 'Sharp red onions for cooking, frying and salads.',
            ],
            [
                'category' => 'fresh-foodstuff', 'name' => 'Bell Pepper (Tatashe)', 'unit' => 'kg', 'price_kobo' => 280_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500,
                'tags' => ['stew', 'tatashe', 'pepper'],
                'description' => 'Mild red bell peppers that give stew its colour and body without extra heat.',
            ],
            [
                'category' => 'fresh-foodstuff', 'name' => 'Ripe Plantain', 'unit' => 'bunch', 'price_kobo' => 350_000,
                'is_featured' => true, 'tags' => ['plantain', 'dodo', 'side'],
                'description' => 'Sweet ripe plantain for dodo, plantain porridge and grilling. About 8–10 fingers per bunch.',
            ],
            [
                'category' => 'fresh-foodstuff', 'name' => 'Puna Yam (Tuber)', 'unit' => 'tuber', 'price_kobo' => 450_000,
                'tags' => ['yam', 'staple', 'pounded yam'],
                'description' => 'A medium tuber of puna yam for boiling, frying, porridge or pounding.',
            ],
            [
                'category' => 'fresh-foodstuff', 'name' => 'Sweet Potatoes', 'unit' => 'kg', 'price_kobo' => 180_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500,
                'tags' => ['potato', 'side'],
                'description' => 'Firm sweet potatoes for boiling, roasting and frying.',
            ],

            // --- Fruits ---
            [
                'category' => 'fruits', 'name' => 'Pineapple', 'unit' => 'piece', 'price_kobo' => 150_000,
                'tags' => ['fruit', 'juice', 'smoothie'],
                'description' => 'A whole ripe pineapple, sweet and juicy. Great for eating fresh or blending.',
            ],
            [
                'category' => 'fruits', 'name' => 'Watermelon', 'unit' => 'piece', 'price_kobo' => 300_000,
                'tags' => ['fruit', 'juice'],
                'description' => 'A medium whole watermelon, picked for ripeness.',
            ],
            [
                'category' => 'fruits', 'name' => 'Agbalumo (African Star Apple)', 'unit' => 'pack of 10', 'price_kobo' => 200_000,
                'tags' => ['fruit', 'seasonal', 'udara'],
                'description' => 'Seasonal African star apple, tangy and sweet. Ten pieces per pack.',
            ],

            // --- Spices & seasonings ---
            [
                'category' => 'spices-seasonings', 'name' => 'Ground Crayfish', 'unit' => '200g pack', 'price_kobo' => 350_000,
                'is_featured' => true, 'preparation_type' => PreparationType::Blended, 'tags' => ['crayfish', 'soup', 'seasoning'],
                'description' => 'Dried crayfish, cleaned and ground. A base seasoning for almost every Nigerian soup.',
            ],
            [
                'category' => 'spices-seasonings', 'name' => 'Ogiri Igbo', 'unit' => 'wrap', 'price_kobo' => 80_000,
                'tags' => ['ogiri', 'oha', 'nsala', 'seasoning'],
                'description' => 'Fermented oil bean seasoning that gives oha, nsala and egusi soup their traditional depth.',
            ],
            [
                'category' => 'spices-seasonings', 'name' => 'Uziza Seeds (Ground)', 'unit' => '100g', 'price_kobo' => 150_000,
                'preparation_type' => PreparationType::Blended, 'tags' => ['uziza', 'pepper soup', 'nsala'],
                'description' => 'Ground uziza seeds with a warm, peppery aroma for nsala soup and pepper soup.',
            ],
            [
                'category' => 'spices-seasonings', 'name' => 'Ehuru (Calabash Nutmeg)', 'unit' => '50g', 'price_kobo' => 120_000,
                'tags' => ['ehuru', 'pepper soup', 'nutmeg'],
                'description' => 'Whole calabash nutmeg seeds. Roast, crack and grind for pepper soup and abacha.',
            ],
            [
                'category' => 'spices-seasonings', 'name' => 'Pepper Soup Spice Mix', 'unit' => '100g', 'price_kobo' => 180_000,
                'is_featured' => true, 'preparation_type' => PreparationType::Blended, 'tags' => ['pepper soup', 'spice', 'mix'],
                'description' => 'A balanced blend of pepper soup spices, ground and ready to measure into the pot.',
            ],
            [
                'category' => 'spices-seasonings', 'name' => 'Banga Spice Mix', 'unit' => '100g', 'price_kobo' => 200_000,
                'preparation_type' => PreparationType::Blended, 'tags' => ['banga', 'ofe akwu', 'spice'],
                'description' => 'Ground banga spice blend for Delta-style banga soup and ofe akwu.',
            ],
            [
                'category' => 'spices-seasonings', 'name' => 'Suya Spice (Yaji)', 'unit' => '100g', 'price_kobo' => 150_000,
                'preparation_type' => PreparationType::Blended, 'tags' => ['suya', 'yaji', 'grill', 'spice'],
                'description' => 'Peanut-based suya pepper for grilled meat, chicken and roasted plantain.',
            ],

            // --- Staples ---
            [
                'category' => 'staples', 'name' => 'Parboiled Rice', 'unit' => 'kg', 'price_kobo' => 190_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500, 'min_weight_grams' => 1000,
                'is_featured' => true, 'tags' => ['rice', 'jollof', 'staple'],
                'description' => 'Long-grain parboiled rice that cooks up firm and separate. Sold by the kilogram — order as much as you need.',
            ],
            [
                'category' => 'staples', 'name' => 'Brown Beans (Oloyin)', 'unit' => 'kg', 'price_kobo' => 225_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500, 'min_weight_grams' => 1000,
                'tags' => ['beans', 'oloyin', 'staple'],
                'description' => 'Honey beans (oloyin) that cook soft and sweet for porridge, moi moi and akara.',
            ],
            [
                'category' => 'staples', 'name' => 'Ijebu Garri (Yellow)', 'unit' => 'kg', 'price_kobo' => 140_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 500, 'min_weight_grams' => 1000,
                'tags' => ['garri', 'eba', 'ijebu'],
                'description' => 'Sour, fine yellow Ijebu garri for eba and for soaking.',
            ],
            [
                'category' => 'staples', 'name' => 'Pounded Yam Flour (Poundo)', 'unit' => '1.8kg', 'price_kobo' => 420_000,
                'tags' => ['poundo', 'swallow', 'yam'],
                'description' => 'Instant pounded yam flour. Stir into hot water for a smooth swallow in minutes.',
            ],
            [
                'category' => 'staples', 'name' => 'Semovita', 'unit' => '2kg', 'price_kobo' => 380_000,
                'tags' => ['semo', 'swallow'],
                'description' => 'Semolina flour for a soft semo swallow to go with any soup.',
            ],
            [
                'category' => 'staples', 'name' => 'Palm Oil', 'unit' => '1 litre', 'price_kobo' => 250_000,
                'is_featured' => true, 'tags' => ['palm oil', 'stew', 'soup'],
                'description' => 'Fresh red palm oil for stews, soups and sauces. One litre bottle.',
            ],

            // --- Protein ---
            [
                'category' => 'protein', 'name' => 'Fresh Beef (Cut & Washed)', 'unit' => 'kg', 'price_kobo' => 650_000, 'sold_by' => SoldBy::Weight, 'storage_type' => StorageType::Chilled, 'weight_step_grams' => 500,
                'is_featured' => true, 'preparation_type' => PreparationType::Portioned, 'tags' => ['beef', 'meat', 'stew'],
                'description' => 'Fresh beef cut into medium chunks, washed and packed. Sold by the kilogram.',
            ],
            [
                'category' => 'protein', 'name' => 'Cow Skin (Kpomo)', 'unit' => 'kg', 'price_kobo' => 500_000, 'sold_by' => SoldBy::Weight, 'storage_type' => StorageType::Chilled, 'weight_step_grams' => 250,
                'preparation_type' => PreparationType::Cleaned, 'tags' => ['kpomo', 'ponmo', 'soup'],
                'description' => 'Cleaned and cut cow skin (kpomo), ready to add to soups and stews.',
            ],
            [
                'category' => 'protein', 'name' => 'Whole Chicken (Cut)', 'unit' => '1.2kg', 'price_kobo' => 700_000, 'storage_type' => StorageType::Chilled,
                'preparation_type' => PreparationType::Portioned, 'tags' => ['chicken', 'protein', 'rice'],
                'description' => 'A whole chicken, cut into pieces and cleaned. About 1.2 kilograms.',
            ],
            [
                'category' => 'protein', 'name' => 'Dried Stockfish (Panla)', 'unit' => 'kg', 'price_kobo' => 2_000_000, 'sold_by' => SoldBy::Weight, 'weight_step_grams' => 100, 'min_weight_grams' => 200,
                'tags' => ['stockfish', 'panla', 'soup'],
                'description' => 'Boneless dried stockfish pieces, softened and cleaned for soups.',
            ],
            [
                'category' => 'protein', 'name' => 'Catfish (Cleaned & Cut)', 'unit' => 'kg', 'price_kobo' => 480_000, 'sold_by' => SoldBy::Weight, 'storage_type' => StorageType::Chilled, 'weight_step_grams' => 500,
                'preparation_type' => PreparationType::Cleaned, 'tags' => ['catfish', 'pepper soup', 'fish'],
                'description' => 'Fresh catfish, gutted, cleaned and cut into steaks for pepper soup and stew.',
            ],

            // --- Food boxes ---
            [
                'category' => 'food-boxes', 'name' => 'Weekly Soup Starter Box', 'unit' => 'box', 'price_kobo' => 1_800_000,
                'is_featured' => true, 'tags' => ['bundle', 'box', 'soup', 'weekly'],
                'description' => 'A week of soup basics: palm oil, ground crayfish, assorted pepper mix, onions, seasoning and two soup vegetables of your choice on the day.',
            ],
            [
                'category' => 'food-boxes', 'name' => 'Jollof Party Pack (Serves 10)', 'unit' => 'box', 'price_kobo' => 2_500_000,
                'tags' => ['bundle', 'box', 'jollof', 'party'],
                'description' => 'Everything for a pot of party jollof for ten: parboiled rice, blended stew base, seasoning, onions and vegetable oil.',
            ],
        ];
    }

    /**
     * Sample cost price: a plausible share of the selling price for the category
     * (staples and frozen protein run thin; kits and spices earn more), rounded
     * to the nearest ₦10. Development data only — replace with real costs.
     */
    private function sampleCost(string $categorySlug, int $priceKobo): int
    {
        $costShare = match ($categorySlug) {
            'fresh-foodstuff' => 0.74,
            'vegetables' => 0.66,
            'fruits' => 0.70,
            'spices-seasonings' => 0.68,
            'staples' => 0.84,
            'protein' => 0.80,
            'frozen-foods' => 0.82,
            'meal-kits' => 0.64,
            'food-boxes' => 0.72,
            default => 0.75,
        };

        return (int) (round($priceKobo * $costShare / 1000) * 1000);
    }

    /**
     * Frozen foods. Rows inherit the weight-based defaults from run(); prices
     * are sample development prices in kobo, per kg unless overridden.
     *
     * @return list<array<string, mixed>>
     */
    private function frozenFoods(): array
    {
        return [
            [
                'name' => 'Frozen Whole Chicken', 'price_kobo' => 580_000, 'is_featured' => true,
                'tags' => ['chicken', 'frozen', 'poultry'],
                'description' => 'Whole frozen chicken, cleaned and packed. Order the weight you need — we deliver it frozen in an insulated cooler bag.',
            ],
            [
                'name' => 'Frozen Chicken Laps', 'price_kobo' => 480_000,
                'tags' => ['chicken', 'laps', 'frozen', 'poultry'],
                'description' => 'Frozen chicken thighs and drumsticks (laps), ready to season, grill or stew.',
            ],
            [
                'name' => 'Frozen Chicken Wings', 'price_kobo' => 520_000,
                'tags' => ['chicken', 'wings', 'frozen', 'poultry'],
                'description' => 'Frozen chicken wings for grilling, frying or pepper soup.',
            ],
            [
                'name' => 'Frozen Turkey (Cut Parts)', 'price_kobo' => 720_000,
                'tags' => ['turkey', 'frozen', 'poultry'],
                'description' => 'Frozen turkey cut into parts, for frying, grilling or stew.',
            ],
            [
                'name' => 'Titus Fish (Frozen)', 'price_kobo' => 550_000, 'is_featured' => true,
                'tags' => ['titus', 'mackerel', 'fish', 'frozen'],
                'description' => 'Frozen mackerel (Titus), sold by the kilogram for frying and stew.',
            ],
            [
                'name' => 'Frozen Croaker Fish', 'price_kobo' => 680_000,
                'tags' => ['croaker', 'fish', 'frozen'],
                'description' => 'Frozen croaker, cleaned and ready for pepper soup, stew or grilling.',
            ],
            [
                'name' => 'Frozen Hake Fish', 'price_kobo' => 620_000,
                'tags' => ['hake', 'fish', 'frozen'],
                'description' => 'Frozen hake fillets, sold by weight for frying, stew or soup.',
            ],
            [
                'name' => 'Frozen Prawns (Peeled)', 'price_kobo' => 1_450_000,
                'weight_step_grams' => 250, 'min_weight_grams' => 500,
                'tags' => ['prawns', 'shrimp', 'seafood', 'frozen'],
                'description' => 'Peeled frozen prawns for fried rice, stir-fry, pepper soup and sauces.',
            ],
            [
                'name' => 'Frozen Goat Meat', 'price_kobo' => 980_000,
                'tags' => ['goat', 'meat', 'pepper soup', 'frozen'],
                'description' => 'Frozen goat meat cut into pieces, for pepper soup and stew.',
            ],
            [
                'name' => 'Frozen Boneless Beef', 'price_kobo' => 740_000,
                'tags' => ['beef', 'meat', 'frozen'],
                'description' => 'Frozen boneless beef, sold by the kilogram for stew, suya and stir-fry.',
            ],
            [
                'name' => 'Frozen Shaki (Tripe)', 'price_kobo' => 490_000,
                'tags' => ['shaki', 'tripe', 'soup', 'frozen'],
                'description' => 'Frozen cleaned tripe (shaki) for assorted-meat soups and stew.',
            ],
            [
                'name' => 'Frozen Mixed Vegetables (1kg Pack)', 'price_kobo' => 320_000,
                'sold_by' => SoldBy::Unit, 'unit' => '1kg pack', 'stock_quantity' => 40,
                'weight_step_grams' => null, 'min_weight_grams' => null,
                'tags' => ['vegetables', 'mixed', 'fried rice', 'frozen'],
                'description' => 'Frozen diced carrots, green beans, sweet corn and peas in a fixed one-kilogram pack — handy for fried rice.',
            ],
            [
                'name' => 'Frozen Yam Chips (1kg Pack)', 'price_kobo' => 280_000,
                'sold_by' => SoldBy::Unit, 'unit' => '1kg pack', 'stock_quantity' => 40,
                'weight_step_grams' => null, 'min_weight_grams' => null,
                'tags' => ['yam', 'chips', 'frozen'],
                'description' => 'Peeled, cut yam chips frozen in a fixed one-kilogram pack for frying.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mealKits(): array
    {
        return [
            [
                'category' => 'meal-kits', 'name' => 'Afang Soup Kit', 'price_kobo' => 1_200_000, 'is_featured' => true,
                'serves' => '4–5 people', 'prep_time_minutes' => 60,
                'tags' => ['afang', 'soup', 'meal kit', 'calabar'],
                'description' => 'Everything you need for a rich pot of Afang soup. We source, wash, slice and portion the greens so you just cook.',
                'included_items' => ['Sliced afang leaves', 'Washed waterleaf', 'Ground pepper mix', 'Sliced onion', 'Palm oil', 'Ground crayfish', 'Seasoning'],
                'cooking_instructions' => 'Boil your protein with onion and seasoning. Add palm oil, pepper and crayfish, then the waterleaf. Simmer, add the afang leaves last and cook for 5–7 minutes.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Egusi Soup Kit', 'price_kobo' => 1_050_000, 'is_featured' => true,
                'serves' => '4–5 people', 'prep_time_minutes' => 55,
                'tags' => ['egusi', 'soup', 'meal kit'],
                'description' => 'Ground melon seed and prepped vegetables for a classic egusi soup, portioned and ready for the pot.',
                'included_items' => ['Ground egusi (melon seed)', 'Chopped ugu leaves', 'Ground pepper mix', 'Sliced onion', 'Palm oil', 'Ground crayfish', 'Seasoning'],
                'cooking_instructions' => 'Cook protein with onion and seasoning. Add palm oil and pepper, then the egusi paste. Simmer until the oil floats, add crayfish and vegetables, and cook for another 5 minutes.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Okro Soup Kit', 'price_kobo' => 850_000,
                'serves' => '3–4 people', 'prep_time_minutes' => 35,
                'tags' => ['okro', 'okra', 'soup', 'meal kit'],
                'description' => 'Fresh okro chopped and grated to your preferred texture, with the seasonings to match.',
                'included_items' => ['Chopped okro', 'Ground pepper mix', 'Sliced onion', 'Palm oil', 'Ground crayfish', 'Seasoning'],
                'cooking_instructions' => 'Bring stock to a boil, add palm oil, pepper and crayfish. Stir in the okro and cook for 5–10 minutes depending on how you like the draw. Add vegetables if using.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Banga Soup Kit', 'price_kobo' => 1_100_000,
                'serves' => '4–5 people', 'prep_time_minutes' => 60,
                'tags' => ['banga', 'ofe akwu', 'soup', 'meal kit'],
                'description' => 'Extracted palm fruit concentrate with a measured banga spice blend for a Delta-style banga soup.',
                'included_items' => ['Palm fruit extract', 'Banga spice blend', 'Ground pepper mix', 'Sliced onion', 'Ground crayfish', 'Seasoning'],
                'cooking_instructions' => 'Boil the palm fruit extract until it thickens. Add your protein, banga spice, pepper, crayfish and seasoning. Simmer for 20–25 minutes.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Oha Soup Kit', 'price_kobo' => 1_250_000,
                'serves' => '4–5 people', 'prep_time_minutes' => 55,
                'tags' => ['oha', 'ora', 'soup', 'meal kit'],
                'description' => 'Hand-torn oha leaves with cocoyam thickener and ogiri for an eastern-style oha soup.',
                'included_items' => ['Hand-torn oha leaves', 'Cocoyam paste (thickener)', 'Ogiri Igbo', 'Ground pepper mix', 'Palm oil', 'Ground crayfish', 'Seasoning'],
                'cooking_instructions' => 'Cook protein and stock, add the cocoyam paste in lumps and let it dissolve. Add palm oil, pepper, ogiri and crayfish. Add the oha leaves last and simmer for 5 minutes.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Edikang Ikong Kit', 'price_kobo' => 1_300_000,
                'serves' => '4–5 people', 'prep_time_minutes' => 50,
                'tags' => ['edikang ikong', 'vegetable soup', 'soup', 'meal kit'],
                'description' => 'A generous mix of sliced ugu and waterleaf with the seasonings for a proper Efik vegetable soup.',
                'included_items' => ['Sliced ugu leaves', 'Washed waterleaf', 'Sliced onion', 'Ground pepper mix', 'Palm oil', 'Ground crayfish', 'Seasoning'],
                'cooking_instructions' => 'Cook assorted meat and fish with onion and seasoning. Add palm oil, pepper and crayfish, then waterleaf. Add ugu last and cook for 5 minutes without a lid.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Jollof Rice Kit', 'price_kobo' => 900_000, 'is_featured' => true,
                'serves' => '4–6 people', 'prep_time_minutes' => 45,
                'tags' => ['jollof', 'rice', 'meal kit'],
                'description' => 'Parboiled rice with a blended tomato-pepper base and seasoning, measured for a smoky party-style jollof.',
                'included_items' => ['Parboiled rice', 'Blended tomato & pepper base', 'Sliced onion', 'Vegetable oil', 'Bay leaves', 'Seasoning & spices'],
                'cooking_instructions' => 'Fry the base in oil until reduced, add stock and seasoning, then the rice. Cover and cook on low heat until the rice is done, stirring occasionally.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Fried Rice Kit', 'price_kobo' => 950_000,
                'serves' => '4–6 people', 'prep_time_minutes' => 45,
                'tags' => ['fried rice', 'rice', 'meal kit'],
                'description' => 'Parboiled rice with diced mixed vegetables and seasoning for Nigerian-style fried rice.',
                'included_items' => ['Parboiled rice', 'Diced carrots & green beans', 'Sweet corn', 'Diced green pepper', 'Sliced onion', 'Curry & seasoning'],
                'cooking_instructions' => 'Parboil the rice in seasoned stock. Stir-fry the vegetables on high heat, then fold in the rice with curry and seasoning.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Goat Meat Pepper Soup Kit', 'price_kobo' => 1_000_000,
                'serves' => '3–4 people', 'prep_time_minutes' => 50,
                'tags' => ['pepper soup', 'goat meat', 'meal kit'],
                'description' => 'Cleaned goat meat with a measured pepper soup spice blend and scent leaf.',
                'included_items' => ['Cleaned goat meat', 'Pepper soup spice blend', 'Scent leaf', 'Sliced onion', 'Ground pepper', 'Seasoning'],
                'cooking_instructions' => 'Boil the goat meat with onion and seasoning until tender. Add the pepper soup spice and ground pepper, simmer for 15 minutes, then add scent leaf.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Ofada Rice & Ayamase Kit', 'price_kobo' => 1_150_000,
                'serves' => '4–5 people', 'prep_time_minutes' => 70,
                'tags' => ['ofada', 'ayamase', 'designer stew', 'meal kit'],
                'description' => 'Local ofada rice with a blend of green peppers for the classic ayamase (designer) stew.',
                'included_items' => ['Ofada rice', 'Blended green pepper mix', 'Sliced onion', 'Palm oil', 'Iru (locust beans)', 'Seasoning'],
                'cooking_instructions' => 'Bleach the palm oil carefully, then fry the pepper blend with onion and iru until reduced. Add protein and seasoning. Serve with boiled ofada rice.',
            ],
            [
                'category' => 'meal-kits', 'name' => 'Beef Suya Kit', 'price_kobo' => 800_000,
                'serves' => '3–4 people', 'prep_time_minutes' => 40,
                'tags' => ['suya', 'grill', 'beef', 'meal kit'],
                'description' => 'Thinly sliced beef with suya spice, skewers and sliced onions and pepper to serve.',
                'included_items' => ['Thinly sliced beef', 'Suya spice (yaji)', 'Wooden skewers', 'Sliced onion', 'Sliced fresh pepper', 'Vegetable oil'],
                'not_included_items' => ['Salt', 'Water', 'Cooking gas', 'Charcoal'],
                'cooking_instructions' => 'Thread the beef onto skewers, brush with oil and coat with suya spice. Grill or oven-roast until done, dust with more spice and serve with onions and pepper.',
            ],
        ];
    }
}
