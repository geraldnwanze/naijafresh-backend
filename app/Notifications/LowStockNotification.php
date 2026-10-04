<?php

namespace App\Notifications;

use App\Enums\StockLevel;
use App\Models\User;
use App\Notifications\Concerns\QueuesEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells admins that a sale pushed one or more products to low or out of stock.
 *
 * Carries plain snapshots (not models) so the message says what stock looked
 * like at the moment of the sale, even if it is restocked before the email goes.
 */
class LowStockNotification extends Notification implements ShouldQueue
{
    use QueuesEmail;

    /**
     * @param  list<array{product_id: int, name: string, level: string, stock_label: string}>  $products
     */
    public function __construct(
        public array $products,
        public ?string $orderReference = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && ! $notifiable->receivesStaffEmails() ? ['database'] : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->markdown('mail.admin.low-stock', [
                'products' => $this->products,
                'orderReference' => $this->orderReference,
                'inventoryUrl' => rtrim((string) config('app.frontend_url'), '/').'/admin/inventory',
            ]);
    }

    /**
     * @return array{kind: string, title: string, body: string, url: string, order_id: null, order_reference: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'low_stock',
            'title' => $this->title(),
            'body' => collect($this->products)
                ->map(fn (array $p): string => "{$p['name']}: {$p['stock_label']}")
                ->implode(' · '),
            'url' => '/admin/inventory',
            'order_id' => null,
            'order_reference' => $this->orderReference,
        ];
    }

    private function title(): string
    {
        $count = count($this->products);

        if ($count === 1) {
            $product = $this->products[0];

            return ($product['level'] === StockLevel::Out->value ? 'Out of stock: ' : 'Low stock: ').$product['name'];
        }

        return "Restock needed: {$count} products running low";
    }
}
