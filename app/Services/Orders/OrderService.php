<?php

namespace App\Services\Orders;

use App\Enums\ActivityEvent;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockLevel;
use App\Exceptions\CartException;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Cart\CartPricingService;
use App\Services\Notifications\OrderNotifier;
use App\Services\Notifications\StockNotifier;
use App\Support\Analytics;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;

/**
 * Creates orders from a validated checkout payload. All money is recalculated
 * from the database here — the client only supplies product ids and quantities.
 */
class OrderService
{
    public function __construct(
        private readonly CartPricingService $pricing,
        private readonly Analytics $analytics,
        private readonly OrderNotifier $notifier,
        private readonly StockNotifier $stockNotifier,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{
     *     contact: array{first_name: string, last_name: string, phone: string, email: string},
     *     delivery: array{street: string, area: string, city: string, state?: string|null, country?: string|null, notes?: string|null},
     *     delivery_window_id: int,
     *     delivery_date?: string|null,
     *     payment_method: string,
     *     items: list<array{product_id: int, variant_id?: int|null, quantity: int}>
     * }  $data
     */
    public function place(User $user, array $data): Order
    {
        $priced = $this->pricing->price($data['items']);
        $window = DeliveryWindow::query()->active()->findOrFail($data['delivery_window_id']);
        $paymentMethod = PaymentMethod::from($data['payment_method']);

        return DB::transaction(function () use ($user, $data, $priced, $window, $paymentMethod): Order {
            $stockCrossings = $this->assertStockAndDecrement($priced['lines']);

            $delivery = $data['delivery'];

            $order = new Order([
                'reference' => Reference::order(),
                'status' => OrderStatus::Pending,
                'contact_first_name' => $data['contact']['first_name'],
                'contact_last_name' => $data['contact']['last_name'],
                'contact_phone' => $data['contact']['phone'],
                'contact_email' => $data['contact']['email'],
                'delivery_street' => $delivery['street'],
                'delivery_area' => $delivery['area'],
                'delivery_city' => $delivery['city'],
                'delivery_state' => $delivery['state'] ?? null,
                'delivery_country' => $delivery['country'] ?? config('naijafresh.store.default_country'),
                'delivery_notes' => $delivery['notes'] ?? null,
                'delivery_window_id' => $window->id,
                'delivery_window_label' => $window->label,
                'delivery_window_time' => $window->displayTime(),
                'delivery_date' => $data['delivery_date'] ?? null,
                'payment_method' => $paymentMethod,
                'currency' => $priced['currency'],
                'subtotal_kobo' => $priced['subtotal_kobo'],
                'delivery_fee_kobo' => $priced['delivery_fee_kobo'],
                'discount_kobo' => $priced['discount_kobo'],
                'total_kobo' => $priced['total_kobo'],
                'placed_at' => now(),
            ]);

            $order->user()->associate($user);
            $order->save();

            foreach ($priced['lines'] as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $line['variant']?->id,
                    'name' => $product->name,
                    'type' => $product->type,
                    'sold_by' => $product->sold_by,
                    'storage_type' => $product->storage_type,
                    'unit' => $product->unit,
                    'variant_name' => $line['variant']?->name,
                    'image_url' => $product->image_url,
                    'unit_price_kobo' => $line['unit_price_kobo'],
                    'quantity' => $line['quantity'],
                    'line_total_kobo' => $line['line_total_kobo'],
                    'unit_cost_kobo' => $line['unit_cost_kobo'],
                    'line_cost_kobo' => $line['line_cost_kobo'],
                ]);
            }

            $order->delivery()->create([
                'status' => DeliveryStatus::Pending,
                'fee_kobo' => $priced['delivery_fee_kobo'],
                'window_label' => $window->label,
                'window_time' => $window->displayTime(),
                'scheduled_date' => $data['delivery_date'] ?? null,
            ]);

            $this->analytics->track('order_placed', [
                'order_reference' => $order->reference,
                'total_kobo' => $order->total_kobo,
                'item_count' => $priced['item_count'],
                'payment_method' => $paymentMethod->value,
            ]);

            $this->activity->log(ActivityEvent::OrderPlaced, "Order {$order->reference} placed", $order, [
                'total_kobo' => $order->total_kobo,
                'payment_method' => $paymentMethod->value,
            ], $user);

            $this->notifier->orderPlaced($order);
            $this->stockNotifier->lowStock($stockCrossings, $order);

            return $order->load(['items', 'delivery', 'deliveryWindow']);
        });
    }

    /**
     * Checks and takes stock for every line. Returns the products this sale
     * pushed to a worse stock level (ok → low, low → out…), so admins are
     * alerted once per crossing rather than on every sale of a low product.
     *
     * @param  list<array{product: Product, variant: mixed, quantity: int, unit_price_kobo: int, line_total_kobo: int, unit_cost_kobo: int|null, line_cost_kobo: int|null}>  $lines
     * @return list<array{product_id: int, name: string, level: string, stock_label: string}>
     */
    private function assertStockAndDecrement(array $lines): array
    {
        $crossings = [];

        foreach ($lines as $line) {
            /** @var Product $snapshot */
            $snapshot = $line['product'];

            /** @var Product|null $product */
            $product = Product::query()->whereKey($snapshot->id)->lockForUpdate()->first();

            if ($product === null || ! $product->is_available) {
                throw CartException::unavailable($snapshot->name);
            }

            if ($product->stock_quantity < $line['quantity']) {
                throw CartException::insufficientStock($product, $product->stock_quantity);
            }

            $levelBefore = $product->stockLevelFor($product->stock_quantity);

            $product->decrement('stock_quantity', $line['quantity']);

            if ($product->stock_quantity <= 0) {
                $product->forceFill(['is_available' => false])->save();
            }

            $levelAfter = $product->stockLevelFor($product->stock_quantity);

            if ($levelAfter->isWorseThan($levelBefore)) {
                $crossings[$product->id] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'level' => $levelAfter->value,
                    'stock_label' => $levelAfter === StockLevel::Out ? 'Out of stock' : $product->quantityLabel($product->stock_quantity).' left',
                ];
            }
        }

        return array_values($crossings);
    }
}
