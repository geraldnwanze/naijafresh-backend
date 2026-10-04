<?php

use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Payments\MockPaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaystackPaymentGateway;
use App\Services\StoreSettings;

function inProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

it('refuses the fake gateway in production', function (): void {
    inProduction();
    config()->set('naijafresh.payments.provider', 'mock');

    $manager = app(PaymentGatewayManager::class);

    expect($manager->isMockBlocked())->toBeTrue();
    expect(fn () => $manager->driver())->toThrow(RuntimeException::class, 'disabled in production');
});

it('hides card payments from the storefront while only the fake gateway exists in production', function (): void {
    inProduction();
    config()->set('naijafresh.payments.provider', 'mock');

    $methods = $this->getJson('/api/v1/config')->assertOk()->json('data.payment.methods');

    expect(collect($methods)->pluck('value')->all())->not->toContain('paystack')->toContain('cash_on_delivery');
    $this->getJson('/api/v1/config')->assertJsonPath('data.payment.is_mock', false);
});

it('rejects a card-payment order in production when only the fake gateway exists', function (): void {
    inProduction();
    config()->set('naijafresh.payments.provider', 'mock');
    $product = Product::factory()->for(Category::factory())->create(['stock_quantity' => 10]);

    $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => DeliveryWindow::factory()->create()->id,
        'payment_method' => 'paystack',
    ])->assertUnprocessable();

    expect(Order::query()->count())->toBe(0);
});

it('uses Paystack in production once a key is configured', function (): void {
    inProduction();
    config()->set('naijafresh.payments.provider', 'paystack');

    expect(app(PaymentGatewayManager::class)->isMockBlocked())->toBeFalse()
        ->and(app(PaymentGatewayManager::class)->driver())->toBeInstanceOf(PaystackPaymentGateway::class)
        ->and(app(StoreSettings::class)->enabledPaymentMethods()['paystack'])->toBeTrue();
});

it('lets the mock gateway through in production only when explicitly allowed', function (): void {
    inProduction();
    config()->set('naijafresh.payments.provider', 'mock');
    config()->set('naijafresh.payments.allow_mock_in_production', true);

    expect(app(PaymentGatewayManager::class)->driver())->toBeInstanceOf(MockPaymentGateway::class);
});

it('keeps the mock gateway available in development and tests', function (): void {
    config()->set('naijafresh.payments.provider', 'mock');

    expect(app(PaymentGatewayManager::class)->isMockBlocked())->toBeFalse()
        ->and(app(StoreSettings::class)->enabledPaymentMethods()['paystack'])->toBeTrue();
});

it('answers the root URL with JSON instead of a view that needs built assets', function (): void {
    $this->getJson('/')->assertOk()->assertJson(['status' => 'ok']);
});
