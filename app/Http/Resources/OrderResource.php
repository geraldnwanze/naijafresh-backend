<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\StorageType;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $showProfit = ($request->user()?->isAdmin() ?? false) && $this->relationLoaded('items');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_cancelled' => $this->status === OrderStatus::Cancelled,
            'cancellation_reason' => $this->cancellation_reason,

            'contact' => [
                'first_name' => $this->contact_first_name,
                'last_name' => $this->contact_last_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],

            'delivery_address' => [
                'street' => $this->delivery_street,
                'area' => $this->delivery_area,
                'city' => $this->delivery_city,
                'state' => $this->delivery_state,
                'country' => $this->delivery_country,
                'notes' => $this->delivery_notes,
            ],

            'delivery_window' => [
                'label' => $this->delivery_window_label,
                'time' => $this->delivery_window_time,
                'date' => $this->delivery_date?->toDateString(),
            ],

            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),

            'currency' => $this->currency,
            'subtotal_kobo' => $this->subtotal_kobo,
            'subtotal' => Money::format($this->subtotal_kobo, $this->currency),
            'delivery_fee_kobo' => $this->delivery_fee_kobo,
            'delivery_fee' => Money::format($this->delivery_fee_kobo, $this->currency),
            'discount_kobo' => $this->discount_kobo,
            'discount' => Money::format($this->discount_kobo, $this->currency),
            'total_kobo' => $this->total_kobo,
            'total' => Money::format($this->total_kobo, $this->currency),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'has_frozen_items' => $this->whenLoaded(
                'items',
                fn (): bool => $this->items->contains(fn ($item) => $item->storage_type === StorageType::Frozen),
            ),
            // Staff only: profit on the products sold (delivery fee excluded).
            'cost_kobo' => $this->when($showProfit, fn () => (int) $this->items->sum('line_cost_kobo')),
            'profit_kobo' => $this->when(
                $showProfit,
                fn () => $this->subtotal_kobo - $this->discount_kobo - (int) $this->items->sum('line_cost_kobo'),
            ),
            'has_uncosted_items' => $this->when(
                $showProfit,
                fn () => $this->items->contains(fn ($item) => $item->line_cost_kobo === null),
            ),

            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'delivery' => new DeliveryResource($this->whenLoaded('delivery')),

            'timeline' => $this->timeline(),

            'placed_at' => $this->placed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A customer-facing progress timeline.
     *
     * @return list<array{key: string, label: string, state: string, at: string|null}>
     */
    private function timeline(): array
    {
        $milestones = [
            ['key' => OrderStatus::Pending->value, 'label' => 'Order placed', 'at' => $this->placed_at],
            ['key' => OrderStatus::Confirmed->value, 'label' => 'Order confirmed', 'at' => $this->confirmed_at],
            ['key' => OrderStatus::Preparing->value, 'label' => 'Preparing your order', 'at' => $this->prepared_at],
            ['key' => OrderStatus::OutForDelivery->value, 'label' => 'Out for delivery', 'at' => $this->delivery?->dispatched_at],
            ['key' => OrderStatus::Delivered->value, 'label' => 'Delivered', 'at' => $this->delivered_at],
        ];

        if ($this->status === OrderStatus::Cancelled) {
            return [
                ['key' => 'pending', 'label' => 'Order placed', 'state' => 'done', 'at' => $this->placed_at?->toIso8601String()],
                ['key' => 'cancelled', 'label' => 'Order cancelled', 'state' => 'current', 'at' => $this->cancelled_at?->toIso8601String()],
            ];
        }

        $pipeline = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::pipeline());
        $currentIndex = array_search($this->status->value, $pipeline, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;

        return array_map(function (array $milestone) use ($pipeline, $currentIndex): array {
            $index = array_search($milestone['key'], $pipeline, true);
            $index = $index === false ? 0 : $index;

            $state = match (true) {
                $index < $currentIndex => 'done',
                $index === $currentIndex => 'current',
                default => 'upcoming',
            };

            if ($milestone['at'] !== null) {
                $state = $state === 'upcoming' ? 'done' : $state;
            }

            return [
                'key' => $milestone['key'],
                'label' => $milestone['label'],
                'state' => $state,
                'at' => $milestone['at']?->toIso8601String(),
            ];
        }, $milestones);
    }
}
