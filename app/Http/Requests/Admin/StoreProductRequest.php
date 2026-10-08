<?php

namespace App\Http\Requests\Admin;

use App\Enums\PreparationType;
use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StorageType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', Rule::enum(ProductType::class)],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('products', 'slug')->ignore($this->productId())],
            'description' => ['required', 'string', 'max:5000'],
            'price_kobo' => ['required', 'integer', 'min:0'],
            'compare_at_price_kobo' => ['nullable', 'integer', 'min:0'],
            // What it costs us (per item, or per kg when sold by weight).
            'cost_price_kobo' => ['nullable', 'integer', 'min:0'],
            // Weight-sold products are always priced per kg, so unit is optional for them.
            'unit' => [Rule::requiredIf(! $this->soldByWeight()), 'nullable', 'string', 'max:40'],
            'sold_by' => ['nullable', Rule::enum(SoldBy::class)],
            'storage_type' => ['nullable', Rule::enum(StorageType::class)],

            // Weight-based selling (grams). price_kobo is per kg; stock_quantity is grams.
            'weight_step_grams' => [Rule::requiredIf($this->soldByWeight()), 'nullable', 'integer', 'min:50', 'max:50000'],
            'min_weight_grams' => ['nullable', 'integer', 'min:50', 'max:200000'],
            'max_weight_grams' => ['nullable', 'integer', 'min:50', 'max:200000', 'gte:min_weight_grams'],

            'stock_quantity' => ['required', 'integer', 'min:0'],
            'is_available' => ['boolean'],
            'is_featured' => ['boolean'],
            'preparation_type' => ['nullable', Rule::enum(PreparationType::class)],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'position' => ['nullable', 'integer', 'min:0'],

            // Meal-kit fields
            'serves' => ['nullable', 'string', 'max:60'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'included_items' => ['nullable', 'array'],
            'included_items.*' => ['string', 'max:120'],
            'not_included_items' => ['nullable', 'array'],
            'not_included_items.*' => ['string', 'max:120'],
            'storage_instructions' => ['nullable', 'string', 'max:2000'],
            'cooking_instructions' => ['nullable', 'string', 'max:5000'],

            'variants' => ['nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:80'],
            'variants.*.kind' => ['nullable', 'string', 'max:40'],
            'variants.*.price_delta_kobo' => ['nullable', 'integer'],
            'variants.*.cost_delta_kobo' => ['nullable', 'integer'],
            'variants.*.is_default' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('type') !== ProductType::FoodPack->value) {
                    return;
                }

                // A food pack is a fixed, shelf-stable bundle sold per pack.
                $contents = array_filter((array) $this->input('included_items'), fn ($item) => is_string($item) && trim($item) !== '');

                if (count($contents) < 2) {
                    $validator->errors()->add('included_items', 'List at least two items that come in the pack.');
                }

                if (! in_array($this->input('storage_type'), [null, StorageType::Ambient->value], true)) {
                    $validator->errors()->add('storage_type', 'Food packs are non-perishable, so they must be stored at room temperature.');
                }

                if ($this->soldByWeight()) {
                    $validator->errors()->add('sold_by', 'Food packs are sold per pack, not by weight.');
                }
            },
            function (Validator $validator): void {
                if (! $this->soldByWeight() || $validator->errors()->hasAny(['weight_step_grams', 'min_weight_grams'])) {
                    return;
                }

                $step = (int) $this->input('weight_step_grams');
                $min = $this->input('min_weight_grams');

                if ($min !== null && $step > 0 && (int) $min % $step !== 0) {
                    $validator->errors()->add('min_weight_grams', 'The minimum weight must be a multiple of the weight step.');
                }
            },
        ];
    }

    protected function soldByWeight(): bool
    {
        return $this->input('sold_by') === SoldBy::Weight->value;
    }

    protected function productId(): ?int
    {
        $product = $this->route('product');

        return is_object($product) ? $product->id : (is_numeric($product) ? (int) $product : null);
    }
}
