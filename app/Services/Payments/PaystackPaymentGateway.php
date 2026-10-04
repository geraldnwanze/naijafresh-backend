<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class PaystackPaymentGateway implements PaymentGateway
{
    public function __construct(private readonly HttpClient $http) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function initialize(Payment $payment, array $context = []): array
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $response = $this->client()
            ->post('/transaction/initialize', [
                'email' => $payment->order->contact_email,
                'amount' => $payment->amount_kobo,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => $frontend.'/checkout/verify?reference='.$payment->reference,
                'metadata' => [
                    'order_reference' => $payment->order->reference,
                    'order_id' => $payment->order_id,
                ],
            ])
            ->throw()
            ->json();

        if (! ($response['status'] ?? false)) {
            throw new RuntimeException('Paystack could not initialize this payment.');
        }

        $data = $response['data'];

        return [
            'provider' => $this->name(),
            'reference' => $payment->reference,
            'requires_redirect' => true,
            'authorization_url' => $data['authorization_url'],
            'provider_reference' => $data['access_code'] ?? null,
            'public_key' => config('naijafresh.payments.paystack.public_key'),
            'is_mock' => false,
            'meta' => $data,
        ];
    }

    public function verify(Payment $payment, array $context = []): array
    {
        $response = $this->client()
            ->get('/transaction/verify/'.urlencode($payment->reference))
            ->throw()
            ->json();

        $data = $response['data'] ?? [];

        return [
            'status' => $this->mapStatus($data['status'] ?? 'failed'),
            'provider_reference' => $data['reference'] ?? null,
            'paid_at' => isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : null,
            'raw' => $data,
        ];
    }

    public function parseWebhook(string $payload, string $signature): ?array
    {
        $secret = (string) config('naijafresh.payments.paystack.secret_key');
        $expected = hash_hmac('sha512', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ($event['event'] ?? null) !== 'charge.success') {
            return null;
        }

        $data = $event['data'] ?? [];
        $reference = $data['reference'] ?? null;

        if (! is_string($reference)) {
            return null;
        }

        return [
            'reference' => $reference,
            'verification' => [
                'status' => $this->mapStatus($data['status'] ?? 'failed'),
                'provider_reference' => $data['reference'] ?? null,
                'paid_at' => isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : null,
                'raw' => $data,
            ],
        ];
    }

    private function mapStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'success' => PaymentStatus::Paid,
            'processing', 'pending' => PaymentStatus::Processing,
            'reversed' => PaymentStatus::Refunded,
            default => PaymentStatus::Failed,
        };
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl((string) config('naijafresh.payments.paystack.base_url'))
            ->withToken((string) config('naijafresh.payments.paystack.secret_key'))
            ->acceptJson()
            ->timeout(20);
    }
}
