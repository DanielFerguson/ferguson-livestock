<?php

use App\Enums\DeliveryMethod;
use App\Models\Drop;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;

it('describes the open drop and what’s left of each item', function () {
    $this->freezeSecond();
    $shop = StockedDrop::create(boxes: 5);

    $this->getJson(route('drop-status'))
        ->assertOk()
        ->assertExactJson([
            'drop' => [
                'id' => $shop->drop->id,
                'name' => $shop->drop->name,
                'state' => 'live',
                'opens_at' => $shop->drop->opens_at->toIso8601ZuluString(),
                'closes_at' => null,
            ],
            'server_time' => now()->toIso8601ZuluString('millisecond'),
            'items' => [
                $shop->box->product->slug => ['available' => 5, 'max' => 1],
                $shop->largeBox->product->slug => ['available' => 2, 'max' => 1],
                $shop->mince->product->slug => ['available' => 10, 'max' => 10],
            ],
            'held' => 0,
        ]);
});

it('counts the checkouts holding stock, and shows new stock straight away', function () {
    $shop = StockedDrop::create(boxes: 5);
    $this->getJson(route('drop-status'))->assertJsonPath('held', 0);

    app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));

    $this->getJson(route('drop-status'))
        ->assertJsonPath('held', 1)
        ->assertJsonPath("items.{$shop->box->product->slug}.available", 4);
});

it('shows stock the farm changes straight away', function () {
    $shop = StockedDrop::create(mince: 10);
    $this->getJson(route('drop-status'));

    $shop->mince->adjustQuantityTo(4);

    $this->getJson(route('drop-status'))->assertJsonPath("items.{$shop->mince->product->slug}.available", 4);
});

it('says when the next drop opens', function () {
    $drop = Drop::factory()->create(['opens_at' => now()->addDay()->startOfHour()]);

    $this->getJson(route('drop-status'))
        ->assertJsonPath('drop.state', 'scheduled')
        ->assertJsonPath('drop.opens_at', $drop->opens_at->toIso8601ZuluString());
});

it('has nothing to report before the first drop', function () {
    $this->getJson(route('drop-status'))
        ->assertOk()
        ->assertJson(['drop' => null, 'items' => [], 'held' => 0]);
});

it('can be cached for a second by the edge, and sets no cookies', function () {
    StockedDrop::create();

    $response = $this->get(route('drop-status'));

    expect($response->headers->get('Cache-Control'))->toContain('max-age=1')->toContain('public')->toContain('s-maxage=1')
        ->and($response->headers->getCookies())->toBe([]);
});
