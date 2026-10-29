<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Exceptions\InsufficientStock;
use App\Models\DropItem;
use App\Models\Product;
use App\Stock\DropSnapshot;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;

/**
 * A 5kg mince pack is ten 500g packs: an extra packed from another extra's stock.
 */
function bulkMincePack(StockedDrop $shop): DropItem
{
    $pack = Product::factory()->bundleOf($shop->mince->product, 10)->create(['name' => '5kg Mince Pack']);

    return DropItem::factory()->for($shop->drop)->for($pack)->withoutStock()->create(['price' => 11000, 'stripe_price_id' => 'price_mince_5kg']);
}

it('takes ten 500g packs from the mince stock for each 5kg mince pack', function (): void {
    $shop = StockedDrop::create(mince: 25);
    $pack = bulkMincePack($shop);

    app(StockLedger::class)->reserve($shop->drop, [$pack->id => 2], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));

    expect($shop->mince->refresh()->available)->toBe(5);
});

it('refuses a bulk pack the 500g stock cannot cover, and says how many are left', function (): void {
    $shop = StockedDrop::create(mince: 25);
    $pack = bulkMincePack($shop);

    try {
        app(StockLedger::class)->reserve($shop->drop, [$pack->id => 3], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));
    } catch (InsufficientStock $exception) {
        expect($exception->available)->toBe([$pack->id => 2]);
    }

    expect($shop->mince->refresh()->available)->toBe(25);
});

it('shows how many bulk packs the 500g stock covers', function (): void {
    $shop = StockedDrop::create(mince: 25);
    $pack = bulkMincePack($shop);

    expect(data_get(DropSnapshot::current(), 'items.'.$pack->product->slug.'.available'))->toBe(2);
});

it('gives a bundle’s stock back to its component when the order is released', function (): void {
    $shop = StockedDrop::create(mince: 25);
    $pack = bulkMincePack($shop);
    $ledger = app(StockLedger::class);

    $order = $ledger->reserve($shop->drop, [$pack->id => 2, $shop->mince->id => 3], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));

    expect($shop->mince->refresh()->available)->toBe(2);

    $ledger->release($order);

    expect($shop->mince->refresh()->available)->toBe(25);
});

it('keeps releasing at the units the order was taken at, even if the bundle changes later', function (): void {
    $shop = StockedDrop::create(mince: 25);
    $pack = bulkMincePack($shop);
    $ledger = app(StockLedger::class);

    $order = $ledger->reserve($shop->drop, [$pack->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));
    $pack->product->bundle?->update(['units' => 4]);

    $ledger->release($order);

    expect($shop->mince->refresh()->available)->toBe(25);
});

it('trims a mixed order of a pack and 500g packs together, keeping the pack', function (): void {
    $shop = StockedDrop::create(mince: 12);
    $pack = bulkMincePack($shop);

    $cut = app(StockLedger::class)->fit($shop->drop, [$pack->id => 1, $shop->mince->id => 3]);

    expect($cut)->toBe([$shop->mince->id => 2]);
});

it('cuts nothing when the whole order is still in stock', function (): void {
    $shop = StockedDrop::create(mince: 13);
    $pack = bulkMincePack($shop);

    expect(app(StockLedger::class)->fit($shop->drop, [$pack->id => 1, $shop->mince->id => 3]))->toBe([]);
});

it('takes a pack off the order when there is not a pack’s worth left, and keeps the singles', function (): void {
    $shop = StockedDrop::create(mince: 9);
    $pack = bulkMincePack($shop);

    expect(app(StockLedger::class)->fit($shop->drop, [$pack->id => 1, $shop->mince->id => 4]))->toBe([$pack->id => 0]);
});

it('tells the order form which stock each item sells from', function (): void {
    $shop = StockedDrop::create(mince: 25);
    $pack = bulkMincePack($shop);
    $items = DropSnapshot::current()['items'];

    expect($items[$pack->product->slug])->toMatchArray(['available' => 2, 'pool' => $shop->mince->product->slug, 'units' => 10])
        ->and($items[$shop->mince->product->slug])->toMatchArray(['available' => 25, 'pool' => $shop->mince->product->slug, 'units' => 1])
        ->and($items[$shop->largeBox->product->slug])->toMatchArray(['pool' => $shop->box->product->slug, 'units' => 2]);
});
