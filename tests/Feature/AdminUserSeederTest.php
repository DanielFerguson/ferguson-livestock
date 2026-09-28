<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Filament\Facades\Filament;

it('seeds an admin who can access the panel', function () {
    config(['shop.admin_email' => 'owner@example.com', 'shop.admin_password' => 'secret-password']);

    $this->seed(AdminUserSeeder::class);

    expect(User::sole()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('does not duplicate the admin when run twice', function () {
    config(['shop.admin_email' => 'owner@example.com', 'shop.admin_password' => 'secret-password']);

    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    expect(User::count())->toBe(1);
});

it('refuses to seed without an admin email', function () {
    config(['shop.admin_email' => null]);

    $this->seed(AdminUserSeeder::class);
})->throws(RuntimeException::class, 'SHOP_ADMIN_EMAIL');

it('refuses to seed without a password outside local', function () {
    config(['shop.admin_email' => 'owner@example.com', 'shop.admin_password' => null]);

    $this->seed(AdminUserSeeder::class);
})->throws(RuntimeException::class, 'SHOP_ADMIN_PASSWORD');
