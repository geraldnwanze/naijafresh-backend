<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Notifications\Concerns\QueuesEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for notifications about an order. Delivery rules (bell now, email
 * queued) live in QueuesEmail. These go to the customer by default; staff
 * notifications override mailRecipient().
 */
abstract class OrderNotification extends Notification implements ShouldQueue
{
    use QueuesEmail;

    public function __construct(public Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Where the email goes. Customers get it at the address given at checkout,
     * which may differ from the account email (e.g. ordering for family).
     */
    public function mailRecipient(User $notifiable): string
    {
        return $this->order->contact_email;
    }

    /**
     * Stored in the `notifications` table and returned by the notifications API.
     *
     * @return array{kind: string, title: string, body: string, url: string, order_id: int, order_reference: string}
     */
    abstract public function toArray(object $notifiable): array;

    /**
     * The order page on the storefront.
     */
    public function orderUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$this->orderPath();
    }

    /**
     * The in-app route the bell links to.
     */
    protected function orderPath(): string
    {
        return '/orders/'.$this->order->id;
    }

    /**
     * @return array{kind: string, title: string, body: string, url: string, order_id: int, order_reference: string}
     */
    protected function payload(string $kind, string $title, string $body, ?string $url = null): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'url' => $url ?? $this->orderPath(),
            'order_id' => $this->order->id,
            'order_reference' => $this->order->reference,
        ];
    }
}
