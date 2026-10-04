<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'superadmin@naijafresh.test'],
            [
                'name' => 'NaijaFresh Owner',
                'phone' => '08000000000',
                'role' => UserRole::SuperAdmin,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'admin@naijafresh.test'],
            [
                'name' => 'NaijaFresh Admin',
                'phone' => '08000000001',
                'role' => UserRole::Admin,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'customer@naijafresh.test'],
            [
                'name' => 'Ada Customer',
                'phone' => '08000000002',
                'role' => UserRole::Customer,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
