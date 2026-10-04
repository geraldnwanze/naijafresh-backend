<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreSettings;

/**
 * End-to-end: browse → add to cart → checkout → place order → view order →
 * admin advances the order → customer sees the updated status.
 */
it('runs the full customer and admin order journey', function (): void {
    app(StoreSettings::class)->setMany([
        StoreSettings::DELIVERY_FEE_KOBO => 200_000,
        StoreSettings::FREE_DELIVERY_THRESHOLD_KOBO => 0,
    ]);

    $window = DeliveryWindow::factory()->create(['label' => 'Morning', 'starts_at' => '8am', 'ends_at' => '11am']);
    $category = Category::factory()->create();
    $ugu = Product::factory()->for($category)->create(['name' => 'Ugu', 'price_kobo' => 70_000, 'stock_quantity' => 10]);
    $rice = Product::factory()->for($category)->create(['name' => 'Parboiled Rice', 'price_kobo' => 950_000, 'stock_quantity' => 5]);

    // 1. Register (real token flow)
    $token = $this->postJson('/api/v1/auth/register', [
        'name' => 'Chidi Balogun',
        'email' => 'chidi@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated()->json('token');

    $api = fn () => $this->withToken($token);

    // 2. Browse
    $api()->getJson('/api/v1/products')->assertOk();

    // 3. Price the cart server-side
    $items = [
        ['product_id' => $ugu->id, 'quantity' => 2],
        ['product_id' => $rice->id, 'quantity' => 1],
    ];
    $priced = $this->postJson('/api/v1/cart/price', ['items' => $items])->assertOk();
    expect($priced->json('data.subtotal_kobo'))->toBe(70_000 * 2 + 950_000)
        ->and($priced->json('data.total_kobo'))->toBe(70_000 * 2 + 950_000 + 200_000);

    // 4. Place the order (cash on delivery)
    $place = $api()->postJson('/api/v1/orders', [
        'items' => $items,
        'contact' => [
            'first_name' => 'Chidi', 'last_name' => 'Balogun',
            'phone' => '08011112222', 'email' => 'chidi@example.com',
        ],
        'delivery' => [
            'street' => '5 Admiralty Way', 'area' => 'Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos',
        ],
        'delivery_window_id' => $window->id,
        'delivery_date' => now()->addDay()->toDateString(),
        'payment_method' => 'cash_on_delivery',
    ])->assertCreated();

    $reference = $place->json('data.reference');
    expect($place->json('data.total_kobo'))->toBe(70_000 * 2 + 950_000 + 200_000)
        ->and($place->json('data.status'))->toBe('pending')
        ->and($place->json('checkout.requires_redirect'))->toBeFalse();

    // Stock was decremented
    expect($ugu->refresh()->stock_quantity)->toBe(8)
        ->and($rice->refresh()->stock_quantity)->toBe(4);

    // 5. Customer views the order with a timeline
    $show = $api()->getJson("/api/v1/orders/{$place->json('data.id')}")->assertOk();
    expect($show->json('data.timeline'))->not->toBeEmpty()
        ->and($show->json('data.timeline.0.state'))->toBe('current');

    // 6. Admin advances the order through the pipeline
    $admin = User::factory()->admin()->create();
    $order = Order::where('reference', $reference)->firstOrFail();

    foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Preparing, OrderStatus::ReadyForPickup, OrderStatus::OutForDelivery, OrderStatus::Delivered] as $status) {
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status->value])
            ->assertOk()
            ->assertJsonPath('data.status', $status->value);
    }

    // 7. Customer sees the delivered status and the COD payment settled
    $final = $api()->getJson("/api/v1/orders/{$order->id}")->assertOk();
    expect($final->json('data.status'))->toBe('delivered')
        ->and($final->json('data.payment.status'))->toBe(PaymentStatus::Paid->value);
});
