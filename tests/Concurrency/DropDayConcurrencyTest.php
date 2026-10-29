<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Stock\StockLedger;
use Illuminate\Support\Facades\Concurrency;
use Tests\Fixtures\ConcurrentCart;
use Tests\Fixtures\ConcurrentSettle;
use Tests\Fixtures\StockedDrop;
use Tests\Fixtures\StockInvariant;

/*
|--------------------------------------------------------------------------
| Drop day: many buyers at the same moment, in real processes on real Postgres
|--------------------------------------------------------------------------
|
| Each task runs in its own PHP process with its own database connection and waits for the same instant, so
| they really do collide. Whatever order they land in, every unit must end up either on sale or in an order
| that still holds it (StockInvariant), and the totals must be exactly what the stock allows.
|
*/

/**
 * A bulk pack of the shop's 500g mince: $units of it each, with no stock of its own.
 */
function packOfMince(StockedDrop $shop, int $units): DropItem
{
    $pack = Product::factory()->bundleOf($shop->mince->product, $units)->create(['name' => "{$units} x 500g Mince Pack"]);

    return DropItem::factory()->for($shop->drop)->for($pack)->withoutStock()->create(['price' => 1000 * $units, 'stripe_price_id' => "price_pack_{$units}"]);
}

/**
 * @param  list<Closure(): string>  $tasks
 * @return array<string, int>
 */
function runTogether(array $tasks): array
{
    $outcomes = [];

    foreach (Concurrency::createProcessDriver()->run($tasks) as $outcome) {
        if (is_string($outcome)) {
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }
    }

    ksort($outcomes);

    return $outcomes;
}

it('sells the last 10kg box to exactly one of twenty buyers when the 5kg stock covers one', function (): void {
    $shop = StockedDrop::create(boxes: 3);
    $startAt = microtime(true) + 4;

    $outcomes = runTogether(array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->largeBox->id => 1], $startAt, "buyer-{$buyer}"), range(1, 20)));

    expect($outcomes)->toBe(['held' => 1, 'sold out' => 19])
        ->and(StockInvariant::check($shop->drop)[$shop->box->product->slug])->toBe(['quantity' => 3, 'available' => 1, 'held' => 2]);
});

it('shares one pool of 5kg stock between 5kg and 10kg buyers without selling a unit twice', function (): void {
    $shop = StockedDrop::create(boxes: 6);
    $startAt = microtime(true) + 4;
    $tasks = [
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->box->id => 1], $startAt, "small-{$buyer}"), range(1, 6)),
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->largeBox->id => 1], $startAt, "large-{$buyer}"), range(1, 8)),
    ];

    $outcomes = runTogether($tasks);

    $small = OrderItem::query()->where('drop_item_id', $shop->box->id)->count();
    $large = OrderItem::query()->where('drop_item_id', $shop->largeBox->id)->count();
    $report = StockInvariant::check($shop->drop);

    expect($outcomes)->not->toHaveKey('busy')
        ->and($small + 2 * $large)->toBe(6 - $report[$shop->box->product->slug]['available'])
        ->and($report[$shop->box->product->slug]['available'])->toBeLessThanOrEqual(1);
});

it('never oversells mince when packs and singles are bought together', function (): void {
    $shop = StockedDrop::create(mince: 20);
    $pack = packOfMince($shop, 10);
    $startAt = microtime(true) + 5;
    $tasks = [
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$pack->id => 1], $startAt, "pack-{$buyer}"), range(1, 10)),
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->mince->id => 3], $startAt, "singles-{$buyer}"), range(1, 15)),
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$pack->id => 1, $shop->mince->id => 2], $startAt, "both-{$buyer}"), range(1, 15)),
    ];

    $outcomes = runTogether($tasks);
    $mince = StockInvariant::check($shop->drop)[$shop->mince->product->slug];

    expect($outcomes)->not->toHaveKey('busy')
        ->and($mince['held'] + $mince['available'])->toBe(20)
        // Singles buyers were turned away, so what's left can't be enough for another of them.
        ->and($mince['available'])->toBeLessThan(3);
});

