<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime business configuration. Values live in the `settings` table so the
 * admin can change them without a deploy; config/naijafresh.php provides the
 * fallback defaults. Reads are cached for the duration of the request cycle.
 */
class StoreSettings
{
    private const CACHE_KEY = 'store_settings';

    public const DELIVERY_FEE_KOBO = 'delivery_fee_kobo';

    public const FREE_DELIVERY_THRESHOLD_KOBO = 'free_delivery_threshold_kobo';

    public const CASH_ON_DELIVERY_ENABLED = 'cash_on_delivery_enabled';

    public const BANK_TRANSFER_ENABLED = 'bank_transfer_enabled';

    public const STORE_OPEN = 'store_open';

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
            $stored = Setting::query()->pluck('value', 'key')->all();

            return array_merge($this->defaults(), $stored);
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function deliveryFeeKobo(): int
    {
        return (int) $this->get(self::DELIVERY_FEE_KOBO, config('naijafresh.delivery.fee_kobo'));
    }

    public function freeDeliveryThresholdKobo(): int
    {
        return (int) $this->get(self::FREE_DELIVERY_THRESHOLD_KOBO, config('naijafresh.delivery.free_threshold_kobo'));
    }

    public function isStoreOpen(): bool
    {
        return (bool) $this->get(self::STORE_OPEN, true);
    }

    /**
     * Enabled payment methods, keyed by PaymentMethod value.
     *
     * @return array<string, bool>
     */
    public function enabledPaymentMethods(): array
    {
        return [
            PaymentMethod::Paystack->value => (bool) config('naijafresh.payments.methods.'.PaymentMethod::Paystack->value),
            PaymentMethod::BankTransfer->value => (bool) $this->get(self::BANK_TRANSFER_ENABLED, config('naijafresh.payments.methods.'.PaymentMethod::BankTransfer->value)),
            PaymentMethod::CashOnDelivery->value => (bool) $this->get(self::CASH_ON_DELIVERY_ENABLED, config('naijafresh.payments.methods.'.PaymentMethod::CashOnDelivery->value)),
        ];
    }

    public function paymentMethodEnabled(PaymentMethod $method): bool
    {
        return $this->enabledPaymentMethods()[$method->value] ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            self::DELIVERY_FEE_KOBO => (int) config('naijafresh.delivery.fee_kobo'),
            self::FREE_DELIVERY_THRESHOLD_KOBO => (int) config('naijafresh.delivery.free_threshold_kobo'),
            self::CASH_ON_DELIVERY_ENABLED => (bool) config('naijafresh.payments.methods.'.PaymentMethod::CashOnDelivery->value),
            self::BANK_TRANSFER_ENABLED => (bool) config('naijafresh.payments.methods.'.PaymentMethod::BankTransfer->value),
            self::STORE_OPEN => true,
        ];
    }
}
