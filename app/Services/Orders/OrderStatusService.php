<?php

namespace App\Services\Orders;

use App\Enums\ActivityEvent;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Services\Audit\ActivityLogger;
use App\Services\Notifications\OrderNotifier;
use App\Support\Analytics;
use Illuminate\Support\Facades\DB;

/**
 * Owns the order-status state machine and the side effects of each move
 * (timestamps, the linked delivery record, cash-on-delivery settlement).
 */
class OrderStatusService
{
    public function __construct(
        private readonly Analytics $analytics,
        private readonly OrderNotifier $notifier,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{reason?: string|null, rider_name?: string|null, rider_phone?: string|null, notify?: bool}  $context
     *                                                                                                                    `notify: false` skips the customer notification (used when another message already covers the change).
     */
    public function transition(Order $order, OrderStatus $target, array $context = []): Order
    {
        $current = $order->status;

        if ($current === $target) {
            return $order;
        }

        if (! $current->canTransitionTo($target)) {
            throw new InvalidOrderTransitionException($current, $target);
        }

        return DB::transaction(function () use ($order, $current, $target, $context): Order {
            $order->status = $target;
            $this->stampTimestamps($order, $target, $context);
            $order->save();

            $this->syncDelivery($order, $target, $context);
            $this->settleCashOnDelivery($order, $target);

            $this->analytics->track('order_status_changed', [
                'order_reference' => $order->reference,
                'status' => $target->value,
            ]);

            $order->refresh();

            $this->activity->log(ActivityEvent::OrderStatusChanged, "Order {$order->reference}: {$current->label()} → {$target->label()}", $order, [
                'from' => $current->value,
                'to' => $target->value,
            ]);

            if (($context['notify'] ?? true) !== false) {
                $this->notifier->statusChanged($order);
            }

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function stampTimestamps(Order $order, OrderStatus $target, array $context): void
    {
        match ($target) {
            OrderStatus::Confirmed => $order->confirmed_at ??= now(),
            OrderStatus::Preparing => $order->prepared_at ??= now(),
            OrderStatus::Delivered => $order->delivered_at ??= now(),
            OrderStatus::Cancelled => $order->cancelled_at ??= now(),
            default => null,
        };

        if ($target === OrderStatus::Cancelled && isset($context['reason'])) {
            $order->cancellation_reason = $context['reason'];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function syncDelivery(Order $order, OrderStatus $target, array $context): void
    {
        $deliveryStatus = match ($target) {
            OrderStatus::Preparing => DeliveryStatus::Preparing,
            OrderStatus::ReadyForPickup => DeliveryStatus::ReadyForPickup,
            OrderStatus::OutForDelivery => DeliveryStatus::OutForDelivery,
            OrderStatus::Delivered => DeliveryStatus::Delivered,
            default => null,
        };

        if ($deliveryStatus === null && ! isset($context['rider_name'], $context['rider_phone'])) {
            return;
        }

        $delivery = $order->delivery()->firstOrNew([]);

        if ($deliveryStatus !== null) {
            $delivery->status = $deliveryStatus;
        }

        if ($target === OrderStatus::OutForDelivery) {
            $delivery->dispatched_at ??= now();
        }

        if ($target === OrderStatus::Delivered) {
            $delivery->delivered_at ??= now();
        }

        foreach (['rider_name', 'rider_phone'] as $field) {
            if (array_key_exists($field, $context) && $context[$field] !== null) {
                $delivery->{$field} = $context[$field];
            }
        }

        $delivery->fee_kobo = $order->delivery_fee_kobo;
        $delivery->window_label ??= $order->delivery_window_label;
        $delivery->window_time ??= $order->delivery_window_time;
        $delivery->scheduled_date ??= $order->delivery_date;

        $order->delivery()->save($delivery);
    }

    private function settleCashOnDelivery(Order $order, OrderStatus $target): void
    {
        if ($target !== OrderStatus::Delivered) {
            return;
        }

        $payment = $order->payment;

        if ($payment && $payment->method === PaymentMethod::CashOnDelivery && ! $payment->isPaid()) {
            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ])->save();
        }
    }
}
