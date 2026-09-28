<?php

use App\Models\Order;
use Illuminate\Support\Facades\Concurrency;
use Tests\Fixtures\ConcurrentBuyer;
use Tests\Fixtures\StockedDrop;

it('sells the last five boxes to exactly five of twenty people buying at the same moment', function () {
    $shop = StockedDrop::create(boxes: 5);
    $startAt = microtime(true) + 3;

    $buyers = array_map(fn (int $buyer) => ConcurrentBuyer::task($shop->drop->id, $shop->box->id, $startAt, "buyer-{$buyer}"), range(1, 20));

    $outcomes = collect(Concurrency::createProcessDriver()->run($buyers))->countBy()->sortKeys()->all();

    expect($outcomes)->toBe(['held' => 5, 'sold out' => 15])
        ->and($shop->box->refresh()->available)->toBe(0)
        ->and(Order::count())->toBe(5);
});
