<?php

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;

beforeEach(function (): void {
    config()->set('naijafresh.logs.directory', sys_get_temp_dir().'/nf-no-logs-'.uniqid());
    $this->super = User::factory()->superAdmin()->create(['name' => 'Owner']);
});

describe('overview', function (): void {
    it('summarises the last 24 hours', function (): void {
        AuditLog::query()->delete();
        AuditLog::factory()->count(2)->create();
        AuditLog::factory()->create(['created_at' => now()->subDays(3)]);
        ActivityLog::factory()->count(3)->create();
        ActivityLog::factory()->event(ActivityEvent::LoginFailed)->count(2)->create();
        ActivityLog::factory()->event(ActivityEvent::OrderPlaced)->create();
        ActivityLog::factory()->create(['created_at' => now()->subDays(3)]);
        User::factory()->admin()->create();

        $data = $this->actingAs($this->super, 'sanctum')->getJson('/api/v1/admin/system/overview')->assertOk()->json('data');

        expect($data['stats'])->toBe([
            'audit_changes_24h' => 2,
            'sign_ins_24h' => 3,
            'failed_sign_ins_24h' => 2,
            'orders_24h' => 1,
            'errors_24h' => 0,
        ])
            ->and(collect($data['users_by_role'])->pluck('count', 'role')->all())->toBe(['customer' => 0, 'admin' => 1, 'super_admin' => 1])
            ->and($data['recent_audit'])->toHaveCount(3)
            ->and($data['recent_activity'])->toHaveCount(7);
    });

    it('flags IP addresses with repeated failed sign-ins', function (): void {
        ActivityLog::factory()->event(ActivityEvent::LoginFailed)->count(4)->create(['ip_address' => '203.0.113.9']);
        ActivityLog::factory()->event(ActivityEvent::LoginFailed)->count(2)->create(['ip_address' => '203.0.113.10']);

        $this->actingAs($this->super, 'sanctum')->getJson('/api/v1/admin/system/overview')
            ->assertJsonPath('data.failed_sign_in_ips', [['ip_address' => '203.0.113.9', 'attempts' => 4]]);
    });
});

describe('user roles', function (): void {
    beforeEach(function (): void {
        $this->ada = User::factory()->create(['name' => 'Ada Customer', 'email' => 'ada@example.com']);
        $this->bola = User::factory()->admin()->create(['name' => 'Bola Admin', 'email' => 'bola@example.com']);
        $this->actingAs($this->super, 'sanctum');
    });

    it('lists accounts staff first, with order counts and role options', function (): void {
        Order::factory()->for($this->ada)->count(2)->create();

        $response = $this->getJson('/api/v1/admin/system/users')->assertOk();

        expect(array_column($response->json('data'), 'role'))->toBe(['super_admin', 'admin', 'customer'])
            ->and(collect($response->json('data'))->firstWhere('email', 'ada@example.com')['orders_count'])->toBe(2)
            ->and(collect($response->json('filters.roles'))->pluck('value')->all())->toBe(['customer', 'admin', 'super_admin']);
    });

    it('filters by role and searches by name or email', function (): void {
        expect($this->getJson('/api/v1/admin/system/users?role=admin')->json('data'))->toHaveCount(1)
            ->and($this->getJson('/api/v1/admin/system/users?search=ADA')->json('data.0.email'))->toBe('ada@example.com')
            ->and($this->getJson('/api/v1/admin/system/users?search=bola@')->json('data'))->toHaveCount(1);
    });

    it('promotes a customer to admin, who can then use the admin area', function (): void {
        $this->putJson("/api/v1/admin/system/users/{$this->ada->id}/role", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.is_admin', true);

        $this->actingAs($this->ada->fresh(), 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk();
    });

    it('demotes an admin, who loses access straight away', function (): void {
        $this->putJson("/api/v1/admin/system/users/{$this->bola->id}/role", ['role' => 'customer'])->assertOk();

        $this->actingAs($this->bola->fresh(), 'sanctum')->getJson('/api/v1/admin/dashboard')->assertForbidden();
    });

    it('will not let a super admin change their own role', function (): void {
        $this->putJson("/api/v1/admin/system/users/{$this->super->id}/role", ['role' => 'customer'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "You can't change your own role.");

        expect($this->super->fresh()->isSuperAdmin())->toBeTrue();
    });

    it('rejects an unknown role', function (): void {
        $this->putJson("/api/v1/admin/system/users/{$this->ada->id}/role", ['role' => 'emperor'])->assertUnprocessable();
    });

    it('is closed to ordinary admins', function (): void {
        $this->actingAs($this->bola, 'sanctum')
            ->putJson("/api/v1/admin/system/users/{$this->ada->id}/role", ['role' => 'super_admin'])
            ->assertForbidden();

        expect($this->ada->fresh()->role->value)->toBe('customer');
    });
});
