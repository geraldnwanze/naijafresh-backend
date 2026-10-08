<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Money;
use App\Support\Weight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type->value,
            'is_meal_kit' => $this->isMealKit(),
            'is_food_pack' => $this->isFoodPack(),
            'description' => $this->description,
            'price_kobo' => $this->price_kobo,
            'price' => Money::format($this->price_kobo),
            'compare_at_price_kobo' => $this->compare_at_price_kobo,
            'compare_at_price' => $this->compare_at_price_kobo ? Money::format($this->compare_at_price_kobo) : null,

            // Cost and margin are for staff only — never exposed to shoppers.
            'cost_price_kobo' => $this->when($isAdmin, $this->cost_price_kobo),
            'profit_per_unit_kobo' => $this->when($isAdmin, fn () => $this->profitPerUnitKobo()),
            'margin_pct' => $this->when($isAdmin, fn () => $this->marginPercent()),
            'unit' => $this->unit,

            // Weight-based selling: when sold_by is "weight", price_kobo is the
            // price per kg and cart/order quantities and stock are in grams.
            'sold_by' => $this->sold_by->value,
            'is_sold_by_weight' => $this->isSoldByWeight(),
            'price_label' => $this->isSoldByWeight() ? 'per kg' : "per {$this->unit}",
            'weight' => $this->when($this->isSoldByWeight(), fn (): array => [
                'min_grams' => $this->minWeightGrams(),
                'step_grams' => $this->weightStepGrams(),
                'max_grams' => $this->max_weight_grams,
            ]),

            'storage_type' => $this->storage_type->value,
            'storage_label' => $this->storage_type->label(),
            'is_frozen' => $this->isFrozen(),

            'image_url' => $this->image_url,
            'tags' => $this->tags ?? [],
            'is_featured' => $this->is_featured,
            'is_available' => $this->is_available,
            'in_stock' => $this->isPurchasable(),
            'stock_quantity' => $this->when(
                $request->user()?->isAdmin() ?? false,
                $this->stock_quantity,
            ),
            'stock_label' => $this->when(
                $request->user()?->isAdmin() ?? false,
                fn () => $this->isSoldByWeight()
                    ? Weight::format($this->stock_quantity)
                    : (string) $this->stock_quantity,
            ),
            'preparation_type' => $this->preparation_type?->value,
            'preparation_label' => $this->preparation_type?->label(),
            'category' => new CategoryResource($this->whenLoaded('category')),

            // Meal-kit specific detail.
            'meal_kit' => $this->when($this->isMealKit(), fn (): array => [
                'serves' => $this->serves,
                'prep_time_minutes' => $this->prep_time_minutes,
                'included_items' => $this->included_items ?? [],
                'not_included_items' => $this->not_included_items ?? [],
                'storage_instructions' => $this->storage_instructions,
                'cooking_instructions' => $this->cooking_instructions,
            ]),

            // Food pack combo: the "What's inside" list.
            'food_pack' => $this->when($this->isFoodPack(), fn (): array => [
                'contents' => $this->included_items ?? [],
                'item_count' => count($this->included_items ?? []),
            ]),

            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
