<?php

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\StoreSettings;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada.admin@naijafresh.test']);
    $this->super = User::factory()->superAdmin()->create();
    $this->product = Product::factory()->for(Category::factory())->create(['name' => 'Palm Oil', 'price_kobo' => 250_000, 'stock_quantity' => 40]);
    AuditLog::query()->delete(); // setup rows aren't what we're testing
});

describe('what gets recorded', function (): void {
    it('records who changed a record, from what to what, and where from', function (): void {
        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('User-Agent', 'PestBrowser/1.0')
            ->putJson("/api/v1/admin/inventory/{$this->product->id}", ['stock_quantity' => 12, 'is_available' => true])
            ->assertOk();

        $log = AuditLog::query()->sole();

        expect($log->event)->toBe('updated')
            ->and($log->actor_id)->toBe($this->admin->id)
            ->and($log->actor_name)->toBe('Ada Admin')
            ->and($log->actor_email)->toBe('ada.admin@naijafresh.test')
            ->and($log->auditable_type)->toBe(Product::class)
            ->and($log->auditable_id)->toBe($this->product->id)
            ->and($log->auditable_label)->toBe('Palm Oil')
            ->and($log->old_values)->toBe(['stock_quantity' => 40])
            ->and($log->new_values)->toBe(['stock_quantity' => 12])
            ->and($log->ip_address)->toBe('127.0.0.1')
            ->and($log->user_agent)->toBe('PestBrowser/1.0');
    });

    it('records creates with the new values and deletes with the old ones', function (): void {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/categories', ['name' => 'Snacks', 'is_active' => true])
            ->assertCreated();

        $created = AuditLog::query()->where('event', 'created')->sole();
        expect($created->auditable_type)->toBe(Category::class)
            ->and($created->auditable_label)->toBe('Snacks')
            ->and($created->old_values)->toBeNull()
            ->and($created->new_values)->toMatchArray(['name' => 'Snacks', 'is_active' => true]);

        $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/v1/admin/categories/'.$created->auditable_id)->assertSuccessful();

        $deleted = AuditLog::query()->where('event', 'deleted')->sole();
        expect($deleted->old_values)->toMatchArray(['name' => 'Snacks'])->and($deleted->new_values)->toBeNull();
    });

    it('records settings changes by key', function (): void {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'delivery_fee_kobo' => 350_000,
            'free_delivery_threshold_kobo' => 0,
            'cash_on_delivery_enabled' => true,
            'bank_transfer_enabled' => true,
            'store_open' => false,
        ])->assertOk();

        $log = AuditLog::query()->where('auditable_label', StoreSettings::STORE_OPEN)->sole();

        expect($log->actor_id)->toBe($this->admin->id)->and($log->new_values)->toHaveKey('value');
    });

    it('records an admin moving an order along', function (): void {
        $order = Order::factory()->for(User::factory()->create())->create(['status' => OrderStatus::Pending]);
        AuditLog::query()->delete();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        $log = AuditLog::query()->where('auditable_type', Order::class)->latest('id')->first();

        expect($log->actor_id)->toBe($this->admin->id)
            ->and($log->auditable_label)->toBe($order->reference)
            ->and($log->old_values['status'])->toBe('pending')
            ->and($log->new_values['status'])->toBe('confirmed');
    });

    it('records changes with nobody signed in as the system', function (): void {
        $this->product->update(['price_kobo' => 300_000]);

        $log = AuditLog::query()->sole();

        expect($log->actor_id)->toBeNull()->and($log->actor_name)->toBeNull();

        $this->actingAs($this->super, 'sanctum')->getJson('/api/v1/admin/system/audit-logs')
            ->assertJsonPath('data.0.actor.name', 'System')
            ->assertJsonPath('data.0.actor.is_system', true);
    });

    it('records role changes but never passwords', function (): void {
        $user = User::factory()->create();
        AuditLog::query()->delete();

        $this->actingAs($this->super, 'sanctum')->putJson("/api/v1/admin/system/users/{$user->id}/role", ['role' => 'admin'])->assertOk();
        $user->update(['password' => 'a-brand-new-secret']);

        $logs = AuditLog::query()->where('auditable_type', User::class)->get();

        expect($logs)->toHaveCount(1)
            ->and($logs->first()->old_values)->toBe(['role' => 'customer'])
            ->and($logs->first()->new_values)->toBe(['role' => 'admin'])
            ->and(json_encode($logs->toArray()))->not->toContain('a-brand-new-secret')->not->toContain('password');
    });

    it('does not record payment gateway payloads', function (): void {
        $payment = Payment::factory()->create();
        AuditLog::query()->delete();

        $payment->update(['status' => 'paid', 'meta' => ['secret' => 'gateway-body'], 'authorization_url' => 'https://pay.example/x']);

        $log = AuditLog::query()->sole();

        expect($log->new_values)->toHaveKey('status')->not->toHaveKey('meta')->not->toHaveKey('authorization_url');
    });
});

