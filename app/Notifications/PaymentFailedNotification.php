<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the customer when an online payment did not go through.
 */
class PaymentFailedNotification extends OrderNotification
{
    public function __construct(Order $order, public Payment $payment)
    {
        parent::__construct($order);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment for order {$this->order->reference} didn't go through")
            ->markdown('mail.orders.payment-failed', [
                'order' => $this->order,
                'payment' => $this->payment,
                'orderUrl' => $this->orderUrl(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'payment_failed',
            "Payment failed · {$this->order->reference}",
            'Your payment didn\'t go through. Open the order to try again.',
        );
    }
}
