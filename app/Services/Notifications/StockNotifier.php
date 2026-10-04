<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Notifications\LowStockNotification;

/**
 * Turns "a sale pushed products down a stock level" into one admin alert per
 * sale (bell + queued email), however many products crossed a line.
 */
class StockNotifier extends Notifier
{
    /**
     * @param  list<array{product_id: int, name: string, level: string, stock_label: string}>  $crossings
     */
    public function lowStock(array $crossings, ?Order $order = null): void
    {
        if ($crossings === []) {
            return;
        }

        $this->send($this->admins(), new LowStockNotification($crossings, $order?->reference));
    }
}
