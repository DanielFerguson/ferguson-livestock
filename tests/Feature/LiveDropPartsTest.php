<?php

use App\Models\Drop;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StockedDrop;

it('announces an open drop on the homepage', function () {
    StockedDrop::create();

    $this->get(route('home'))
        ->assertSee('Orders are open')
        ->assertSee('data-drop-show="live"', escape: false);
});

it('announces when the next drop opens', function () {
    Drop::factory()->create(['opens_at' => CarbonImmutable::parse('2026-10-10 09:00', 'Australia/Melbourne')->utc()]);

    $this->get(route('home'))->assertSee('Next drop opens Saturday 10 October at 9:00am');
});

it('points people to the wait list between drops', function () {
    $this->get(route('home'))->assertSee('Next drop coming soon');
});

it('shows each box’s stock on the beef boxes page', function () {
    $shop = StockedDrop::create(boxes: 3);

    $this->get(route('beef-boxes'))
        ->assertSee('data-drop-stock="'.$shop->box->product->slug.'"', escape: false)
        ->assertSee('3 left');
});
