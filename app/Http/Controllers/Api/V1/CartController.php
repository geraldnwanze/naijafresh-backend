<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PriceCartRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartPricingService;
use App\Support\Money;

class CartController extends Controller
{
    /**
     * Recalculate cart totals server-side. The client sends product ids and
     * quantities only; prices, availability and the delivery fee are resolved
     * here so the browser can never dictate the amount charged.
     */
    public function price(PriceCartRequest $request, CartPricingService $pricing)
    {
        $priced = $pricing->price($request->validated()['items'], enforceStock: false);

        return response()->json(['data' => $this->present($priced)]);
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    private function present(array $cart): array
    {
        return [
            'currency' => $cart['currency'],
            'item_count' => $cart['item_count'],
            'lines' => array_map(function (array $line): array {
                /** @var Product $product */
                $product = $line['product'];
                /** @var ProductVariant|null $variant */
                $variant = $line['variant'];

                return [
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'image_url' => $product->image_url,
                    'variant_id' => $variant?->id,
                    'variant_name' => $variant?->name,
                    'quantity' => $line['quantity'],
                    'quantity_label' => $product->quantityLabel($line['quantity']),
                    'sold_by' => $product->sold_by->value,
                    'storage_type' => $product->storage_type->value,
                    'unit_price_kobo' => $line['unit_price_kobo'],
                    'unit_price' => Money::format($line['unit_price_kobo']),
                    'line_total_kobo' => $line['line_total_kobo'],
                    'line_total' => Money::format($line['line_total_kobo']),
                    'in_stock' => $product->isPurchasable(),
                    'available_quantity' => $product->stock_quantity,
                ];
            }, $cart['lines']),
            'subtotal_kobo' => $cart['subtotal_kobo'],
            'subtotal' => Money::format($cart['subtotal_kobo']),
            'delivery_fee_kobo' => $cart['delivery_fee_kobo'],
            'delivery_fee' => Money::format($cart['delivery_fee_kobo']),
            'discount_kobo' => $cart['discount_kobo'],
            'discount' => Money::format($cart['discount_kobo']),
            'total_kobo' => $cart['total_kobo'],
            'total' => Money::format($cart['total_kobo']),
        ];
    }
}
