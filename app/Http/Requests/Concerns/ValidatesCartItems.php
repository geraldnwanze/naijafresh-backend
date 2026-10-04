<?php

namespace App\Http\Requests\Concerns;

trait ValidatesCartItems
{
    /**
     * @return array<string, mixed>
     */
    protected function cartItemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            // Item count, or grams for weight-sold products; per-product limits
            // are enforced in CartPricingService.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:200000'],
        ];
    }
}
