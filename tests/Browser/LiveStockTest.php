<?php

use App\Models\DropItem;
use App\Stock\DropSnapshot;
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
