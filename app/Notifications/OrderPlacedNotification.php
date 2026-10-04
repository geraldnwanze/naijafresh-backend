<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the customer as soon as an order is placed.
 */
class OrderPlacedNotification extends OrderNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['items', 'delivery', 'payment']);

        return (new MailMessage)
            ->subject("We've received your order {$this->order->reference}")
            ->markdown('mail.orders.placed', [
                'order' => $this->order,
                'orderUrl' => $this->orderUrl(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'order_placed',
            "Order {$this->order->reference} placed",
            'Total '.Money::format($this->order->total_kobo, $this->order->currency).'. We\'ll let you know as it progresses.',
        );
    }
}
