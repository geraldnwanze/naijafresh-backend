---
paths:
  - app/Models/Product.php
---

# Models

## Food pack combos: a product type that reuses included_items
ProductType::FoodPack ('food_pack') is a fixed bundle of NON-PERISHABLE foodstuffs sold per pack. Its "What's inside" list is the existing `included_items` column (no extra column); the API exposes it as `food_pack.contents` + `is_food_pack`.
StoreProductRequest (also used for updates) enforces: at least 2 non-blank items, storage_type ambient (or omitted), not sold by weight. The admin form locks sold-by/storage when "Food pack combo" is chosen.
Any price works (min 0); the sample packs start at ₦20,000. FoodPackSeeder is idempotent (category firstOrCreate by slug, products by slug) and is called from ProductSeeder; it can also run alone, and InstallService::seedSampleData adds the packs once to a dev/staging store that predates them. CategorySeeder::FOOD_PACK_CATEGORY is the shared category definition.
Existing deployed databases don't re-run edited migrations, so schema changes after a deploy need a NEW migration.
