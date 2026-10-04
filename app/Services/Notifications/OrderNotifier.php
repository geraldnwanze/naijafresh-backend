<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\Payment;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentReceivedNotification;

/**
 * The one place order events turn into notifications (delivery guarantees are
 * documented on Notifier).
 */
class OrderNotifier extends Notifier
{
    public function orderPlaced(Order $order): void
    {
        $this->send($order->user, new OrderPlacedNotification($order));
        $this->send($this->admins(), new NewOrderNotification($order));
    }

    public function statusChanged(Order $order): void
    {
        $this->send($order->user, new OrderStatusChangedNotification($order));
    }

    public function paymentReceived(Payment $payment): void
    {
        $this->send($payment->order->user, new PaymentReceivedNotification($payment->order, $payment));
    }

    public function paymentFailed(Payment $payment): void
    {
        $this->send($payment->order->user, new PaymentFailedNotification($payment->order, $payment));
    }
}
