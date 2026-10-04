<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the customer when staff move an order along. Every step shows in the
 * bell; only the moments that matter to the customer are also emailed, so
 * their inbox isn't flooded with internal steps like "processing".
 */
class OrderStatusChangedNotification extends OrderNotification
{
    /** Statuses worth an email. */
    private const EMAILED = [
        OrderStatus::Confirmed,
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
        OrderStatus::Cancelled,
    ];

    public function via(object $notifiable): array
    {
        return in_array($this->order->status, self::EMAILED, true)
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['items', 'delivery', 'payment']);

        return (new MailMessage)
            ->subject($this->emailSubject())
            ->markdown('mail.orders.status', [
                'order' => $this->order,
                'orderUrl' => $this->orderUrl(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        [$title, $body] = match ($this->order->status) {
            OrderStatus::Confirmed => ['Order confirmed', 'We\'ve confirmed your order and will start preparing it.'],
            OrderStatus::Processing => ['Order being processed', 'We\'re sourcing your ingredients.'],
            OrderStatus::Preparing => ['Preparing your order', 'We\'re washing, cutting and packing your order.'],
            OrderStatus::ReadyForPickup => ['Order packed', 'Your order is packed and waiting for a rider.'],
            OrderStatus::OutForDelivery => ['Out for delivery', 'Your order is on its way to you.'],
            OrderStatus::Delivered => ['Order delivered', 'Your order has been delivered. Enjoy!'],
            OrderStatus::Cancelled => ['Order cancelled', $this->order->cancellation_reason ?: 'Your order was cancelled.'],
            default => ['Order update', "Your order is now {$this->order->status->label()}."],
        };

        return $this->payload('order_status', "{$title} · {$this->order->reference}", $body);
    }

    private function emailSubject(): string
    {
        return match ($this->order->status) {
            OrderStatus::Confirmed => "Your NaijaFresh order {$this->order->reference} is confirmed",
            OrderStatus::OutForDelivery => "Your order {$this->order->reference} is on its way",
            OrderStatus::Delivered => "Your order {$this->order->reference} has been delivered",
            OrderStatus::Cancelled => "Your order {$this->order->reference} was cancelled",
            default => "Update on your order {$this->order->reference}",
        };
    }
}
