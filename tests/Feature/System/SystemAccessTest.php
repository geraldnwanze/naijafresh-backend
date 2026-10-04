<?php

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Services\Notifications\OrderNotifier;
use Illuminate\Support\Facades\Notification;

$endpoints = [
    'overview' => '/api/v1/admin/system/overview',
    'audit logs' => '/api/v1/admin/system/audit-logs',
    'activity logs' => '/api/v1/admin/system/activity-logs',
    'application logs' => '/api/v1/admin/system/application-logs',
    'users' => '/api/v1/admin/system/users',
];

it('keeps the system area closed to guests, customers and ordinary admins', function (string $url): void {
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->admin()->create(), 'sanctum')->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('message', 'This area is for super admins only.');
})->with($endpoints);

it('opens the system area to super admins', function (string $url): void {
    $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')->getJson($url)->assertOk();
})->with($endpoints);

it('lets a super admin do everything an admin can', function (): void {
    $super = User::factory()->superAdmin()->create();

    expect($super->isAdmin())->toBeTrue()->and($super->isSuperAdmin())->toBeTrue();

    $this->actingAs($super, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk();
    $this->actingAs($super, 'sanctum')->getJson('/api/v1/admin/orders')->assertOk();
    $this->actingAs($super, 'sanctum')->getJson('/api/v1/admin/settings')->assertOk();
});

it('does not make ordinary admins super admins', function (): void {
    $admin = User::factory()->admin()->create();

    expect($admin->isAdmin())->toBeTrue()->and($admin->isSuperAdmin())->toBeFalse();
});

it('tells the frontend who is a super admin', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')->getJson('/api/v1/auth/user')
        ->assertJsonPath('data.role', UserRole::SuperAdmin->value)
        ->assertJsonPath('data.is_admin', true)
        ->assertJsonPath('data.is_super_admin', true);

    $this->actingAs(User::factory()->admin()->create(), 'sanctum')->getJson('/api/v1/auth/user')
        ->assertJsonPath('data.is_admin', true)
        ->assertJsonPath('data.is_super_admin', false);
});

it('sends admin alerts to super admins in-app only, never by email', function (): void {
    Notification::fake();
    $super = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();

    app(OrderNotifier::class)->orderPlaced(Order::factory()->for($customer)->create());

    Notification::assertSentTo($super, NewOrderNotification::class, fn ($n, array $channels) => $channels === ['database']);
    Notification::assertSentTo($admin, NewOrderNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
    Notification::assertNotSentTo($customer, NewOrderNotification::class);
});
