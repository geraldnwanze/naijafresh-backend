<?php

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaystackPaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('naijafresh.payments.paystack.secret_key', 'sk_test_example');
    config()->set('naijafresh.payments.paystack.base_url', 'https://api.paystack.co');
});

function paystackPayment(): Payment
{
    $order = Order::factory()->create(['contact_email' => 'ada@example.com']);

    return Payment::factory()->for($order)->create(['amount_kobo' => 120_000]);
}

it('sends the order details to Paystack and returns the checkout link', function (): void {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'x'],
    ])]);
    $payment = paystackPayment();

    $init = app(PaystackPaymentGateway::class)->initialize($payment);

    expect($init['authorization_url'])->toBe('https://checkout.paystack.com/abc')
        ->and($init['requires_redirect'])->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['email'] === 'ada@example.com'
        && $request['amount'] === 120_000
        && $request['reference'] === $payment->reference
        && $request->hasHeader('Authorization', 'Bearer sk_test_example'));
});

it('explains why Paystack refused to start a payment, and logs it without the secret key', function (): void {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => false,
        'message' => '"email" must be a valid email',
    ], 400)]);
    Log::spy();
    $payment = paystackPayment();

    expect(fn () => app(PaystackPaymentGateway::class)->initialize($payment))
        ->toThrow(PaymentGatewayException::class, '"email" must be a valid email');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'must be a valid email')
        && $context['http_status'] === 400
        && $context['payment_reference'] === $payment->reference
        && ! str_contains(json_encode($context), 'sk_test_example'))->once();
});

it('treats an unreachable Paystack like a refusal', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    Log::spy();
    $payment = paystackPayment();

    expect(fn () => app(PaystackPaymentGateway::class)->initialize($payment))
        ->toThrow(PaymentGatewayException::class, 'could not be reached')
        ->and(fn () => app(PaystackPaymentGateway::class)->verify($payment))
        ->toThrow(PaymentGatewayException::class, 'could not be reached');
});

it('reports a failed verification call with the same detail', function (): void {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404)]);
    Log::spy();

    expect(fn () => app(PaystackPaymentGateway::class)->verify(paystackPayment()))
        ->toThrow(PaymentGatewayException::class, 'Transaction reference not found');
});

it('maps Paystack transaction states to payment statuses', function (string $paystackStatus, PaymentStatus $expected): void {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => $paystackStatus, 'reference' => 'x'],
    ])]);

    $result = app(PaystackPaymentGateway::class)->verify(paystackPayment());

    expect($result['status'])->toBe($expected);
})->with([
    ['success', PaymentStatus::Paid],
    ['abandoned', PaymentStatus::Failed],
    ['failed', PaymentStatus::Failed],
    ['pending', PaymentStatus::Processing],
    ['reversed', PaymentStatus::Refunded],
]);

it('accepts only webhooks signed with the secret key', function (): void {
    $gateway = app(PaystackPaymentGateway::class);
    $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'NFPAY-1', 'status' => 'success']]);

    $valid = hash_hmac('sha512', $payload, 'sk_test_example');

    expect($gateway->parseWebhook($payload, $valid)['reference'])->toBe('NFPAY-1')
        ->and($gateway->parseWebhook($payload, str_repeat('0', 128)))->toBeNull()
        ->and($gateway->parseWebhook($payload, ''))->toBeNull();
});

it('ignores signed events that are not successful charges', function (): void {
    $payload = json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'NFPAY-1']]);
    $signature = hash_hmac('sha512', $payload, 'sk_test_example');

    expect(app(PaystackPaymentGateway::class)->parseWebhook($payload, $signature))->toBeNull();
});
