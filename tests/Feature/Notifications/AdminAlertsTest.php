<?php

use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\NewOrderNotification;
use App\Services\StoreSettings;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * @param  list<array{product_id: int, quantity: int}>  $items
 */
function placeAlertOrder(User $user, array $items, string $contactEmail = 'family@example.com'): TestResponse
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
        'items' => $items,
        'contact' => ['first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '08000000000', 'email' => $contactEmail],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => DeliveryWindow::factory()->create()->id,
        'payment_method' => 'cash_on_delivery',
    ]);
}

function stockedProduct(array $attributes = []): Product
{
    return Product::factory()->for(Category::factory())->create(['price_kobo' => 100_000, ...$attributes]);
}

/**
 * @return list<string>
 */
function sentEmailRecipients(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()
        ->map(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress())
        ->all();
}

beforeEach(function (): void {
    config()->set('naijafresh.payments.provider', 'mock');
    app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);

    $this->customer = User::factory()->create(['email' => 'ada@example.com']);
    $this->admin = User::factory()->admin()->create(['email' => 'boss@naijafresh.test']);
    $this->otherAdmin = User::factory()->admin()->create(['email' => 'ops@naijafresh.test']);
    $this->owner = User::factory()->superAdmin()->create(['email' => 'owner@naijafresh.test']);
});

describe('new order email for admins', function (): void {
    it('emails every admin at their own account address, not the customer\'s contact email', function (): void {
        placeAlertOrder($this->customer, [['product_id' => stockedProduct(['stock_quantity' => 50])->id, 'quantity' => 1]])->assertCreated();

        expect(sentEmailRecipients())->toHaveCount(3)
            ->toContain('family@example.com', 'boss@naijafresh.test', 'ops@naijafresh.test');
    });

    it('renders the order, customer, payment and a link to the admin order page', function (): void {
        $product = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 50]);
        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 2]])->assertCreated();

        $order = Order::firstOrFail();
        $mail = (new NewOrderNotification($order))->toMail($this->admin);
        $html = (string) $mail->render();

        expect($mail->subject)->toStartWith("New order {$order->reference}")
            ->and($html)
            ->toContain('Ada Obi')
            ->toContain('2 × Palm Oil')
            ->toContain('Cash on delivery')
            ->toContain('not yet paid')
            ->toContain(config('app.frontend_url')."/admin/orders/{$order->id}");
    });
});

describe('low stock alerts', function (): void {
    it('alerts admins by bell and email when a sale takes a product to low stock', function (): void {
        Notification::fake();
        $product = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 8]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 4]])->assertCreated();

        Notification::assertSentTo(
            [$this->admin, $this->otherAdmin],
            LowStockNotification::class,
            fn (LowStockNotification $n, array $channels) => $channels === ['database', 'mail']
                && $n->products[0]['name'] === 'Palm Oil'
                && $n->products[0]['level'] === 'low'
                && $n->products[0]['stock_label'] === '4 left',
        );
        Notification::assertNotSentTo($this->customer, LowStockNotification::class);
    });

    it('stores a bell entry that opens the inventory page', function (): void {
        $product = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 8]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 4]])->assertCreated();

        expect($this->admin->notifications->first(fn ($n) => $n->data['kind'] === 'low_stock')->data)->toMatchArray([
            'kind' => 'low_stock',
            'title' => 'Low stock: Palm Oil',
            'body' => 'Palm Oil: 4 left',
            'url' => '/admin/inventory',
            'order_reference' => Order::firstOrFail()->reference,
        ]);
    });

    it('says out of stock when a sale sells the last of a product', function (): void {
        Notification::fake();
        $product = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 10]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 10]])->assertCreated();

        Notification::assertSentTo($this->admin, LowStockNotification::class, fn (LowStockNotification $n) => $n->products[0]['level'] === 'out'
            && $n->products[0]['stock_label'] === 'Out of stock');
    });

    it('stays quiet while stock is healthy', function (): void {
        Notification::fake();
        $product = stockedProduct(['stock_quantity' => 50]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 3]])->assertCreated();

        Notification::assertNotSentTo($this->admin, LowStockNotification::class);
    });

    it('alerts once per level, not on every sale of a product that is already low', function (): void {
        Notification::fake();
        $product = stockedProduct(['stock_quantity' => 8]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 4]])->assertCreated(); // ok → low
        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 1]])->assertCreated(); // low → low
        Notification::assertSentToTimes($this->admin, LowStockNotification::class, 1);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 3]])->assertCreated(); // low → out
        Notification::assertSentToTimes($this->admin, LowStockNotification::class, 2);
    });

    it('groups every product one order pushes down into a single alert', function (): void {
        Notification::fake();
        $oil = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 6]);
        $beans = stockedProduct(['name' => 'Honey Beans', 'stock_quantity' => 7]);
        $salt = stockedProduct(['name' => 'Salt', 'stock_quantity' => 100]);

        placeAlertOrder($this->customer, [
            ['product_id' => $oil->id, 'quantity' => 2],
            ['product_id' => $beans->id, 'quantity' => 3],
            ['product_id' => $salt->id, 'quantity' => 1],
        ])->assertCreated();

        Notification::assertSentToTimes($this->admin, LowStockNotification::class, 1);
        Notification::assertSentTo($this->admin, LowStockNotification::class, function (LowStockNotification $n): bool {
            $html = (string) $n->toMail($this->admin)->render();

            return collect($n->products)->pluck('name')->sort()->values()->all() === ['Honey Beans', 'Palm Oil']
                && $n->toArray($this->admin)['title'] === 'Restock needed: 2 products running low'
                && str_contains($html, 'Honey Beans') && str_contains($html, config('app.frontend_url').'/admin/inventory');
        });
    });

    it('measures weight-sold products in grams against the kilogram threshold', function (): void {
        Notification::fake();
        $rice = Product::factory()->for(Category::factory())->byWeight()->create(['name' => 'Rice', 'price_kobo' => 190_000, 'stock_quantity' => 8_000]);

        placeAlertOrder($this->customer, [['product_id' => $rice->id, 'quantity' => 2_000]])->assertCreated(); // 6 kg: fine
        Notification::assertNotSentTo($this->admin, LowStockNotification::class);

        placeAlertOrder($this->customer, [['product_id' => $rice->id, 'quantity' => 2_000]])->assertCreated(); // 4 kg: low
        Notification::assertSentTo($this->admin, LowStockNotification::class, fn (LowStockNotification $n) => $n->products[0]['stock_label'] === '4 kg left');
    });

    it('honours the configured thresholds', function (): void {
        Notification::fake();
        config()->set('naijafresh.inventory.low_stock_units', 20);
        $product = stockedProduct(['stock_quantity' => 30]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 15]])->assertCreated();

        Notification::assertSentTo($this->admin, LowStockNotification::class);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/inventory')->assertJsonPath('meta.low_stock_threshold', 20);
    });

    it('sends no alert when the order fails for lack of stock', function (): void {
        Notification::fake();
        $product = stockedProduct(['stock_quantity' => 3]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 5]])->assertUnprocessable();

        Notification::assertNothingSent();
        expect($product->fresh()->stock_quantity)->toBe(3);
    });
});

