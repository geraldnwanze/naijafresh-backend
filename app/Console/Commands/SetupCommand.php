<?php

namespace App\Console\Commands;

use App\Services\Setup\InstallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class SetupCommand extends Command
{
    protected $signature = 'naijafresh:setup
        {--email= : Email for the first super admin (default: SUPER_ADMIN_EMAIL)}
        {--name= : Name for the first super admin (default: SUPER_ADMIN_NAME)}
        {--password= : Password for the first super admin (default: SUPER_ADMIN_PASSWORD)}
        {--with-sample-data : Also load sample products, demo logins and demo orders into an empty store (default: SEED_SAMPLE_DATA; never in production)}';

    protected $description = 'Prepare a fresh install: seed categories, delivery windows and settings (only when empty) and create the first super admin';

    public function handle(InstallService $install): int
    {
        $seeded = $install->seedReferenceData();
        $this->components->info($seeded === [] ? 'Reference data already in place.' : 'Seeded '.implode(', ', $seeded).'.');

        if ($this->option('with-sample-data') || config('naijafresh.setup.seed_sample_data')) {
            $sample = $install->seedSampleData();

            $this->components->info(match (true) {
                $sample === null => 'Sample data is never loaded in production (APP_ENV=production); use APP_ENV=staging for a dev server.',
                $sample === [] => 'Sample data skipped: the store already has products.',
                default => 'Seeded '.implode(', ', $sample).'.',
            });
        }

        $email = $this->option('email') ?: config('naijafresh.setup.super_admin.email');
        $password = $this->option('password') ?: config('naijafresh.setup.super_admin.password');
        $name = $this->option('name') ?: config('naijafresh.setup.super_admin.name');

        if (! $email || ! $password) {
            $this->components->info('No super admin credentials given (SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD); skipping.');

            return self::SUCCESS;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:'.(app()->isProduction() ? 12 : 8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = $install->createFirstSuperAdmin($email, $name, $password);

        $this->components->info(match (true) {
            $user === null => 'A super admin already exists; leaving accounts untouched.',
            $user->wasRecentlyCreated => "Created super admin {$user->email}.",
            default => "Promoted {$user->email} to super admin.",
        });

        return self::SUCCESS;
    }
}
