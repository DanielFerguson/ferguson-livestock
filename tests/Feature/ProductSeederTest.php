<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Product;
use Database\Seeders\ProductSeeder;

it('seeds the catalogue in the order it is listed', function (): void {
    $this->seed(ProductSeeder::class);

    expect(Product::query()->orderBy('sort')->pluck('slug')->all())->toBe([
        'beef-box-5kg', 'beef-box-10kg', 'beef-mince-500g', 'beef-bones-2kg', 'sausage-packs-6',
        'rump-steak-2', 'porterhouse', 'diced-chuck', 'delivery-fee',
    ]);
});

it('makes the 10kg box use two units of the 5kg box stock', function (): void {
    $this->seed(ProductSeeder::class);

    $tenKg = Product::query()->where('slug', 'beef-box-10kg')->sole();

    expect($tenKg->stockProduct?->slug)->toBe('beef-box-5kg')
        ->and($tenKg->stock_units)->toBe(2)
        ->and($tenKg->hasOwnStock())->toBeFalse()
        ->and(Product::query()->where('slug', 'beef-box-5kg')->sole()->hasOwnStock())->toBeTrue();
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

    expect(Product::query()->count())->toBe(9)
        ->and(Product::query()->where('slug', 'porterhouse')->value('name'))->toBe('Porterhouse (thick cut)');
});
