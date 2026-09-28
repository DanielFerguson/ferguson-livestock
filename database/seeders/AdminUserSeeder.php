<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The shop owner's admin account, using SHOP_ADMIN_EMAIL so it passes canAccessPanel().
 * Seeded locally by DatabaseSeeder; run it elsewhere with --class=AdminUserSeeder.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('shop.admin_email');

        if (! is_string($email) || $email === '') {
            throw new RuntimeException('Set SHOP_ADMIN_EMAIL before seeding the admin user.');
        }

        $password = config('shop.admin_password');

        if (! is_string($password) || $password === '') {
            if (! app()->isLocal()) {
                throw new RuntimeException('Set SHOP_ADMIN_PASSWORD before seeding the admin user.');
            }

            $password = 'password';
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );
    }
}
