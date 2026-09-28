<?php

use App\Models\Drop;
use App\Models\DropItem;
use App\Stock\DropSnapshot;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StockedDrop;

beforeEach(fn () => config(['shop.drop_poll_ms' => 200]));

it('updates what’s left while the page is open, and trims a choice to match', function () {
    $shop = StockedDrop::create(mince: 10);
    $more = '[aria-label="One more 500g Beef Mince"]';

    $page = visit(route('order'))
        ->assertSee('10 left')
        ->click($more)
        ->click($more)
        ->click($more);

    DropItem::whereKey($shop->mince->id)->update(['available' => 2]);
    DropSnapshot::forget();

    $page->assertSee('2 left')
        ->assertSee('There are only 2 of the 500g Beef Mince left, so we’ve changed your order to 2.')
        ->assertNoJavaScriptErrors();
});

it('opens the drop on time, without a reload', function () {
    StockedDrop::create(drop: ['opens_at' => now()->addSeconds(3)]);

    visit(route('order'))
        ->assertSee('Orders open')
        ->assertSee('Orders are open. Stock updates live as people order.')
        ->assertSee('Continue to payment')
        ->assertNoJavaScriptErrors();
});

it('announces the next drop’s date, then goes back to coming soon once it has passed', function () {
    // Three seconds before 9am on Saturday 14 November in Melbourne.
    $this->travelTo(CarbonImmutable::parse('2026-11-13 21:59:57', 'UTC'));
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    visit(route('home'))
        ->assertSee('Next drop: Saturday 14 November')
        ->assertSee('Next drop coming soon')
        ->assertDontSee('Next drop: Saturday 14 November')
        ->assertNoJavaScriptErrors();
});

it('drops the announced date from the sold-out message once it has passed', function () {
    StockedDrop::create(boxes: 0, mince: 0);
    $announced = Drop::factory()->announced()->create(['opens_at' => now()->addSeconds(3)]);
    $message = "Next drop {$announced->announcedLabel()}";

    visit(route('home'))
        ->assertSee('Boxes have sold out')
        ->assertSee($message)
        ->assertDontSee($message)
        ->assertSee('Boxes have sold out')
        ->assertNoJavaScriptErrors();
});

it('tells people the drop has closed, and points to the wait list, when a date is announced', function () {
    StockedDrop::create(drop: ['closes_at' => now()->addSeconds(4)]);
    Drop::factory()->announced()->create(['opens_at' => now()->addWeek()]);

    visit(route('order'))
        ->assertSee('Orders are open. Stock updates live as people order.')
        ->assertSee('This drop has closed. Join the wait list and we’ll text you when the next one opens.')
        ->assertNoJavaScriptErrors();
});
