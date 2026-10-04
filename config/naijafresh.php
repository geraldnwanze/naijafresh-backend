<?php

use App\Enums\PaymentMethod;

return [

    'currency' => env('NAIJAFRESH_CURRENCY', 'NGN'),

    // Timezone the business operates in. Accounting reports cut days, weeks and
    // months at local midnight even though timestamps are stored in UTC.
    'timezone' => env('NAIJAFRESH_TIMEZONE', 'Africa/Lagos'),

    'store' => [
        'name' => 'NaijaFresh',
        'tagline' => 'Your Nigerian kitchen, prepared for you.',
        'default_country' => 'Nigeria',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    | All money is stored and calculated in kobo (minor units). These are the
    | seed defaults; the effective values are resolved at runtime from the
    | settings table so the business can change them from the admin panel.
    */
    'delivery' => [
        'fee_kobo' => (int) env('NAIJAFRESH_DELIVERY_FEE', 200_000),
        'free_threshold_kobo' => (int) env('NAIJAFRESH_FREE_DELIVERY_THRESHOLD', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | System logs (super admin area)
    |--------------------------------------------------------------------------
    | The audit trail (who changed what) is kept forever. Activity logs
    | (sign-ins, orders, payments) are pruned after the retention window. The
    | application log viewer reads the newest `max_read_bytes` of a log file.
    */
    'logs' => [
        'activity_retention_days' => (int) env('NAIJAFRESH_ACTIVITY_RETENTION_DAYS', 90),
        'directory' => storage_path('logs'),
        'max_read_bytes' => (int) env('NAIJAFRESH_LOG_READ_BYTES', 5 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    | A product is "low" at or below these levels and admins are alerted when a
    | sale takes it there. Unit products count items; weight-sold products
    | count grams (stock is stored in grams).
    */
    'inventory' => [
        'low_stock_units' => (int) env('NAIJAFRESH_LOW_STOCK_UNITS', 5),
        'low_stock_grams' => (int) env('NAIJAFRESH_LOW_STOCK_GRAMS', 5_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    | provider: "paystack" uses the live gateway, "mock" runs a fully testable
    | in-app payment simulation. If PAYMENT_PROVIDER is unset it is inferred:
    | paystack when a secret key is present, otherwise mock.
    */
    'payments' => [
        'provider' => env('PAYMENT_PROVIDER') ?: (env('PAYSTACK_SECRET_KEY') ? 'paystack' : 'mock'),

        'methods' => [
            PaymentMethod::Paystack->value => true,
            PaymentMethod::BankTransfer->value => (bool) env('NAIJAFRESH_ENABLE_BANK_TRANSFER', true),
            PaymentMethod::CashOnDelivery->value => (bool) env('NAIJAFRESH_ENABLE_CASH_ON_DELIVERY', true),
        ],

        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],
    ],

];
