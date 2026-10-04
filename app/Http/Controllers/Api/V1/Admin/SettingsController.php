<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\StoreSettings;
use App\Support\Money;

class SettingsController extends Controller
{
    public function show(StoreSettings $settings)
    {
        return response()->json(['data' => $this->present($settings)]);
    }

    public function update(UpdateSettingsRequest $request, StoreSettings $settings)
    {
        $data = $request->validated();

        $settings->setMany([
            StoreSettings::DELIVERY_FEE_KOBO => (int) $data['delivery_fee_kobo'],
            StoreSettings::FREE_DELIVERY_THRESHOLD_KOBO => (int) $data['free_delivery_threshold_kobo'],
            StoreSettings::CASH_ON_DELIVERY_ENABLED => (bool) $data['cash_on_delivery_enabled'],
            StoreSettings::BANK_TRANSFER_ENABLED => (bool) $data['bank_transfer_enabled'],
            StoreSettings::STORE_OPEN => (bool) $data['store_open'],
        ]);

        return response()->json(['data' => $this->present($settings)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoreSettings $settings): array
    {
        return [
            'delivery_fee_kobo' => $settings->deliveryFeeKobo(),
            'delivery_fee' => Money::format($settings->deliveryFeeKobo()),
            'free_delivery_threshold_kobo' => $settings->freeDeliveryThresholdKobo(),
            'cash_on_delivery_enabled' => (bool) $settings->get(StoreSettings::CASH_ON_DELIVERY_ENABLED),
            'bank_transfer_enabled' => (bool) $settings->get(StoreSettings::BANK_TRANSFER_ENABLED),
            'store_open' => $settings->isStoreOpen(),
        ];
    }
}
