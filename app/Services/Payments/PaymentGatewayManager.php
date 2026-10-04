<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

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

    public function isMock(): bool
    {
        return $this->defaultDriver() === 'mock';
    }
}
