<?php

namespace App\Services\Cart;

use App\Services\StoreSettings;

/**
 * Resolves the delivery fee for an order subtotal. Kept as its own class so
 * that area-based or distance-based pricing can be added later without
 * touching cart pricing.
 */
class DeliveryFeeCalculator
{
    public function __construct(private readonly StoreSettings $settings) {}

    public function forSubtotal(int $subtotalKobo): int
    {
        $threshold = $this->settings->freeDeliveryThresholdKobo();

        if ($threshold > 0 && $subtotalKobo >= $threshold) {
            return 0;
        }

        return $this->settings->deliveryFeeKobo();
    }
}
