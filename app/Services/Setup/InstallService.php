<?php

namespace App\Services\Setup;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\DeliveryWindow;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DeliveryWindowSeeder;
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
