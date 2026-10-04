<?php

namespace App\Services\Payments;

use App\Enums\ActivityEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Audit\ActivityLogger;
use App\Services\Notifications\OrderNotifier;
use App\Services\Orders\OrderStatusService;
use App\Support\Analytics;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly OrderStatusService $orderStatus,
        private readonly Analytics $analytics,
        private readonly OrderNotifier $notifier,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Create the pending Payment row for a freshly placed order.
     */
    public function createForOrder(Order $order): Payment
    {
        $method = $order->payment_method;

        $provider = $method === PaymentMethod::Paystack
            ? $this->gateways->defaultDriver()
            : 'manual';

        return $order->payment()->create([
            'provider' => $provider,
            'method' => $method,
            'status' => PaymentStatus::Pending,
            'currency' => $order->currency,
            'amount_kobo' => $order->total_kobo,
            'reference' => Reference::payment(),
            'meta' => [],
        ]);
    }

    /**
     * Begin an online payment. Returns null for offline methods (bank transfer,
     * cash on delivery) which are settled manually later.
     *
     * @return array<string, mixed>|null
     */
    public function initialize(Payment $payment): ?array
    {
        if ($payment->method !== PaymentMethod::Paystack) {
            return null;
        }

        $gateway = $this->gateways->driver($payment->provider);
        $init = $gateway->initialize($payment);

        $payment->forceFill([
            'status' => PaymentStatus::Processing,
            'provider_reference' => $init['provider_reference'],
            'authorization_url' => $init['authorization_url'],
            'meta' => array_merge($payment->meta ?? [], ['initialization' => $init['meta']]),
        ])->save();

        return $init;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function verify(Payment $payment, array $context = []): array
    {
        if ($payment->method !== PaymentMethod::Paystack) {
            return [
                'status' => $payment->status,
                'provider_reference' => $payment->provider_reference,
                'paid_at' => $payment->paid_at,
                'raw' => [],
            ];
        }

        $gateway = $this->gateways->driver($payment->provider);
        $verification = $gateway->verify($payment, $context);

        $this->applyVerification($payment, $verification);

        return $verification;
    }

    /**
     * @param  array{status: PaymentStatus, provider_reference?: string|null, paid_at?: CarbonImmutable|null, raw?: array<string, mixed>}  $verification
     */
    public function applyVerification(Payment $payment, array $verification): void
    {
        $isPaid = $verification['status'] === PaymentStatus::Paid;
        // The same result can arrive twice (customer return + gateway webhook);
        // only the first one should notify the customer.
        $changed = $payment->status !== $verification['status'];

        DB::transaction(function () use ($payment, $verification, $isPaid, $changed): void {
            $payment->forceFill([
                'status' => $verification['status'],
                'provider_reference' => $verification['provider_reference'] ?? $payment->provider_reference,
                'paid_at' => $isPaid ? ($verification['paid_at'] ?? now()) : $payment->paid_at,
                'meta' => array_merge($payment->meta ?? [], ['verification' => $verification['raw'] ?? []]),
            ])->save();

            if ($isPaid) {
                $this->onPaid($payment, notify: $changed);
            } elseif ($changed && $verification['status'] === PaymentStatus::Failed) {
                $this->notifier->paymentFailed($payment);
                $this->activity->log(ActivityEvent::PaymentFailed, "Payment failed for order {$payment->order->reference}", $payment->order, [
                    'payment_reference' => $payment->reference,
                    'amount_kobo' => $payment->amount_kobo,
                ]);
            }
        });
    }

    /**
     * Admin confirms an offline payment (bank transfer received, etc.).
     */
    public function confirmManualPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $this->onPaid($payment, notify: true);
        });
    }

    private function onPaid(Payment $payment, bool $notify): void
    {
        $order = $payment->order;

        $this->analytics->track('payment_completed', [
            'order_reference' => $order->reference,
            'amount_kobo' => $payment->amount_kobo,
            'provider' => $payment->provider,
        ]);

        if ($order->status === OrderStatus::Pending) {
            // The "payment received" message already tells the customer the
            // order is confirmed, so skip the separate status notification.
            $this->orderStatus->transition($order, OrderStatus::Confirmed, ['notify' => false]);
        }

        if ($notify) {
            $this->notifier->paymentReceived($payment);
            $this->activity->log(ActivityEvent::PaymentSucceeded, "Payment received for order {$order->reference}", $order, [
                'payment_reference' => $payment->reference,
                'amount_kobo' => $payment->amount_kobo,
                'method' => $payment->method->value,
            ]);
        }
    }
}
