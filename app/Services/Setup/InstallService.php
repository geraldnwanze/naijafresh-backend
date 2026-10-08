<?php

namespace App\Services\Setup;

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DeliveryWindowSeeder;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\FoodPackSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * First-run setup for a fresh deployment. Safe to run on every boot: it only
 * ever fills in what is missing and never overwrites anything an admin edited.
 */
class InstallService
{
    /**
     * Seeds categories, delivery windows and store settings, each only when its
     * table is still empty. Returns the names of what was seeded.
     *
     * @return list<string>
     */
    public function seedReferenceData(): array
    {
        /** @var array<string, array{0: class-string<Model>, 1: class-string<Seeder>}> $sets */
        $sets = [
            'categories' => [Category::class, CategorySeeder::class],
            'delivery windows' => [DeliveryWindow::class, DeliveryWindowSeeder::class],
            'settings' => [Setting::class, SettingSeeder::class],
        ];

        $seeded = [];

        // Setup isn't "someone changing something": keep it out of the audit trail.
        app(AuditLogger::class)->withoutAuditing(function () use ($sets, &$seeded): void {
            foreach ($sets as $name => [$model, $seeder]) {
                if ($model::query()->doesntExist()) {
                    app()->call([app($seeder), 'run']);
                    $seeded[] = $name;
                }
            }
        });

        return $seeded;
    }

    /**
     * Loads the full demo set (sample products, demo logins, ~170 demo orders and
     * expenses) for development and staging deployments. Returns null in
     * production (the demo logins all share the password "password"), an empty
     * list when the store already has products (so it never doubles up or
     * overwrites a catalogue you built), otherwise the names of what was seeded.
     *
     * @return list<string>|null
     */
    public function seedSampleData(): ?array
    {
        if (app()->isProduction()) {
            return null;
        }

        if (Product::query()->exists()) {
            // A catalogue you built is left alone, but stores seeded before food
            // packs existed get the sample packs once.
            if (Product::query()->where('type', ProductType::FoodPack->value)->exists()) {
                return [];
            }

            app(AuditLogger::class)->withoutAuditing(fn () => app(FoodPackSeeder::class)->run());

            return ['food pack combos'];
        }

        app(AuditLogger::class)->withoutAuditing(function (): void {
            foreach ([ProductSeeder::class, DemoAccountSeeder::class, DemoDataSeeder::class] as $seeder) {
                app()->call([app($seeder), 'run']);
            }
        });

        return ['sample products', 'demo accounts', 'demo orders and expenses'];
    }

    /**
     * Creates the first super admin, or promotes the account that already has
     * this email. Does nothing (returns null) once any super admin exists, so a
     * leftover environment variable can never reset the owner's password.
     */
    public function createFirstSuperAdmin(string $email, string $name, string $password): ?User
    {
        if (User::query()->where('role', UserRole::SuperAdmin->value)->exists()) {
            return null;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $existing->update(['role' => UserRole::SuperAdmin]);

            return $existing;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()])->save();

        return $user;
    }
}
