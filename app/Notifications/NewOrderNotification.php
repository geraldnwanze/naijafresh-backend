<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells staff a new order has come in: a bell entry plus an email to the
 * admin's own account address (not the customer's contact email).
 */
class NewOrderNotification extends OrderNotification
{
    /**
     * Bell for every staff account; email only for ordinary admins.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && ! $notifiable->receivesStaffEmails() ? ['database'] : ['database', 'mail'];
    }

    public function mailRecipient(User $notifiable): string
    {
        return $notifiable->email;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['items', 'delivery', 'payment']);

        return (new MailMessage)
            ->subject("New order {$this->order->reference} · ".Money::format($this->order->total_kobo, $this->order->currency))
            ->markdown('mail.admin.new-order', [
                'order' => $this->order,
                'adminUrl' => $this->adminUrl(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $this->order->loadMissing('items');

        $frozen = $this->order->items->contains(fn ($item) => $item->storage_type->value === 'frozen');

        return $this->payload(
            'new_order',
            "New order {$this->order->reference}",
            "{$this->order->contact_first_name} {$this->order->contact_last_name} · ".Money::format($this->order->total_kobo, $this->order->currency).($frozen ? ' · includes frozen items' : ''),
            $this->adminPath(),
        );
    }

    private function adminPath(): string
    {
        return '/admin/orders/'.$this->order->id;
    }

    private function adminUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$this->adminPath();
    }
}
