<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Bundle;
use App\Models\Product;
use Database\Seeders\ProductSeeder;

it('seeds the catalogue in the order it is listed', function (): void {
    $this->seed(ProductSeeder::class);

    expect(Product::query()->orderBy('sort')->pluck('slug')->all())->toBe([
        'beef-box-5kg', 'beef-box-10kg', 'beef-mince-500g', 'beef-mince-5kg', 'beef-mince-10kg', 'beef-bones-2kg',
        'sausage-packs-6', 'rump-steak-2', 'porterhouse', 'diced-chuck', 'delivery-fee',
    ]);
});

it('makes the 10kg box a bundle of two 5kg boxes', function (): void {
    $this->seed(ProductSeeder::class);

    $tenKg = Product::query()->where('slug', 'beef-box-10kg')->sole();

    expect(data_get($tenKg, 'bundle.component.slug'))->toBe('beef-box-5kg')
        ->and(data_get($tenKg, 'bundle.units'))->toBe(2)
        ->and($tenKg->hasOwnStock())->toBeFalse()
        ->and(Product::query()->where('slug', 'beef-box-5kg')->sole()->hasOwnStock())->toBeTrue();
});

it('makes the mince packs bundles of the 500g mince, and extras', function (): void {
    $this->seed(ProductSeeder::class);

    $packs = Product::query()->whereIn('slug', ['beef-mince-5kg', 'beef-mince-10kg'])->with('bundle.component')->get()->keyBy('slug');

    expect(data_get($packs, 'beef-mince-5kg.bundle.units'))->toBe(10)
        ->and(data_get($packs, 'beef-mince-10kg.bundle.units'))->toBe(20)
        ->and(data_get($packs, 'beef-mince-5kg.bundle.component.slug'))->toBe('beef-mince-500g')
        ->and(data_get($packs, 'beef-mince-5kg.type'))->toBe(ProductType::Extra)
        ->and($packs->get('beef-mince-5kg')?->hasOwnStock())->toBeFalse();
});

it('keeps box contents and types', function (): void {
    $this->seed(ProductSeeder::class);

    $fiveKg = Product::query()->where('slug', 'beef-box-5kg')->sole();

    expect($fiveKg->type)->toBe(ProductType::Box)
        ->and($fiveKg->box_details['weight_kg'] ?? null)->toBe(5)
        ->and($fiveKg->box_details['best_for'] ?? null)->toBe('Couples and smaller households')
        ->and(Product::query()->where('slug', 'delivery-fee')->sole()->type)->toBe(ProductType::Delivery);
});

it('can be run again without duplicating products or overwriting admin edits', function (): void {
    $this->seed(ProductSeeder::class);
    Product::query()->where('slug', 'porterhouse')->update(['name' => 'Porterhouse (thick cut)']);

    $this->seed(ProductSeeder::class);

    expect(Product::query()->count())->toBe(11)
        ->and(Bundle::query()->count())->toBe(3)
        ->and(Product::query()->where('slug', 'porterhouse')->value('name'))->toBe('Porterhouse (thick cut)');
});
