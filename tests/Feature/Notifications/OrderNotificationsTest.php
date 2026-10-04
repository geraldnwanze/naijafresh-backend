<?php

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\Notifications\OrderNotifier;
use App\Services\StoreSettings;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * @param  list<array{product_id: int, quantity: int}>  $items
 */
function placeNotifiedOrder(User $user, array $items, string $method = 'cash_on_delivery', ?string $contactEmail = null): TestResponse
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
        'items' => $items,
        'contact' => ['first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '08000000000', 'email' => $contactEmail ?? $user->email],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => DeliveryWindow::factory()->create()->id,
        'payment_method' => $method,
    ]);
}

beforeEach(function (): void {
    config()->set('naijafresh.payments.provider', 'mock');
    app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);

    $this->customer = User::factory()->create(['email' => 'ada@example.com']);
    $this->admin = User::factory()->admin()->create();
    $this->rice = Product::factory()->for(Category::factory())->byWeight()->create([
        'name' => 'Parboiled Rice',
        'price_kobo' => 190_000,
        'stock_quantity' => 50_000,
    ]);
    $this->turkey = Product::factory()->for(Category::factory())->byWeight()->frozen()->create([
        'name' => 'Frozen Turkey',
        'price_kobo' => 720_000,
        'stock_quantity' => 50_000,
    ]);
});

describe('when an order is placed', function (): void {
    it('emails the customer and puts it in their bell, and tells admins in-app and by email', function (): void {
        Notification::fake();
        $other = User::factory()->create();

        placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 2500]])->assertCreated();

        Notification::assertSentTo($this->customer, OrderPlacedNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($this->admin, NewOrderNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
        Notification::assertNotSentTo($other, OrderPlacedNotification::class);
        Notification::assertNotSentTo($other, NewOrderNotification::class);
        Notification::assertNotSentTo($this->customer, NewOrderNotification::class);
    });

    it('stores a bell entry for the customer and for the admin', function (): void {
        $orderId = placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]])->assertCreated()->json('data.id');

        $mine = $this->customer->notifications()->first();
        expect($mine->data)->toMatchArray([
            'kind' => 'order_placed',
            'url' => "/orders/{$orderId}",
            'order_id' => $orderId,
        ])->and($mine->read_at)->toBeNull();

        expect($this->admin->notifications()->first()->data)->toMatchArray([
            'kind' => 'new_order',
            'url' => "/admin/orders/{$orderId}",
        ]);
    });

    it('really hands the confirmation email to the mailer, branded and addressed to the contact email', function (): void {
        placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 2500]], contactEmail: 'family@example.com')->assertCreated();

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $order = Order::firstOrFail();

        // Two emails: the customer's confirmation and the admin's new-order alert.
        expect($sent)->toHaveCount(2);

        $message = $sent->first(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress() === 'family@example.com');
        expect($message)->not->toBeNull()
            ->and($sent->map(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress())->all())->toContain($this->admin->email);

        expect($message->getEnvelope()->getRecipients()[0]->getAddress())->toBe('family@example.com')
            ->and($message->getOriginalMessage()->getSubject())->toBe("We've received your order {$order->reference}")
            ->and($message->getOriginalMessage()->getHtmlBody())
            ->toContain($order->reference)
            ->toContain('2.5 kg × Parboiled Rice')
            ->toContain('#0b3d2e'); // NaijaFresh theme colour
    });

    it('addresses the email to the contact email entered at checkout', function (): void {
        placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]], contactEmail: 'family@example.com')->assertCreated();

        $order = Order::firstOrFail();
        $notification = new OrderPlacedNotification($order);

        expect($this->customer->routeNotificationFor('mail', $notification))->toBe('family@example.com')
            ->and($notification->toMail($this->customer)->subject)->toBe("We've received your order {$order->reference}");
    });

    it('renders the order, weights, total, delivery window and a link in the email', function (): void {
        placeNotifiedOrder($this->customer, [
            ['product_id' => $this->rice->id, 'quantity' => 2500],
            ['product_id' => $this->turkey->id, 'quantity' => 1500],
        ])->assertCreated();

        $order = Order::firstOrFail();
        $html = (string) (new OrderPlacedNotification($order))->toMail($this->customer)->render();

        expect($html)
            ->toContain($order->reference)
            ->toContain('2.5 kg × Parboiled Rice')
            ->toContain('1.5 kg × Frozen Turkey')
            ->toContain('₦4,750.00')
            ->toContain('₦10,800.00')
            ->toContain('₦17,550.00')
            ->toContain('Delivery window')
            ->toContain('1 Road, Yaba, Lagos')
            ->toContain('in cash') // cash on delivery
            ->toContain('includes frozen items')
            ->toContain(config('app.frontend_url')."/orders/{$order->id}");
    });

    it('leaves the frozen notice out when nothing is frozen', function (): void {
        placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]])->assertCreated();

        $html = (string) (new OrderPlacedNotification(Order::firstOrFail()))->toMail($this->customer)->render();

        expect($html)->not->toContain('frozen items');
    });

    it('never fails the order when notifications cannot be delivered', function (): void {
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP is down')));

        placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]])->assertCreated();

        expect(Order::count())->toBe(1);
    });

    it('sends nothing if the order transaction is rolled back', function (): void {
        Notification::fake();
        $order = Order::factory()->for($this->customer)->create();

        try {
            DB::transaction(function () use ($order): void {
                app(OrderNotifier::class)->orderPlaced($order);

                throw new RuntimeException('payment could not be started');
            });
        } catch (RuntimeException) {
            // expected
        }

        Notification::assertNothingSent();
    });
});

