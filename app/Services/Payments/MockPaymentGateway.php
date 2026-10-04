<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use Carbon\CarbonImmutable;

/**
 * Development / demo payment provider. It performs no network calls: the
 * checkout flow can be exercised end to end without Paystack credentials.
 * The frontend clearly labels the checkout as "TEST PAYMENT" whenever this
 * provider is active.
 */
class MockPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function initialize(Payment $payment, array $context = []): array
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return [
            'provider' => $this->name(),
            'reference' => $payment->reference,
            'requires_redirect' => false,
            'authorization_url' => $frontend.'/checkout/mock-pay?reference='.$payment->reference,
            'provider_reference' => null,
            'public_key' => null,
            'is_mock' => true,
            'meta' => ['note' => 'Mock payment provider – no real charge is made.'],
        ];
    }

    public function verify(Payment $payment, array $context = []): array
    {
        // Honour an explicit outcome from the mock checkout screen; default to success.
        $outcome = $context['mock_outcome'] ?? ($payment->meta['mock_outcome'] ?? 'success');

        if ($outcome === 'fail') {
            return [
                'status' => PaymentStatus::Failed,
                'provider_reference' => 'mock_'.$payment->reference,
                'paid_at' => null,
                'raw' => ['mock' => true, 'outcome' => 'fail'],
            ];
        }

        return [
            'status' => PaymentStatus::Paid,
            'provider_reference' => 'mock_'.$payment->reference,
            'paid_at' => CarbonImmutable::now(),
            'raw' => ['mock' => true, 'outcome' => 'success'],
        ];
    }

    public function parseWebhook(string $payload, string $signature): ?array
    {
        return null;
    }
}
