<?php

use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('naijafresh.payments.provider', 'paystack');
    config()->set('naijafresh.payments.paystack.secret_key', 'sk_test_example');
    config()->set('naijafresh.payments.paystack.base_url', 'https://api.paystack.co');
    app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);
});

function cardOrderPayload(): array
{
    $window = DeliveryWindow::factory()->create();
    $product = Product::factory()->for(Category::factory())->create(['price_kobo' => 500_000, 'stock_quantity' => 10]);

    return [
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => $window->id,
        'payment_method' => 'paystack',
    ];
}

function paystackRefuses(): void
{
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Secret internal reason'], 400)]);
}

it('keeps the order and tells the customer when Paystack cannot start the payment', function (): void {
    paystackRefuses();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', cardOrderPayload())->assertCreated();

    expect($response->json('checkout.requires_redirect'))->toBeFalse()
        ->and($response->json('checkout.authorization_url'))->toBeNull()
        ->and($response->json('checkout.error'))->toContain('try again from your order page')
        ->and($response->json('checkout.error'))->not->toContain('Secret internal reason')
        ->and($response->json('payment.status'))->toBe(PaymentStatus::Pending->value);

    $order = Order::where('reference', $response->json('data.reference'))->first();

    expect($order)->not->toBeNull()
        ->and($order->user_id)->toBe($user->id);
});

it('lets the customer retry the payment from their order once Paystack recovers', function (): void {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::sequence()
        ->push(['status' => false, 'message' => 'Temporarily unavailable'], 503)
        ->push(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/retry', 'access_code' => 'retry']])]);
    $user = User::factory()->create();
    $reference = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', cardOrderPayload())->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/payments/initialize', ['order_reference' => $reference])
        ->assertOk()
        ->assertJsonPath('checkout.authorization_url', 'https://checkout.paystack.com/retry');
});

it('answers a failed retry with a friendly message and no provider detail', function (): void {
    paystackRefuses();
    $user = User::factory()->create();
    $reference = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', cardOrderPayload())->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/payments/initialize', ['order_reference' => $reference])
        ->assertStatus(502)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, "couldn't start your card payment")
            && ! str_contains($message, 'Secret internal reason'));
});

it('answers a failed verification with a message that warns against paying twice', function (): void {
    paystackRefuses();
    $user = User::factory()->create();
    $payload = cardOrderPayload();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);
    $reference = Order::latest('id')->first()->payment->reference;

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/payments/verify', ['reference' => $reference])
        ->assertStatus(502)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, "don't pay again"));
});
