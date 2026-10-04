<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\StoreSettings;
use App\Support\Money;

class StoreConfigController extends Controller
{
    /**
     * Public storefront configuration: currency, delivery fee, which payment
     * methods are on, and whether payments are running in mock mode.
     */
    public function __invoke(StoreSettings $settings, PaymentGatewayManager $gateways)
    {
        $deliveryFee = $settings->deliveryFeeKobo();
        $threshold = $settings->freeDeliveryThresholdKobo();

        $methods = collect($settings->enabledPaymentMethods())
            ->filter()
            ->keys()
            ->map(fn (string $value) => [
                'value' => $value,
                'label' => PaymentMethod::from($value)->label(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'store_name' => config('naijafresh.store.name'),
                'tagline' => config('naijafresh.store.tagline'),
                'currency' => config('naijafresh.currency'),
                'store_open' => $settings->isStoreOpen(),
                'default_country' => config('naijafresh.store.default_country'),
                'delivery' => [
                    'fee_kobo' => $deliveryFee,
                    'fee' => Money::format($deliveryFee),
                    'free_threshold_kobo' => $threshold,
                    'free_threshold' => $threshold > 0 ? Money::format($threshold) : null,
                ],
                'payment' => [
                    'provider' => $gateways->defaultDriver(),
                    'is_mock' => $gateways->isMock(),
                    'paystack_public_key' => config('naijafresh.payments.paystack.public_key'),
                    'methods' => $methods,
                ],
            ],
        ]);
    }
}
