<?php

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\StoreSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    config()->set('naijafresh.payments.provider', 'mock');
    app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 200_000);
    $this->super = User::factory()->superAdmin()->create();
    $this->customer = User::factory()->create(['name' => 'Ada Customer', 'email' => 'ada@example.com']);
    $this->product = Product::factory()->for(Category::factory())->create(['price_kobo' => 500_000, 'stock_quantity' => 50]);
    ActivityLog::query()->delete();
});

function placeActivityOrder(User $user, string $method = 'cash_on_delivery'): TestResponse
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
        'items' => [['product_id' => Product::query()->value('id'), 'quantity' => 1]],
        'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
        'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
        'delivery_window_id' => DeliveryWindow::factory()->create()->id,
        'payment_method' => $method,
    ]);
}

describe('sign-ins', function (): void {
    it('logs a successful sign-in with who, where from and the role', function (): void {
        $this->withHeader('User-Agent', 'PestBrowser/1.0')
            ->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertOk();

        $log = ActivityLog::query()->sole();

        expect($log->event)->toBe(ActivityEvent::LoginSucceeded)
            ->and($log->user_id)->toBe($this->customer->id)
            ->and($log->user_email)->toBe('ada@example.com')
            ->and($log->ip_address)->toBe('127.0.0.1')
            ->and($log->user_agent)->toBe('PestBrowser/1.0')
            ->and($log->properties)->toBe(['role' => 'customer']);
    });

    it('logs a failed sign-in for a known account against that account', function (): void {
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])->assertUnprocessable();

        $log = ActivityLog::query()->sole();

        expect($log->event)->toBe(ActivityEvent::LoginFailed)
            ->and($log->user_id)->toBe($this->customer->id)
            ->and($log->properties)->toBe(['email' => 'ada@example.com']);
    });

    it('logs a failed sign-in for an unknown account by the email that was typed', function (): void {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever1'])->assertUnprocessable();

        $log = ActivityLog::query()->sole();
        expect($log->user_id)->toBeNull();

        $this->actingAs($this->super, 'sanctum')->getJson('/api/v1/admin/system/activity-logs')
            ->assertJsonPath('data.0.event', 'login_failed')
            ->assertJsonPath('data.0.user.email', 'nobody@example.com');
    });

    it('never stores the password that was typed', function (): void {
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'hunter2-hunter2'])->assertUnprocessable();

        expect(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain('hunter2');
    });

    it('logs registration and sign-out', function (): void {
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Person', 'email' => 'new@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        expect(ActivityLog::query()->pluck('event')->map->value->all())->toBe(['user_registered', 'logged_out']);
    });
});

describe('orders and payments', function (): void {
    it('logs an order being placed', function (): void {
        $order = Order::findOrFail(placeActivityOrder($this->customer)->assertCreated()->json('data.id'));

        $log = ActivityLog::query()->where('event', ActivityEvent::OrderPlaced->value)->sole();

        expect($log->user_id)->toBe($this->customer->id)
            ->and($log->subject_type)->toBe(Order::class)
            ->and($log->subject_id)->toBe($order->id)
            ->and($log->properties)->toMatchArray(['total_kobo' => $order->total_kobo, 'payment_method' => 'cash_on_delivery']);
    });

    it('logs who moved an order along, from and to', function (): void {
        $orderId = placeActivityOrder($this->customer)->assertCreated()->json('data.id');
        ActivityLog::query()->delete();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/orders/{$orderId}/status", ['status' => 'confirmed'])->assertOk();

        $log = ActivityLog::query()->sole();
        expect($log->event)->toBe(ActivityEvent::OrderStatusChanged)
            ->and($log->user_id)->toBe($admin->id)
            ->and($log->properties)->toBe(['from' => 'pending', 'to' => 'confirmed']);
    });

    it('logs a successful payment once, even if the result arrives twice', function (): void {
        $data = placeActivityOrder($this->customer, 'paystack')->assertCreated()->json();
        ActivityLog::query()->delete();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/payments/verify', [
                'reference' => $data['payment']['reference'], 'mock_outcome' => 'success',
            ])->assertOk();
        }

        $logs = ActivityLog::query()->where('event', ActivityEvent::PaymentSucceeded->value)->get();
        expect($logs)->toHaveCount(1)->and($logs->first()->properties['payment_reference'])->toBe($data['payment']['reference']);
    });

    it('logs a failed payment', function (): void {
        $data = placeActivityOrder($this->customer, 'paystack')->assertCreated()->json();
        ActivityLog::query()->delete();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/payments/verify', [
            'reference' => $data['payment']['reference'], 'mock_outcome' => 'fail',
        ])->assertOk();

        expect(ActivityLog::query()->where('event', ActivityEvent::PaymentFailed->value)->count())->toBe(1);
    });

    it('logs nothing for an order that fails', function (): void {
        $this->product->update(['stock_quantity' => 0]);

        placeActivityOrder($this->customer)->assertUnprocessable();

        expect(ActivityLog::query()->count())->toBe(0);
    });
});

describe('the activity log API', function (): void {
    beforeEach(function (): void {
        ActivityLog::factory()->create(['user_id' => $this->customer->id, 'user_name' => 'Ada Customer', 'user_email' => 'ada@example.com', 'ip_address' => '10.0.0.5']);
        ActivityLog::factory()->event(ActivityEvent::LoginFailed)->create(['user_email' => null, 'user_name' => null, 'properties' => ['email' => 'guess@example.com'], 'ip_address' => '10.0.0.9']);
        ActivityLog::factory()->event(ActivityEvent::OrderPlaced, 'Order NF-ABC placed')->create(['user_email' => 'bola@example.com', 'ip_address' => '10.0.0.7']);
        $this->actingAs($this->super, 'sanctum');
    });

    it('lists newest first with the event options', function (): void {
        $response = $this->getJson('/api/v1/admin/system/activity-logs')->assertOk();

        expect($response->json('data'))->toHaveCount(3)
            ->and($response->json('data.0.event'))->toBe('order_placed')
            ->and($response->json('data.0.event_label'))->toBe('Order placed')
            ->and(collect($response->json('filters.events'))->pluck('value'))->toContain('login_failed', 'payment_succeeded');
    });

    it('filters by event, user, text (including the typed email and IP) and date', function (): void {
        expect($this->getJson('/api/v1/admin/system/activity-logs?event=login_failed')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/activity-logs?user_id='.$this->customer->id)->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/activity-logs?search=guess@example.com')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/activity-logs?search=NF-ABC')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/activity-logs?search=10.0.0.5')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/activity-logs?from='.now()->addDay()->toDateString())->json('data'))->toHaveCount(0);
    });

    it('rejects an unknown event filter', function (): void {
        $this->getJson('/api/v1/admin/system/activity-logs?event=nope')->assertUnprocessable();
    });
});

describe('retention', function (): void {
    it('prunes activity older than the retention window and keeps the rest', function (): void {
        config()->set('naijafresh.logs.activity_retention_days', 30);
        ActivityLog::factory()->create(['created_at' => now()->subDays(45)]);
        ActivityLog::factory()->create(['created_at' => now()->subDays(5)]);

        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);

        expect(ActivityLog::query()->count())->toBe(1);
    });
});

it('does not log while seeding is paused', function (): void {
    app(AuditLogger::class)->withoutAuditing(fn () => placeActivityOrder($this->customer)->assertCreated());

    expect(ActivityLog::query()->count())->toBe(0);
});