describe('every email is queued', function (): void {
    it('hands each email to the queue instead of sending while the request waits', function (): void {
        Queue::fake();
        $product = stockedProduct(['stock_quantity' => 8]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 4]])->assertCreated();

        // customer confirmation + 2 × new order + 2 × low stock = 5 emails
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => in_array('mail', $job->channels, true) && ! in_array('database', $job->channels, true));
        expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('sends the bell entry at once and the email only when a worker runs', function (): void {
        config()->set('queue.default', 'database');
        $product = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 8]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 4]], contactEmail: 'family@example.com')->assertCreated();

        // Bell is instant; nothing has been emailed yet, the emails are waiting in the jobs table.
        expect($this->admin->notifications()->count())->toBe(2) // new order + low stock
            ->and($this->customer->notifications()->count())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(5)
            ->and(sentEmailRecipients())->toBe([]);

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(sentEmailRecipients())->toHaveCount(5)
            ->toContain('family@example.com', 'boss@naijafresh.test', 'ops@naijafresh.test');
    });

    it('does not duplicate the bell entry when the queued email is delivered', function (): void {
        config()->set('queue.default', 'database');
        $product = stockedProduct(['stock_quantity' => 50]);
        $sent = [];
        Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$sent): void {
            $sent[] = $e->channel;
        });

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 1]])->assertCreated();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        expect(array_count_values($sent))->toBe(['database' => 4, 'mail' => 3]) // owner: bell only
            ->and($this->customer->notifications()->count())->toBe(1);
    });
});

describe('super admins', function (): void {
    it('get the bell entries but no emails', function (): void {
        $product = stockedProduct(['name' => 'Palm Oil', 'stock_quantity' => 8]);

        placeAlertOrder($this->customer, [['product_id' => $product->id, 'quantity' => 4]])->assertCreated();

        expect($this->owner->notifications->pluck('data.kind')->sort()->values()->all())->toBe(['low_stock', 'new_order'])
            ->and(DB::table('jobs')->count())->toBe(0);

        // customer confirmation + 2 admins × (new order + low stock) = 5 emails; none for the owner
        expect(sentEmailRecipients())->toHaveCount(5)->not->toContain('owner@naijafresh.test');
    });

    it('are skipped for the order email and the low-stock email individually', function (): void {
        $order = Order::factory()->for($this->customer)->create();

        expect((new NewOrderNotification($order))->via($this->owner))->toBe(['database'])
            ->and((new NewOrderNotification($order))->via($this->admin))->toBe(['database', 'mail'])
            ->and((new LowStockNotification([]))->via($this->owner))->toBe(['database'])
            ->and((new LowStockNotification([]))->via($this->admin))->toBe(['database', 'mail']);
    });
});
