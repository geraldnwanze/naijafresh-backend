<?php

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\StoreSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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

describe('sample data for dev and staging servers', function (): void {
    it('loads the full demo set into an empty store when asked, without emails or audit noise', function (): void {
        $this->app->detectEnvironment(fn () => 'staging');
        Mail::fake();

        $this->artisan('naijafresh:setup', ['--with-sample-data' => true])
            ->expectsOutputToContain('sample products')
            ->assertSuccessful();

        expect(Product::query()->count())->toBeGreaterThan(20)
            ->and(User::query()->whereIn('email', ['superadmin@naijafresh.test', 'admin@naijafresh.test', 'customer@naijafresh.test'])->count())->toBe(3)
            ->and(Order::query()->count())->toBeGreaterThan(100)
            ->and(Expense::query()->count())->toBeGreaterThan(0)
            ->and(AuditLog::query()->count())->toBe(0)
            ->and(ActivityLog::query()->count())->toBe(0);
    });

    it('is switched on by SEED_SAMPLE_DATA and is then safe to leave on across boots', function (): void {
        $this->app->detectEnvironment(fn () => 'staging');
        config()->set('naijafresh.setup.seed_sample_data', true);

        $this->artisan('naijafresh:setup')->assertSuccessful();
        $products = Product::query()->count();
        $orders = Order::query()->count();
        $edited = Product::query()->orderBy('id')->first();
        $edited->update(['price_kobo' => 123_400]);

        $this->artisan('naijafresh:setup')->expectsOutputToContain('already has products')->assertSuccessful();

        expect(Product::query()->count())->toBe($products)
            ->and(Order::query()->count())->toBe($orders)
            ->and($edited->fresh()->price_kobo)->toBe(123_400);
    });

    it('never loads demo accounts or products in production, and says why', function (): void {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('naijafresh.setup.seed_sample_data', true);

        $this->artisan('naijafresh:setup')->expectsOutputToContain('never loaded in production')->assertSuccessful();

        expect(Product::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0);
    });

    it('leaves a catalogue you built yourself alone, only adding the sample food packs once', function (): void {
        $this->app->detectEnvironment(fn () => 'staging');
        $mine = Product::factory()->for(Category::factory())->create(['name' => 'My own product', 'price_kobo' => 777_700]);

        $this->artisan('naijafresh:setup', ['--with-sample-data' => true])->expectsOutputToContain('food pack combos')->assertSuccessful();
        $this->artisan('naijafresh:setup', ['--with-sample-data' => true])->expectsOutputToContain('already has products')->assertSuccessful();

        expect(Product::query()->where('type', 'food_pack')->count())->toBe(6)
            ->and(Product::query()->count())->toBe(7)
            ->and($mine->fresh()->price_kobo)->toBe(777_700)
            ->and(User::query()->count())->toBe(0)
            ->and(Order::query()->count())->toBe(0);
    });

    it('does nothing unless asked', function (): void {
        $this->app->detectEnvironment(fn () => 'staging');

        $this->artisan('naijafresh:setup')->assertSuccessful();

        expect(Product::query()->count())->toBe(0);
    });

    it('does not double the demo history when the demo seeder runs again', function (): void {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->artisan('naijafresh:setup', ['--with-sample-data' => true])->assertSuccessful();
        $orders = Order::query()->count();
        $expenses = Expense::query()->count();

        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->assertSuccessful();

        expect(Order::query()->count())->toBe($orders)->and(Expense::query()->count())->toBe($expenses);
    });
});
