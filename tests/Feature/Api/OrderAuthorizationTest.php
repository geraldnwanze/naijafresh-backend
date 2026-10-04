<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;

it('stops a customer from viewing another customer\'s order', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $order = Order::factory()->for($owner)->create();

    $this->actingAs($intruder, 'sanctum')
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertForbidden();
});

it('only returns the authenticated customer\'s own orders', function (): void {
    $me = User::factory()->create();
    Order::factory()->for($me)->count(2)->create();
    Order::factory()->count(3)->create();

    $response = $this->actingAs($me, 'sanctum')->getJson('/api/v1/orders')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('blocks a signed-in customer from the admin area', function (): void {
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('blocks a guest from the admin area', function (): void {
    $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
});

it('lets an admin view any order', function (): void {
    $admin = User::factory()->admin()->create();
    $order = Order::factory()->create();

    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")->assertOk();
});

it('rejects checkout for a guest', function (): void {
    $product = Product::factory()->create();

    $this->postJson('/api/v1/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnauthorized();
});
