<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\Product;
use App\Stock\DropSnapshot;
use App\Stock\StockLedger;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

/*
|--------------------------------------------------------------------------
| Bundles in a real browser
|--------------------------------------------------------------------------
|
| The 10kg box is two 5kg boxes and a mince pack is ten 500g packs of mince. These follow a customer through
| ordering them, and through the stock they share running short while the page is open or at the moment of paying.
|
*/

/**
 * A 5kg mince pack on the shop's drop: ten of its 500g mince, with no stock of its own.
 */
function mincePackOnSale(StockedDrop $shop): DropItem
{
    $pack = Product::factory()->bundleOf($shop->mince->product, 10)->create(['name' => '5kg Mince Pack']);

    return DropItem::factory()->for($shop->drop)->for($pack)->withoutStock()->create(['price' => 11000, 'stripe_price_id' => 'price_mince_5kg', 'max_per_order' => 5]);
}

const MORE_PACKS = '[aria-label="One more 5kg Mince Pack"]';
const MORE_MINCE = '[aria-label="One more 500g Beef Mince"]';

it('takes a 10kg box order through to confirmation, using two 5kg boxes of stock', function (): void {
    FakePaymentGateway::swap()->paysOnReturn();
    $shop = StockedDrop::create(boxes: 5);

    visit(route('order'))
        ->assertSee('10kg Beef Box')
        ->radio('box', (string) $shop->largeBox->id)
        ->radio('deliveryMethod', 'pickup')
        ->press('Continue to payment')
        ->assertPathIs('/order-confirmed')
        ->assertSee('Order confirmed, Jane!')
        ->assertNoJavaScriptErrors();

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid)
        ->and(Order::query()->sole()->total)->toBe(27500)
        ->and($shop->box->refresh()->available)->toBe(3);
});

it('takes a 5kg mince pack order through to confirmation, using ten 500g packs of stock', function (): void {
    FakePaymentGateway::swap()->paysOnReturn();
    $shop = StockedDrop::create(mince: 40);
    mincePackOnSale($shop);

    visit(route('order'))
        ->assertSee('5kg Mince Pack')
        ->click(MORE_PACKS)
        ->radio('deliveryMethod', 'pickup')
        ->press('Continue to payment')
        ->assertPathIs('/order-confirmed')
        ->assertNoJavaScriptErrors();

    expect(Order::query()->sole()->total)->toBe(11000)
        ->and($shop->mince->refresh()->available)->toBe(30);
});

it('stops a customer adding more mince than a pack and 500g packs can share', function (): void {
    $shop = StockedDrop::create(mince: 12);
    mincePackOnSale($shop);

    visit(route('order'))
        ->click(MORE_PACKS)
        ->click(MORE_MINCE)
        ->click(MORE_MINCE)
        ->assertDisabled(MORE_MINCE)
        ->assertDisabled(MORE_PACKS)
        ->assertNoJavaScriptErrors();
});

it('trims a pack to what the mince can still cover while the page is open', function (): void {
    config(['shop.drop_poll_ms' => 200]);
    $shop = StockedDrop::create(mince: 40);
    mincePackOnSale($shop);

    $page = visit(route('order'))->click(MORE_PACKS)->click(MORE_PACKS);

    DropItem::query()->whereKey($shop->mince->id)->update(['available' => 15]);
    DropSnapshot::forget();

    $page->assertSee('There are only 1 of the 5kg Mince Pack left, so we’ve changed your order to 1.')
        ->assertNoJavaScriptErrors();
});

it('trims a pack and 500g packs together when checkout finds the mince gone, so trying again works', function (): void {
    config(['shop.drop_poll_ms' => 3_600_000]);
    FakePaymentGateway::swap()->paysOnReturn();
    $shop = StockedDrop::create(mince: 14);
    mincePackOnSale($shop);

    $page = visit(route('order'))
        ->click(MORE_PACKS)
        ->click(MORE_MINCE)
        ->click(MORE_MINCE)
        ->click(MORE_MINCE)
        ->radio('deliveryMethod', 'pickup');

    // Someone else buys some mince after this page was loaded, so the page can't know yet.
    DropItem::query()->whereKey($shop->mince->id)->update(['available' => 11]);

    $page->press('Continue to payment')
        ->assertSee('There are only 1 of the 500g Beef Mince left, so we’ve changed your order to 1.')
        ->assertPathIs('/order')
        ->assertNoJavaScriptErrors();
    expect(Order::query()->count())->toBe(0);

    $page->press('Continue to payment')->assertPathIs('/order-confirmed');

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid)
        ->and($shop->mince->refresh()->available)->toBe(0);
});

it('takes a box off the order, and says so, when someone else buys the last one before this customer pays', function (): void {
    config(['shop.drop_poll_ms' => 3_600_000]);
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 1);

    $page = visit(route('order'))
        ->radio('box', (string) $shop->box->id)
        ->radio('deliveryMethod', 'pickup');

    app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'someone-else', now()->addMinutes(15));

    $page->press('Continue to payment')
        ->assertSee('The 5kg Beef Box just sold out, so we’ve taken it off your order.')
        ->assertPathIs('/order')
        ->assertNoJavaScriptErrors();

    expect(Order::query()->count())->toBe(1);
});

it('lets a customer on a phone order a 10kg box', function (): void {
    FakePaymentGateway::swap()->paysOnReturn();
    $shop = StockedDrop::create(boxes: 5);

    visit(route('order'))->on()->mobile()
        ->radio('box', (string) $shop->largeBox->id)
        ->radio('deliveryMethod', 'pickup')
        ->press('Continue to payment')
        ->assertPathIs('/order-confirmed')
        ->assertNoJavaScriptErrors();

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid);
});

it('fills in what is left on the cached beef boxes page, which prints no count of its own', function (): void {
    StockedDrop::create(boxes: 3);

    visit('/beef-boxes')->assertSee('3 left')->assertNoJavaScriptErrors();
});
