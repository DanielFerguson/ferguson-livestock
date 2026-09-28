<?php

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InsufficientStock;
use App\Models\DropItem;
use App\Models\Order;
use App\Stock\StockLedger;
use Illuminate\Database\QueryException;
use Tests\Fixtures\StockedDrop;

/**
 * @param  array<int, int>  $quantities
 */
function reserve(StockedDrop $shop, array $quantities, DeliveryMethod $method = DeliveryMethod::Pickup): Order
{
    return app(StockLedger::class)->reserve($shop->drop, $quantities, $method, null, 'fingerprint', now()->addMinutes(31));
}

/**
 * What the ledger says is left when it refuses an order.
 *
 * @return array<int, int>|null
 */
function shortfall(Closure $reserve): ?array
{
    try {
        $reserve();
    } catch (InsufficientStock $exception) {
        return $exception->available;
    }

    return null;
}

it('holds the stock for a pending order at the drop’s prices', function () {
    $shop = StockedDrop::create();

    $order = reserve($shop, [$shop->box->id => 1, $shop->mince->id => 3, $shop->delivery->id => 1], DeliveryMethod::Delivery);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->total)->toBe(16000 + 3 * 1200 + 1500)
        ->and($order->items->pluck('unit_price', 'drop_item_id')->all())->toBe([$shop->box->id => 16000, $shop->mince->id => 1200, $shop->delivery->id => 1500])
        ->and($shop->box->refresh()->available)->toBe(4)
        ->and($shop->mince->refresh()->available)->toBe(7);
});

it('takes two units of 5kg-box stock for each 10kg box', function () {
    $shop = StockedDrop::create(boxes: 5);

    reserve($shop, [$shop->largeBox->id => 1]);

    expect($shop->box->refresh()->available)->toBe(3);
});

it('holds everything or nothing, and says what’s left', function () {
    $shop = StockedDrop::create(boxes: 5, mince: 2);

    expect(shortfall(fn () => reserve($shop, [$shop->box->id => 1, $shop->mince->id => 3])))->toBe([$shop->mince->id => 2]);

    expect($shop->box->refresh()->available)->toBe(5)
        ->and(Order::count())->toBe(0);
});

it('works out what’s left of a 10kg box from the 5kg-box stock', function () {
    $shop = StockedDrop::create(boxes: 1);

    expect(shortfall(fn () => reserve($shop, [$shop->largeBox->id => 1])))->toBe([$shop->largeBox->id => 0]);
});

it('only takes orders while the drop is open', function (string $state) {
    $shop = StockedDrop::create();
    $shop->drop->update(match ($state) {
        'scheduled' => ['opens_at' => now()->addHour()],
        'closed' => ['closed_at' => now()->subMinute()],
        default => ['published_at' => null],
    });

    expect(fn () => reserve($shop, [$shop->mince->id => 1]))->toThrow(DropNotOpen::class);

    expect($shop->mince->refresh()->available)->toBe(10);
})->with(['scheduled', 'closed', 'draft']);

it('gives stock back once, however many times it’s released', function () {
    $shop = StockedDrop::create(boxes: 5);
    $order = reserve($shop, [$shop->largeBox->id => 1, $shop->mince->id => 2]);
    $ledger = app(StockLedger::class);

    expect($ledger->release($order))->toBeTrue()
        ->and($ledger->release($order))->toBeFalse();

    expect($shop->box->refresh()->available)->toBe(5)
        ->and($shop->mince->refresh()->available)->toBe(10)
        ->and($order->refresh()->released_at)->not->toBeNull();
});

it('never lets stock go below zero or above the quantity, whatever the code does', function (int $available) {
    $shop = StockedDrop::create(boxes: 5);

    expect(fn () => DropItem::whereKey($shop->box->id)->update(['available' => $available]))
        ->toThrow(QueryException::class);
})->with([-1, 6]);