it('takes carts that list the same items in opposite orders without a deadlock', function (): void {
    $shop = StockedDrop::create(boxes: 8, mince: 12);
    $startAt = microtime(true) + 5;
    $tasks = [
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->box->id => 1, $shop->mince->id => 2], $startAt, "forward-{$buyer}"), range(1, 20)),
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->mince->id => 2, $shop->box->id => 1], $startAt, "backward-{$buyer}"), range(1, 20)),
    ];

    $outcomes = runTogether($tasks);
    $report = StockInvariant::check($shop->drop);

    // The mince covers six carts and the boxes eight, so six carts get through.
    expect($outcomes)->toBe(['held' => 6, 'sold out' => 34])
        ->and($report[$shop->mince->product->slug]['available'])->toBe(0)
        ->and($report[$shop->box->product->slug]['available'])->toBe(2);
});

it('counts every unit once when stock is put back while other buyers are taking it', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $ledger = app(StockLedger::class);
    $holding = array_map(
        fn (int $buyer): Order => $ledger->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, "holder-{$buyer}", now()->addMinutes(15)),
        range(1, 5),
    );
    expect($shop->box->refresh()->available)->toBe(0);
    $startAt = microtime(true) + 5;
    $tasks = [
        // Each held order is expired twice at once, as a webhook and the settle job can.
        ...array_map(fn (Order $order): Closure => ConcurrentSettle::expires($order->id, $startAt), $holding),
        ...array_map(fn (Order $order): Closure => ConcurrentSettle::expires($order->id, $startAt), $holding),
        ...array_map(fn (int $buyer): Closure => ConcurrentCart::holds($shop->drop->id, [$shop->box->id => 1], $startAt, "buyer-{$buyer}"), range(1, 15)),
    ];

    $outcomes = runTogether($tasks);
    $report = StockInvariant::check($shop->drop);

    expect($outcomes['expired'])->toBe(5)
        ->and(Order::query()->where('status', OrderStatus::Expired)->count())->toBe(5)
        // Only the stock that came back could be bought, and each unit once.
        ->and($report[$shop->box->product->slug]['held'])->toBeLessThanOrEqual(5)
        ->and(Order::query()->where('status', OrderStatus::Pending)->count())->toBe($report[$shop->box->product->slug]['held']);
});

it('ends every order paid, with its stock held once, when expiring races paying', function (): void {
    $shop = StockedDrop::create(boxes: 6);
    $ledger = app(StockLedger::class);
    $orders = array_map(function (int $buyer) use ($ledger, $shop): Order {
        $order = $ledger->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, "payer-{$buyer}", now()->addMinutes(15));
        $order->update(['stripe_checkout_session_id' => "cs_test_race_{$buyer}"]);

        return $order;
    }, range(1, 6));
    $startAt = microtime(true) + 5;
    $tasks = [
        ...array_map(fn (Order $order): Closure => ConcurrentSettle::expires($order->id, $startAt), $orders),
        ...array_map(fn (Order $order): Closure => ConcurrentSettle::pays($order->id, $startAt), $orders),
    ];

    $outcomes = runTogether($tasks);

    expect($outcomes['paid'])->toBe(6)
        ->and(Order::query()->where('status', OrderStatus::Paid)->count())->toBe(6)
        ->and(StockInvariant::check($shop->drop)[$shop->box->product->slug])->toBe(['quantity' => 6, 'available' => 0, 'held' => 6]);
});

it('confirms an order once however many copies of its payment arrive together', function (): void {
    $shop = StockedDrop::create(boxes: 4);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'payer', now()->addMinutes(15));
    $order->update(['stripe_checkout_session_id' => 'cs_test_replay']);
    $startAt = microtime(true) + 4;

    $outcomes = runTogether(array_map(fn (): Closure => ConcurrentSettle::pays($order->id, $startAt), range(1, 8)));

    expect($outcomes)->toBe(['paid' => 1, 'unchanged' => 7])
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($shop->box->refresh()->available)->toBe(3);
});

it('holds exactly the stock there is when forty people go through the whole of checkout at once', function (): void {
    $shop = StockedDrop::create(boxes: 10);
    $startAt = microtime(true) + 5;

    $outcomes = runTogether(array_map(fn (int $buyer): Closure => ConcurrentCart::checksOut($shop->drop->id, [$shop->box->id => 1], $startAt, "visitor-{$buyer}"), range(1, 40)));

    expect($outcomes)->toBe(['held' => 10, 'sold out' => 30])
        ->and(Order::query()->whereNotNull('stripe_checkout_session_id')->count())->toBe(10)
        ->and(StockInvariant::check($shop->drop)[$shop->box->product->slug])->toBe(['quantity' => 10, 'available' => 0, 'held' => 10]);
});
