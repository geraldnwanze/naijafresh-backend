<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the customer when a payment for their order is confirmed.
 */
class PaymentReceivedNotification extends OrderNotification
{
    public function __construct(Order $order, public Payment $payment)
    {
        parent::__construct($order);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['items', 'delivery', 'payment']);

        return (new MailMessage)
            ->subject("Payment received for order {$this->order->reference}")
            ->markdown('mail.orders.payment-received', [
                'order' => $this->order,
                'payment' => $this->payment,
                'orderUrl' => $this->orderUrl(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'payment_received',
            "Payment received · {$this->order->reference}",
            'We received '.Money::format($this->payment->amount_kobo, $this->payment->currency).'. Your order is confirmed.',
        );
    }
}
