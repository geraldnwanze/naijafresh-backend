<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\StoreSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Hash;

describe('reference data', function (): void {
    it('seeds categories, delivery windows and settings on an empty database', function (): void {
        $this->artisan('naijafresh:setup')->assertSuccessful();

        expect(Category::query()->count())->toBeGreaterThan(0)
            ->and(DeliveryWindow::query()->count())->toBeGreaterThan(0)
            ->and(Setting::query()->count())->toBeGreaterThan(0);
    });

    it('never overwrites what an admin has since edited, however often it runs', function (): void {
        $this->artisan('naijafresh:setup')->assertSuccessful();

        Category::query()->first()->update(['name' => 'Renamed by the owner']);
        app(StoreSettings::class)->set(StoreSettings::DELIVERY_FEE_KOBO, 999_900);
        DeliveryWindow::query()->first()->delete();
        $windows = DeliveryWindow::query()->count();

        $this->artisan('naijafresh:setup')->assertSuccessful();

        expect(Category::query()->where('name', 'Renamed by the owner')->exists())->toBeTrue()
            ->and(app(StoreSettings::class)->deliveryFeeKobo())->toBe(999_900)
            ->and(DeliveryWindow::query()->count())->toBe($windows);
    });

    it('does not write the seed data into the audit trail', function (): void {
        $this->artisan('naijafresh:setup')->assertSuccessful();

        expect(AuditLog::query()->count())->toBe(0);
    });
});

describe('first super admin', function (): void {
    it('is created from the options, verified, with a hashed password', function (): void {
        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--name' => 'Chidi Owner', '--password' => 'a-long-enough-password'])
            ->assertSuccessful();

        $owner = User::query()->where('email', 'owner@naijafresh.ng')->sole();

        expect($owner->role)->toBe(UserRole::SuperAdmin)
            ->and($owner->name)->toBe('Chidi Owner')
            ->and($owner->email_verified_at)->not->toBeNull()
            ->and(Hash::check('a-long-enough-password', $owner->password))->toBeTrue();
    });

    it('is created from the environment-backed config, as on a deploy', function (): void {
        config()->set('naijafresh.setup.super_admin', ['name' => 'Env Owner', 'email' => 'env@naijafresh.ng', 'password' => 'from-the-environment']);

        $this->artisan('naijafresh:setup')->assertSuccessful();

        expect(User::query()->where('email', 'env@naijafresh.ng')->value('role'))->toBe(UserRole::SuperAdmin);
    });

    it('skips quietly when no credentials are configured', function (): void {
        $this->artisan('naijafresh:setup')->expectsOutputToContain('skipping')->assertSuccessful();

        expect(User::query()->count())->toBe(0);
    });

    it('can never reset the owner: once a super admin exists the credentials are ignored', function (): void {
        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--password' => 'the-original-password'])->assertSuccessful();

        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--password' => 'a-hijacking-password'])
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();
        $this->artisan('naijafresh:setup', ['--email' => 'attacker@example.com', '--password' => 'a-hijacking-password'])->assertSuccessful();

        expect(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(1)
            ->and(Hash::check('the-original-password', User::query()->where('email', 'owner@naijafresh.ng')->value('password')))->toBeTrue()
            ->and(User::query()->where('email', 'attacker@example.com')->exists())->toBeFalse();
    });

    it('promotes the account that already has that email, keeping its password', function (): void {
        $customer = User::factory()->create(['email' => 'owner@naijafresh.ng', 'password' => 'my-own-password']);

        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--password' => 'a-different-password'])
            ->expectsOutputToContain('Promoted')
            ->assertSuccessful();

        expect($customer->fresh()->role)->toBe(UserRole::SuperAdmin)
            ->and(Hash::check('my-own-password', $customer->fresh()->password))->toBeTrue();
    });

    it('rejects a bad email and a short password without creating anything', function (): void {
        $this->artisan('naijafresh:setup', ['--email' => 'not-an-email', '--password' => 'long-enough-password'])->assertFailed();
        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--password' => 'short'])->assertFailed();

        expect(User::query()->count())->toBe(0);
    });

    it('wants a 12+ character password in production', function (): void {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--password' => 'only-10-chr'])->assertFailed();
        $this->artisan('naijafresh:setup', ['--email' => 'owner@naijafresh.ng', '--password' => 'twelve-chars!'])->assertSuccessful();

        expect(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(1);
    });
});

describe('production seeding is safe', function (): void {
    it('never creates demo accounts or sample products in production', function (): void {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        expect(User::query()->count())->toBe(0)
            ->and(Product::query()->count())->toBe(0)
            ->and(Category::query()->count())->toBeGreaterThan(0);
    });

    it('still seeds the demo logins for development', function (): void {
        $this->app->detectEnvironment(fn () => 'testing');

        $this->seed(DemoAccountSeeder::class);

        expect(User::query()->whereIn('email', ['superadmin@naijafresh.test', 'admin@naijafresh.test', 'customer@naijafresh.test'])->count())->toBe(3);
    });
});
