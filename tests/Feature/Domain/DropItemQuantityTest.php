<?php

use App\Exceptions\QuantityBelowCommitted;
use App\Models\DropItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('starts with all of its quantity available', function () {
    $item = DropItem::factory()->create(['quantity' => 5, 'available' => null]);

    expect($item->refresh()->available)->toBe(5);
});

it('changes quantity without disturbing units already held or sold', function () {
    // 2 of 5 are in customers' checkouts.
    $item = DropItem::factory()->create(['quantity' => 5, 'available' => 3]);

    $item->adjustQuantityTo(4);

    expect($item->refresh()->only('quantity', 'available'))->toBe(['quantity' => 4, 'available' => 2]);

    $item->adjustQuantityTo(8);

    expect($item->refresh()->only('quantity', 'available'))->toBe(['quantity' => 8, 'available' => 6]);
});

it('uses the latest availability, not a stale copy', function () {
    $item = DropItem::factory()->create(['quantity' => 5, 'available' => 5]);

    // A customer reserves 3 after the admin opened the form.
    DB::table('drop_items')->where('id', $item->id)->update(['available' => 2]);

    $item->adjustQuantityTo(6);

    expect($item->refresh()->only('quantity', 'available'))->toBe(['quantity' => 6, 'available' => 3]);
});

it('refuses to drop below what is already held or sold', function () {
    $item = DropItem::factory()->create(['quantity' => 5, 'available' => 3]);

    expect(fn () => $item->adjustQuantityTo(1))->toThrow(QuantityBelowCommitted::class, '2 units are already held or sold');

    expect($item->refresh()->only('quantity', 'available'))->toBe(['quantity' => 5, 'available' => 3]);
});

it('never lets the database go below zero or above the quantity', function (int $available) {
    $item = DropItem::factory()->create(['quantity' => 5, 'available' => 5]);

    DB::table('drop_items')->where('id', $item->id)->update(['available' => $available]);
})->with([-1, 6])->throws(QueryException::class, 'drop_items_stock_bounds');
