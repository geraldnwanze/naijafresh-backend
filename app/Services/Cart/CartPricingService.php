<?php

namespace App\Services\Cart;

use App\Exceptions\CartException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Turns a client-supplied cart (product id + optional variant + quantity) into
 * an authoritative set of priced lines and totals. Prices are always read from
 * the database here; client-supplied prices are never trusted.
 */
class CartPricingService
{
    public function __construct(private readonly DeliveryFeeCalculator $deliveryFee) {}

    /**
     * Quantities are item counts, except for weight-sold products where the
     * quantity is grams and the unit price is per kilogram (see Product).
     *
     * @param  iterable<array{product_id: int|string, variant_id?: int|string|null, quantity: int|string}>  $items
     * @return array{
     *     currency: string,
     *     lines: list<array{product: Product, variant: ProductVariant|null, quantity: int, unit_price_kobo: int, line_total_kobo: int, unit_cost_kobo: int|null, line_cost_kobo: int|null}>,
     *     item_count: int,
     *     subtotal_kobo: int,
     *     delivery_fee_kobo: int,
     *     discount_kobo: int,
     *     total_kobo: int
     * }
     */
    public function price(iterable $items, bool $enforceStock = true): array
    {
        /** @var Collection<int, array{product_id: int, variant_id: ?int, quantity: int}> $normalised */
        $normalised = collect($items)
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'variant_id' => isset($item['variant_id']) && $item['variant_id'] !== null
                    ? (int) $item['variant_id']
                    : null,
                'quantity' => max(1, (int) $item['quantity']),
            ])
            ->values();

        if ($normalised->isEmpty()) {
            throw CartException::empty();
        }

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->with('variants')
            ->whereIn('id', $normalised->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($normalised as $item) {
            $product = $products->get($item['product_id']);

            if (! $product instanceof Product) {
                throw new CartException('One of the products in your cart no longer exists.', [
                    'items' => ['One of the products in your cart no longer exists.'],
                ]);
            }

            if (! $product->is_available) {
                throw CartException::unavailable($product->name);
            }

            if (($quantityError = $product->quantityError($item['quantity'])) !== null) {
                throw CartException::invalidQuantity($quantityError);
            }

            if ($enforceStock && $product->stock_quantity < $item['quantity']) {
                throw CartException::insufficientStock($product, $product->stock_quantity);
            }

            $variant = null;

            if ($item['variant_id'] !== null) {
                $variant = $product->variants->firstWhere('id', $item['variant_id']);

                if (! $variant instanceof ProductVariant) {
                    throw new CartException("Selected option for \"{$product->name}\" is not available.", [
                        'items' => ["Selected option for \"{$product->name}\" is not available."],
                    ]);
                }
            }

            $unitPrice = $product->price_kobo + ($variant?->price_delta_kobo ?? 0);

            // Unknown until the product has a cost price; never exposed to customers.
            $unitCost = $product->cost_price_kobo === null
                ? null
                : max(0, $product->cost_price_kobo + ($variant?->cost_delta_kobo ?? 0));

            $lines[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $item['quantity'],
                'unit_price_kobo' => $unitPrice,
                'line_total_kobo' => $product->lineTotalKobo($unitPrice, $item['quantity']),
                'unit_cost_kobo' => $unitCost,
                'line_cost_kobo' => $unitCost === null ? null : $product->lineTotalKobo($unitCost, $item['quantity']),
            ];
        }

        $subtotal = array_sum(array_column($lines, 'line_total_kobo'));
        $deliveryFee = $this->deliveryFee->forSubtotal($subtotal);
        $discount = 0;

        return [
            'currency' => config('naijafresh.currency'),
            'lines' => $lines,
            'item_count' => $this->countItems($lines),
            'subtotal_kobo' => $subtotal,
            'delivery_fee_kobo' => $deliveryFee,
            'discount_kobo' => $discount,
            'total_kobo' => $subtotal + $deliveryFee - $discount,
        ];
    }

    /**
     * Number of things being bought: item counts for unit lines, and one per
     * weight-sold line (its quantity is grams, not a count).
     *
     * @param  list<array{product: Product, quantity: int}>  $lines
     */
    private function countItems(array $lines): int
    {
        return array_sum(array_map(
            fn (array $line): int => $line['product']->isSoldByWeight() ? 1 : $line['quantity'],
            $lines,
        ));
    }
}
