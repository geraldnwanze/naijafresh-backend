<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreSettings;

function placePaystackOrder(User $user): array
{
    app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);
    $window = DeliveryWindow::factory()->create();
    $product = Product::factory()->for(Category::factory())->create(['price_kobo' => 500_000, 'stock_quantity' => 10]);

    $response = test()->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => $window->id,
        'payment_method' => 'paystack',
    ])->assertCreated();

    return $response->json();
}

it('initializes a mock payment when checking out with Paystack', function (): void {
    config()->set('naijafresh.payments.provider', 'mock');
    $user = User::factory()->create();

    $data = placePaystackOrder($user);

    expect($data['checkout']['is_mock'])->toBeTrue()
        ->and($data['checkout']['authorization_url'])->toContain('mock-pay')
        ->and($data['payment']['status'])->toBe(PaymentStatus::Processing->value);
});

it('confirms the order when a mock payment verifies as successful', function (): void {
    config()->set('naijafresh.payments.provider', 'mock');
    $user = User::factory()->create();
    $data = placePaystackOrder($user);

    $verify = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payments/verify', [
        'reference' => $data['payment']['reference'],
        'mock_outcome' => 'success',
    ])->assertOk();

    expect($verify->json('paid'))->toBeTrue()
        ->and($verify->json('order.status'))->toBe(OrderStatus::Confirmed->value)
        ->and($verify->json('order.payment.status'))->toBe(PaymentStatus::Paid->value);
});

it('leaves the order pending when a mock payment fails', function (): void {
    config()->set('naijafresh.payments.provider', 'mock');
    $user = User::factory()->create();
    $data = placePaystackOrder($user);

    $verify = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payments/verify', [
        'reference' => $data['payment']['reference'],
        'mock_outcome' => 'fail',
    ])->assertOk();

    expect($verify->json('paid'))->toBeFalse()
        ->and($verify->json('order.status'))->toBe(OrderStatus::Pending->value)
        ->and($verify->json('payment.status'))->toBe(PaymentStatus::Failed->value);
});

it('does not let a customer verify a payment that is not theirs', function (): void {
    config()->set('naijafresh.payments.provider', 'mock');
    $data = placePaystackOrder(User::factory()->create());

    $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/payments/verify', [
        'reference' => $data['payment']['reference'],
    ])->assertForbidden();
});
