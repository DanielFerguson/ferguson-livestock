<?php

use App\Support\Catalogue;
use App\Support\Product;

function catalogue(): Catalogue
{
    return new Catalogue([
        'beef-box-5kg' => [
            'name' => '5kg Beef Box',
            'description' => 'A balanced mix.',
            'type' => 'box',
            'price' => 16000,
            'box' => [
                'weight_kg' => 5,
                'contents' => ['750g primary cuts', '1kg mince'],
                'best_for' => 'Couples and smaller households',
                'freezer_guidance' => 'Allow roughly one standard freezer drawer.',
            ],
        ],
        'beef-box-10kg' => [
            'name' => '10kg Beef Box',
            'description' => 'A larger balanced mix.',
            'type' => 'box',
            'price' => 27500,
            'box' => [
                'weight_kg' => 10,
                'contents' => ['1.5kg primary cuts', '2kg mince'],
                'best_for' => 'Families and regular beef eaters',
                'freezer_guidance' => 'Allow roughly two standard freezer drawers.',
            ],
        ],
        'beef-mince-500g' => [
            'name' => '500g Beef Mince',
            'description' => 'Versatile mince.',
            'type' => 'extra',
            'price' => 1200,
        ],
        'delivery-fee' => [
            'name' => 'Delivery',
            'description' => 'Flat fee delivery.',
            'type' => 'delivery',
            'price' => 1500,
        ],
    ]);
}

it('lists the boxes in catalogue order', function () {
    expect(catalogue()->boxes()->map(fn (Product $box) => $box->slug)->all())
        ->toBe(['beef-box-5kg', 'beef-box-10kg']);
});

it('lists the individual cuts', function () {
    expect(catalogue()->extras()->map(fn (Product $extra) => $extra->name)->all())
        ->toBe(['500g Beef Mince']);
});

it('finds a product by slug', function () {
    $box = catalogue()->find('beef-box-10kg');

    expect($box->name)->toBe('10kg Beef Box')
        ->and($box->price)->toBe(27500)
        ->and($box->box?->bestFor)->toBe('Families and regular beef eaters')
        ->and($box->box?->contents)->toBe(['1.5kg primary cuts', '2kg mince']);
});

it('works out the price per kilogram of a box', function () {
    expect(catalogue()->find('beef-box-5kg')->box?->perKgPrice)->toBe(3200)
        ->and(catalogue()->find('beef-box-10kg')->box?->perKgPrice)->toBe(2750);
});

it('knows the delivery fee', function () {
    expect(catalogue()->deliveryFee())->toBe(1500);
});

it('knows the cheapest box price', function () {
    expect(catalogue()->startingBoxPrice())->toBe(16000);
});

it('rejects an unknown product', function () {
    catalogue()->find('wagyu');
})->throws(InvalidArgumentException::class, 'wagyu');
