<?php

namespace Database\Seeders;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed data isn't "someone changing something": keep it out of the audit trail.
        app(AuditLogger::class)->withoutAuditing(function (): void {
            $this->call([
                CategorySeeder::class,
                DeliveryWindowSeeder::class,
                SettingSeeder::class,
            ]);

            // Sample catalogue and demo logins (password "password") are for development only.
            if (! app()->isProduction()) {
                $this->call([
                    ProductSeeder::class,
                    DemoAccountSeeder::class,
                ]);
            }

            // Sample order history + expenses so the accounting pages aren't empty.
            if (! app()->isProduction()) {
                $this->call(DemoDataSeeder::class);
            }
        });
    }
}
