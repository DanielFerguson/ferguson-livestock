<?php

use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use App\Support\Catalogue;
use App\Support\CatalogueEntry;
use Database\Seeders\DemoDropSeeder;
use Database\Seeders\ProductSeeder;

it('lists boxes and individual cuts in catalogue order', function () {
    $this->seed(ProductSeeder::class);

    $catalogue = Catalogue::fromDatabase();

    expect($catalogue->boxes()->map(fn (CatalogueEntry $box) => $box->slug)->all())->toBe(['beef-box-5kg', 'beef-box-10kg'])
        ->and($catalogue->extras())->toHaveCount(6)
        ->and($catalogue->find('beef-box-5kg')?->box?->bestFor)->toBe('Couples and smaller households');
});

it('prices products from the featured drop', function () {
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);

    $catalogue = Catalogue::fromDatabase();

    expect($catalogue->find('beef-box-5kg')?->price)->toBe(16000)
        ->and($catalogue->find('beef-box-5kg')?->box?->perKgPrice)->toBe(3200)
        ->and($catalogue->find('beef-box-10kg')?->box?->perKgPrice)->toBe(2750)
        ->and($catalogue->startingBoxPrice())->toBe(16000)
        ->and($catalogue->deliveryFee())->toBe(1500);
});

it('uses the next scheduled drop’s prices between drops', function () {
    $box = Product::factory()->box()->create();
    DropItem::factory()->for(Drop::factory()->closed())->for($box)->create(['price' => 15000]);
    DropItem::factory()->for(Drop::factory()->state(['opens_at' => now()->addWeek()]))->for($box)->create(['price' => 17000]);

    expect(Catalogue::fromDatabase()->boxes()->first()?->price)->toBe(17000);
});

it('has no prices until a drop sets them', function () {
    $this->seed(ProductSeeder::class);

    $catalogue = Catalogue::fromDatabase();

    expect($catalogue->find('beef-box-5kg')?->price)->toBeNull()
        ->and($catalogue->find('beef-box-5kg')?->box?->perKgPrice)->toBeNull()
        ->and($catalogue->startingBoxPrice())->toBeNull()
        ->and($catalogue->deliveryFee())->toBeNull();
});

it('leaves a product unpriced when the drop doesn’t include it', function () {
    $fiveKg = Product::factory()->box()->create();
    $tenKg = Product::factory()->box(10)->create();
    DropItem::factory()->for(Drop::factory()->open())->for($fiveKg)->create(['price' => 16000]);

    $catalogue = Catalogue::fromDatabase();

    expect($catalogue->find($tenKg->slug)?->price)->toBeNull()
        ->and($catalogue->startingBoxPrice())->toBe(16000)
        ->and($catalogue->find($fiveKg->slug)?->type)->toBe(ProductType::Box);
});
