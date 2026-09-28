<?php

use App\Models\Drop;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoDropSeeder;
use Database\Seeders\ProductSeeder;

beforeEach(function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('renders the admin pages in a real browser without errors', function () {
    visit([
        '/admin/drops',
        '/admin/drops/create',
        '/admin/drops/'.Drop::sole()->id.'/edit',
        '/admin/products',
        '/admin/products/'.Product::where('slug', 'beef-box-10kg')->sole()->id.'/edit',
    ])->assertNoJavaScriptErrors();
});
