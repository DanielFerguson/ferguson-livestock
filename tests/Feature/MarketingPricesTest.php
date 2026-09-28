<?php

use App\Models\DropItem;
use App\Models\Product;
use Database\Seeders\DemoDropSeeder;
use Database\Seeders\ProductSeeder;

it('shows the featured drop’s prices on the marketing pages', function (string $path, string ...$prices) {
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);

    $this->get($path)->assertOk()->assertSeeInOrder(array_values($prices));
})->with([
    'home' => ['/', 'Beef boxes from $160', '$160', '$32/kg', '$275', '$27.50/kg'],
    'beef boxes' => ['/beef-boxes', '5kg Beef Box', '$160', '$32/kg', '10kg Beef Box', '$275', '$27.50/kg'],
    'delivery' => ['/delivery', '$15 flat fee'],
]);

it('hides prices until a drop sets them', function () {
    $this->seed(ProductSeeder::class);

    $this->get('/')->assertOk()->assertSee('Farm-direct beef boxes')->assertDontSee('$160');
    $this->get('/beef-boxes')->assertOk()->assertSee('Price set with each drop');
    $this->get('/delivery')->assertOk()->assertSee('One flat fee');
});

it('shows a new price straight away, even on a cached page', function () {
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);
    $this->get('/beef-boxes')->assertSee('$160');

    DropItem::whereBelongsTo(Product::where('slug', 'beef-box-5kg')->sole())->sole()->update(['price' => 17000]);

    $this->get('/beef-boxes')->assertSee('$170')->assertDontSee('$160');
});
