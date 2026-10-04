<?php

namespace App\Services\Payments\Contracts;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\CarbonImmutable;

interface PaymentGateway
{
    /**
     * Machine name of the provider, e.g. "paystack" or "mock".
     */
    public function name(): string;

    /**
     * Begin a payment for the given (pending) Payment record.
     *
     * @param  array<string, mixed>  $context
     * @return array{
     *     provider: string,
     *     reference: string,
     *     requires_redirect: bool,
     *     authorization_url: string|null,
     *     provider_reference: string|null,
     *     public_key: string|null,
     *     is_mock: bool,
     *     meta: array<string, mixed>
     * }
     */
    public function initialize(Payment $payment, array $context = []): array;

    /**
     * Ask the provider for the authoritative status of a payment.
     *
     * @param  array<string, mixed>  $context
     * @return array{
     *     status: PaymentStatus,
     *     provider_reference: string|null,
     *     paid_at: CarbonImmutable|null,
     *     raw: array<string, mixed>
     * }
     */
    public function verify(Payment $payment, array $context = []): array;

    /**
     * Validate an incoming webhook request and return its parsed event, or
     * null when the signature is invalid / the event is not relevant.
     *
     * @return array{reference: string, verification: array<string, mixed>}|null
     */
    public function parseWebhook(string $payload, string $signature): ?array;
}
