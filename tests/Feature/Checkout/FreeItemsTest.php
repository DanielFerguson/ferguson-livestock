<?php

declare(strict_types=1);

use App\Actions\Orders\GiveFreeItems;
use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\CannotChangeOrder;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;
use Tests\Fixtures\StockInvariant;

/**
 * Sam's pickup of one 5kg box, its stock held as checkout holds it.
 */
function samsPickup(StockedDrop $shop, OrderStatus $status = OrderStatus::Paid): Order
{
    return app(StockLedger::class)->setAside($shop->drop, [$shop->box->id => 1], [
        'status' => $status,
        'delivery_method' => DeliveryMethod::Pickup,
        'customer_name' => 'Sam Buyer',
    ])->refresh();
}

it('gives free items from the drop’s stock without changing what the order cost', function (OrderStatus $status): void {
    $shop = StockedDrop::create(boxes: 5, mince: 10);

    $order = app(GiveFreeItems::class)(samsPickup($shop, $status), [$shop->mince->id => 2]);

    expect($order->total)->toBe(16000);
    expect($order->items->firstWhere('drop_item_id', $shop->mince->id))
        ->free->toBeTrue()
        ->unit_price->toBe(0)
        ->quantity->toBe(2);
    expect($shop->mince->refresh()->available)->toBe(8);
    StockInvariant::check($shop->drop);
})->with([OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed]);

it('takes a free bundle from the stock it’s packed from', function (): void {
    $shop = StockedDrop::create(boxes: 5);

    app(GiveFreeItems::class)(samsPickup($shop), [$shop->largeBox->id => 1]);

    expect($shop->box->refresh()->available)->toBe(2);
    StockInvariant::check($shop->drop);
});

it('gives free items after the drop has closed, since pickups come after', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = samsPickup($shop);
    $shop->drop->update(['opens_at' => now()->subWeek(), 'closes_at' => now()->subDay()]);

    app(GiveFreeItems::class)($order, [$shop->mince->id => 1]);

    expect($shop->mince->refresh()->available)->toBe(9);
});

it('says what’s left when there isn’t enough, giving nothing', function (): void {
    $shop = StockedDrop::create(mince: 1);
    $order = samsPickup($shop);

    expect(fn (): Order => app(GiveFreeItems::class)($order, [$shop->mince->id => 2]))
        ->toThrow(CannotChangeOrder::class, 'Only 1 × 500g Beef Mince left. If you’ve got more on hand, raise its stock on the drop first.');

    expect($order->items()->count())->toBe(1)
        ->and($shop->mince->refresh()->available)->toBe(1);
});

it('refuses an order whose stock isn’t held for good', function (Closure $checkoutOutcome): void {
    $shop = StockedDrop::create(mince: 10);
    $order = samsPickup($shop, OrderStatus::Pending);
    $checkoutOutcome($order);

    expect(fn (): Order => app(GiveFreeItems::class)($order, [$shop->mince->id => 1]))
        ->toThrow(CannotChangeOrder::class, 'only paid, fulfilled and cash owed orders can take free items');

    expect($shop->mince->refresh()->available)->toBe(10);
})->with([
    'at checkout' => [fn (Order $order): null => null],
    'abandoned' => [fn (Order $order): bool => app(OrderPayments::class)->markExpired($order)],
]);

it('refuses something the order’s drop doesn’t sell', function (Closure $give, string $message): void {
    $shop = StockedDrop::create();
    $order = samsPickup($shop);

    expect(fn () => $give($shop, $order))->toThrow(CannotChangeOrder::class, $message);

    expect($order->items()->count())->toBe(1);
})->with([
    'nothing' => [fn (StockedDrop $shop, Order $order): Order => app(GiveFreeItems::class)($order, [$shop->mince->id => 0]), 'Choose at least one item to give.'],
    'the delivery fee' => [fn (StockedDrop $shop, Order $order): Order => app(GiveFreeItems::class)($order, [$shop->delivery->id => 1]), 'isn’t sold in'],
    'another drop’s item' => [fn (StockedDrop $shop, Order $order): Order => app(GiveFreeItems::class)($order, [DropItem::factory()->for(Drop::factory()->draft())->create()->id => 1]), 'isn’t sold in'],
]);
