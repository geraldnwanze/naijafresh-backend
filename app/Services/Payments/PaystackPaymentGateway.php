<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

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

        $result = $this->send(fn () => $this->client()
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
            ]), 'initialize', $payment);

        if ($result->failed() || ! ($result->json('status') ?? false)) {
            throw $this->failure($result, 'initialize', $payment);
        }

        $data = $result->json('data');

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
        $result = $this->send(
            fn () => $this->client()->get('/transaction/verify/'.urlencode($payment->reference)),
            'verify',
            $payment,
        );

        if ($result->failed()) {
            throw $this->failure($result, 'verify', $payment);
        }

        $data = $result->json('data') ?? [];

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

    /**
     * Runs a Paystack call, turning "could not reach Paystack" (timeout, DNS,
     * refused connection) into the same exception as a refused request.
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $action, Payment $payment): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::error("Paystack could not {$action} a payment: unreachable", [
                'payment_reference' => $payment->reference,
                'order_id' => $payment->order_id,
                'error' => $e->getMessage(),
            ]);

            throw $action === 'initialize'
                ? PaymentGatewayException::couldNotStart('Paystack could not be reached')
                : PaymentGatewayException::couldNotConfirm('Paystack could not be reached');
        }
    }

    /**
     * Logs what Paystack said (never the request, which carries the secret key)
     * and returns an exception whose message is Paystack's own explanation.
     */
    private function failure(Response $response, string $action, Payment $payment): PaymentGatewayException
    {
        $message = (string) ($response->json('message') ?? 'no message returned');

        Log::error("Paystack could not {$action} a payment: {$message}", [
            'http_status' => $response->status(),
            'payment_reference' => $payment->reference,
            'order_id' => $payment->order_id,
            'response' => mb_substr($response->body(), 0, 500),
        ]);

        return $action === 'initialize'
            ? PaymentGatewayException::couldNotStart($message)
            : PaymentGatewayException::couldNotConfirm($message);
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
