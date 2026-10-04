<?php

namespace Database\Seeders;

use App\Services\StoreSettings;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(StoreSettings $settings): void
    {
        $settings->setMany([
            StoreSettings::DELIVERY_FEE_KOBO => (int) config('naijafresh.delivery.fee_kobo'),
            StoreSettings::FREE_DELIVERY_THRESHOLD_KOBO => (int) config('naijafresh.delivery.free_threshold_kobo'),
            StoreSettings::CASH_ON_DELIVERY_ENABLED => (bool) config('naijafresh.payments.methods.cash_on_delivery'),
            StoreSettings::BANK_TRANSFER_ENABLED => (bool) config('naijafresh.payments.methods.bank_transfer'),
            StoreSettings::STORE_OPEN => true,
        ]);
    }
}