describe('when an order moves along', function (): void {
    it('emails only the steps the customer cares about but always updates the bell', function (OrderStatus $status, array $channels): void {
        $order = Order::factory()->for($this->customer)->create(['status' => $status]);

        expect((new OrderStatusChangedNotification($order))->via($this->customer))->toBe($channels);
    })->with([
        'confirmed' => [OrderStatus::Confirmed, ['database', 'mail']],
        'processing' => [OrderStatus::Processing, ['database']],
        'preparing' => [OrderStatus::Preparing, ['database']],
        'packed' => [OrderStatus::ReadyForPickup, ['database']],
        'out for delivery' => [OrderStatus::OutForDelivery, ['database', 'mail']],
        'delivered' => [OrderStatus::Delivered, ['database', 'mail']],
        'cancelled' => [OrderStatus::Cancelled, ['database', 'mail']],
    ]);

    it('notifies the customer when an admin updates the status', function (): void {
        $order = Order::factory()->for($this->customer)->create(['status' => OrderStatus::Pending]);
        Notification::fake();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk();

        Notification::assertSentTo(
            $this->customer,
            OrderStatusChangedNotification::class,
            fn ($n) => $n->order->is($order) && $n->order->status === OrderStatus::Confirmed,
        );
        Notification::assertNotSentTo($this->admin, OrderStatusChangedNotification::class);
    });

    it('puts the rider details in the out-for-delivery email', function (): void {
        $order = Order::factory()->for($this->customer)->create(['status' => OrderStatus::OutForDelivery]);
        Delivery::factory()->for($order)->create(['rider_name' => 'Musa Bello', 'rider_phone' => '08031234567']);

        $html = (string) (new OrderStatusChangedNotification($order->fresh()))->toMail($this->customer)->render();

        expect($html)->toContain('on its way')->toContain('Musa Bello')->toContain('08031234567');
    });

    it('explains a cancellation in the email and the bell', function (): void {
        $order = Order::factory()->for($this->customer)->create([
            'status' => OrderStatus::Cancelled,
            'cancellation_reason' => 'Out of stock',
        ]);
        $notification = new OrderStatusChangedNotification($order);

        expect((string) $notification->toMail($this->customer)->render())->toContain('cancelled')->toContain('Out of stock')
            ->and($notification->toArray($this->customer)['body'])->toBe('Out of stock');
    });
});

describe('payments', function (): void {
    it('sends one payment-received message (no duplicate status email) even if the result arrives twice', function (): void {
        $paymentRef = placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]], 'paystack')
            ->assertCreated()->json('payment.reference');
        Notification::fake();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/payments/verify', [
                'reference' => $paymentRef,
                'mock_outcome' => 'success',
            ])->assertOk()->assertJsonPath('order.status', 'confirmed');
        }

        Notification::assertSentToTimes($this->customer, PaymentReceivedNotification::class, 1);
        Notification::assertNotSentTo($this->customer, OrderStatusChangedNotification::class);
    });

    it('tells the customer when a payment fails, once', function (): void {
        $paymentRef = placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]], 'paystack')
            ->assertCreated()->json('payment.reference');
        Notification::fake();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/payments/verify', [
                'reference' => $paymentRef,
                'mock_outcome' => 'fail',
            ])->assertOk();
        }

        Notification::assertSentToTimes($this->customer, PaymentFailedNotification::class, 1);
        Notification::assertNotSentTo($this->customer, PaymentReceivedNotification::class);

        $html = (string) (new PaymentFailedNotification(
            Order::firstOrFail(),
            Order::firstOrFail()->payment,
        ))->toMail($this->customer)->render();
        expect($html)->toContain("didn't go through")->toContain('Complete payment');
    });

    it('renders the payment-received email with the amount and reference', function (): void {
        $paymentRef = placeNotifiedOrder($this->customer, [['product_id' => $this->rice->id, 'quantity' => 1000]], 'paystack')
            ->assertCreated()->json('payment.reference');
        $order = Order::firstOrFail();

        $html = (string) (new PaymentReceivedNotification($order, $order->payment))->toMail($this->customer)->render();

        expect($html)->toContain('Payment received')->toContain('₦3,900.00')->toContain($paymentRef);
    });
});
