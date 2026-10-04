<?php

use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;

beforeEach(function (): void {
    $this->customer = User::factory()->create();
    $this->order = Order::factory()->for($this->customer)->create();
});

it('requires a signed-in user', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
});

it('lists my notifications newest first with the unread count', function (): void {
    $this->customer->notify(new OrderPlacedNotification($this->order));
    $this->travel(5)->minutes();
    $this->customer->notify(new OrderStatusChangedNotification($this->order));

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/notifications')->assertOk();

    expect(collect($response->json('data'))->pluck('kind')->all())->toBe(['order_status', 'order_placed'])
        ->and($response->json('unread_count'))->toBe(2)
        ->and($response->json('data.0'))->toMatchArray([
            'url' => "/orders/{$this->order->id}",
            'order_reference' => $this->order->reference,
            'is_read' => false,
            'read_at' => null,
        ])
        ->and($response->json('data.0.title'))->toContain($this->order->reference);
});

it('never shows another user their notifications', function (): void {
    $stranger = User::factory()->create();
    $this->customer->notify(new OrderPlacedNotification($this->order));

    $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('unread_count', 0);
});

it('paginates the list', function (): void {
    foreach (range(1, 17) as $_) {
        $this->customer->notify(new OrderStatusChangedNotification($this->order));
    }

    $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/notifications')
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 17)
        ->assertJsonPath('unread_count', 17);
});

it('returns a cheap unread count for the bell badge', function (): void {
    $this->customer->notify(new OrderPlacedNotification($this->order));
    $this->customer->notify(new OrderStatusChangedNotification($this->order));
    $this->customer->notifications()->first()->markAsRead();

    $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);
});

it('marks one notification read', function (): void {
    $this->customer->notify(new OrderPlacedNotification($this->order));
    $notification = $this->customer->notifications()->first();

    $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.is_read', true);

    expect($notification->fresh()->read_at)->not->toBeNull()
        ->and($this->customer->unreadNotifications()->count())->toBe(0);
});

it('does not let anyone mark someone else\'s notification read', function (): void {
    $this->customer->notify(new OrderPlacedNotification($this->order));
    $notification = $this->customer->notifications()->first();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('marks all of my notifications read without touching anyone else\'s', function (): void {
    $other = User::factory()->create();
    $this->customer->notify(new OrderPlacedNotification($this->order));
    $this->customer->notify(new OrderStatusChangedNotification($this->order));
    $other->notify(new OrderPlacedNotification($this->order));

    $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($this->customer->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);
});

it('gives admins their own bell entries linking to the admin order page', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->notify(new NewOrderNotification($this->order));

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'new_order')
        ->assertJsonPath('data.0.url', "/admin/orders/{$this->order->id}");
});
