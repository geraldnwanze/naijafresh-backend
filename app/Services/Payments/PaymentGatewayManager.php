<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the configured online payment gateway. Add a new provider by
 * registering a resolver here and in config/naijafresh.php.
 */
class PaymentGatewayManager
{
    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= $this->defaultDriver();

        if ($name === 'mock' && $this->isMockBlocked()) {
            throw new RuntimeException('The mock payment gateway is disabled in production. Set PAYSTACK_SECRET_KEY (or NAIJAFRESH_ALLOW_MOCK_PAYMENTS=true to override).');
        }

        return match ($name) {
            'paystack' => $this->container->make(PaystackPaymentGateway::class),
            'mock' => $this->container->make(MockPaymentGateway::class),
            default => throw new InvalidArgumentException("Unsupported payment provider [{$name}]."),
        };
    }

    public function defaultDriver(): string
    {
        return (string) config('naijafresh.payments.provider', 'mock');
    }

    /**
     * True when the app would fall back to the fake gateway in production,
     * which would let anyone "pay" for free.
     */
    public function isMockBlocked(): bool
    {
        return $this->isMock()
            && app()->isProduction()
            && ! config('naijafresh.payments.allow_mock_in_production');
    }

    public function isMock(): bool
    {
        return $this->defaultDriver() === 'mock';
    }
}
