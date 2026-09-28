<?php

use App\Models\Drop;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StockedDrop;

/**
 * How many messages for a drop state the page shows, or hides. The announcement bar and the hero card each carry one.
 */
function messagesFor(string $page, string $state, bool $hidden = false): int
{
    return (int) preg_match_all('/<p data-drop-show="'.$state.'"'.($hidden ? '\s+hidden' : '').'\s*>/', $page);
}

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

it('announces the date of the next drop before its stock is known', function () {
    $this->travelTo('2026-10-01 12:00');
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $page = $this->get(route('home'))
        ->assertSee('Next drop: Saturday 14 November')
        ->assertSee('The next drop is Saturday 14 November. Stock and prices are still to come')
        ->assertSee('Join the wait list')
        ->getContent();

    expect(messagesFor($page, 'announced'))->toBe(2)
        ->and(messagesFor($page, 'closed none', hidden: true))->toBe(2);
});

it('announces the next drop’s date after the last drop has closed', function () {
    $this->travelTo('2026-10-01 12:00');
    Drop::factory()->closed()->create();
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $page = $this->get(route('home'))->assertSee('Next drop: Saturday 14 November')->getContent();

    expect(messagesFor($page, 'announced'))->toBe(2)
        ->and(messagesFor($page, 'closed none', hidden: true))->toBe(2);
});

it('shows a published drop’s opening time instead of an announced date', function () {
    $this->travelTo('2026-10-01 12:00');
    Drop::factory()->create(['opens_at' => '2026-10-09 22:00:00']);
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $page = $this->get(route('home'))->assertSee('Next drop opens Saturday 10 October at 9:00am')->getContent();

    expect(messagesFor($page, 'scheduled'))->toBe(2)
        ->and(messagesFor($page, 'announced', hidden: true))->toBe(2);
});

it('adds the announced date to the sold-out message', function () {
    $this->travelTo('2026-10-01 12:00');
    StockedDrop::create(boxes: 0, mince: 0);
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $this->get(route('home'))
        ->assertSee('Boxes have sold out')
        ->assertSee('Next drop Saturday 14 November')
        ->assertSee('The next drop is Saturday 14 November.');
});

it('shows each box’s stock on the beef boxes page', function () {
    $shop = StockedDrop::create(boxes: 3);

    $this->get(route('beef-boxes'))
        ->assertSee('data-drop-stock="'.$shop->box->product->slug.'"', escape: false)
        ->assertSee('3 left');
});
