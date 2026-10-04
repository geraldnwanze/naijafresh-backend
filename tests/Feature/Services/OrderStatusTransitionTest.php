<?php

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderStatusService;

it('allows a forward step through the fulfilment pipeline', function (): void {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed))->toBeTrue()
        ->and(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Processing))->toBeTrue()
        ->and(OrderStatus::Preparing->canTransitionTo(OrderStatus::ReadyForPickup))->toBeTrue();
});

it('rejects skipping a step or moving backwards', function (): void {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Delivered))->toBeFalse()
        ->and(OrderStatus::Delivered->canTransitionTo(OrderStatus::Preparing))->toBeFalse()
        ->and(OrderStatus::Delivered->allowedTransitions())->toBe([]);
});

it('cannot cancel an order that is already out for delivery', function (): void {
    expect(OrderStatus::OutForDelivery->canTransitionTo(OrderStatus::Cancelled))->toBeFalse();
});

it('throws when the service is asked for an invalid transition', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(OrderStatusService::class)->transition($order, OrderStatus::Delivered);
})->throws(InvalidOrderTransitionException::class);

it('stamps timestamps and syncs the delivery record on transition', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    $order->delivery()->create(['status' => DeliveryStatus::Pending, 'fee_kobo' => $order->delivery_fee_kobo]);

    $service = app(OrderStatusService::class);
    $service->transition($order, OrderStatus::Processing);
    $service->transition($order->refresh(), OrderStatus::Preparing);

    $order->refresh();
    expect($order->prepared_at)->not->toBeNull()
        ->and($order->delivery->status)->toBe(DeliveryStatus::Preparing);
});

it('marks a cash-on-delivery payment paid when the order is delivered', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::OutForDelivery,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::CashOnDelivery,
        'status' => PaymentStatus::Pending,
        'amount_kobo' => $order->total_kobo,
    ]);

    app(OrderStatusService::class)->transition($order, OrderStatus::Delivered);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull();
});
