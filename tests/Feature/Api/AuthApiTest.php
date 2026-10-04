<?php

use App\Models\User;

it('registers a customer and returns an api token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
        'phone' => '08012345678',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated();

    expect($response->json('token'))->toBeString()
        ->and($response->json('user.role'))->toBe('customer')
        ->and($response->json('user.is_admin'))->toBeFalse();

    $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'role' => 'customer']);
});

it('logs in with valid credentials', function (): void {
    User::factory()->create(['email' => 'me@example.com', 'password' => 'password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'me@example.com',
        'password' => 'password',
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('rejects a wrong password', function (): void {
    User::factory()->create(['email' => 'me@example.com', 'password' => 'password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'me@example.com',
        'password' => 'wrong',
    ])->assertStatus(422);
});

it('requires authentication for the current-user endpoint', function (): void {
    $this->getJson('/api/v1/auth/user')->assertUnauthorized();
});

it('revokes the token on logout', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});