describe('what is left out', function (): void {
    it('ignores changes that only touch timestamps', function (): void {
        $this->actingAs($this->admin, 'sanctum');
        $this->product->touch();

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('leaves customers\' own checkout out of the trail (that is activity, not audit)', function (): void {
        $customer = User::factory()->create();
        $window = DeliveryWindow::factory()->create();
        AuditLog::query()->delete();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'contact' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '08000000000', 'email' => 'a@b.com'],
            'delivery' => ['street' => '1 Road', 'area' => 'Yaba', 'city' => 'Lagos'],
            'delivery_window_id' => $window->id,
            'payment_method' => 'cash_on_delivery',
        ])->assertCreated();

        expect($this->product->fresh()->stock_quantity)->toBe(38)
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('can be paused for bulk loading', function (): void {
        app(AuditLogger::class)->withoutAuditing(fn () => $this->product->update(['price_kobo' => 111_000]));

        expect(AuditLog::query()->count())->toBe(0);

        $this->product->update(['price_kobo' => 222_000]);

        expect(AuditLog::query()->count())->toBe(1);
    });
});

describe('integrity', function (): void {
    it('is append-only', function (): void {
        $log = AuditLog::factory()->create();

        expect(fn () => $log->update(['event' => 'deleted']))->toThrow(LogicException::class)
            ->and(fn () => $log->delete())->toThrow(LogicException::class);
    });

    it('keeps the trail when the actor account is deleted', function (): void {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/inventory/{$this->product->id}", ['stock_quantity' => 1, 'is_available' => true]);
        $this->app['auth']->forgetGuards();
        $this->admin->delete();

        $log = AuditLog::query()->where('auditable_type', Product::class)->sole();
        expect($log->actor_id)->toBeNull()->and($log->actor_name)->toBe('Ada Admin')->and($log->actor_email)->toBe('ada.admin@naijafresh.test');
    });
});

describe('the audit log API', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->admin, 'sanctum');
        $this->put("/api/v1/admin/inventory/{$this->product->id}", ['stock_quantity' => 12, 'is_available' => true]);
        $this->postJson('/api/v1/admin/categories', ['name' => 'Snacks', 'is_active' => true]);
        $this->actingAs($this->super, 'sanctum');
    });

    it('lists newest first with before/after values and filter options', function (): void {
        $response = $this->getJson('/api/v1/admin/system/audit-logs')->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('data.0.model'))->toBe('Category')
            ->and($response->json('data.1.old_values'))->toBe(['stock_quantity' => 40])
            ->and($response->json('filters.events'))->toBe(['created', 'updated', 'deleted'])
            ->and($response->json('filters.models'))->toEqualCanonicalizing(['Category', 'Product']);
    });

    it('filters by event, model, actor, search and date', function (): void {
        expect($this->getJson('/api/v1/admin/system/audit-logs?event=created')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?model=Product')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?actor_id='.$this->admin->id)->json('data'))->toHaveCount(2)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?actor_id='.$this->super->id)->json('data'))->toHaveCount(0)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?search=palm')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?search=ada.admin')->json('data'))->toHaveCount(2)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?from='.now()->addDay()->toDateString())->json('data'))->toHaveCount(0)
            ->and($this->getJson('/api/v1/admin/system/audit-logs?to='.now()->subDays(2)->toDateString())->json('data'))->toHaveCount(0);
    });

    it('rejects an unknown event filter', function (): void {
        $this->getJson('/api/v1/admin/system/audit-logs?event=exploded')->assertUnprocessable();
    });
});
