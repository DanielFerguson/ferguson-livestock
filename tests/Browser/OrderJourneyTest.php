<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

/*
|--------------------------------------------------------------------------
| The customer's way through an order, in a real browser
|--------------------------------------------------------------------------
|
| Stripe's own payment page can't be reached from a test browser, so the fake
| gateway sends the customer straight back to the shop, as Stripe does after a
| payment or a press of its back link. Everything on the shop's side is real:
| the form, the Livewire request, the stock hold and the return pages.
|
*/

it('takes a pickup order from choosing a box to a confirmed order', function (): void {
    FakePaymentGateway::swap()->paysOnReturn();
    $shop = StockedDrop::create(boxes: 5);

    visit(route('order'))
        ->assertSee('5kg Beef Box')
        ->radio('box', (string) $shop->box->id)
        ->radio('deliveryMethod', 'pickup')
        ->press('Continue to payment')
        ->assertSee('Thank you for supporting our small family farm!')
        ->assertPathIs('/order-confirmed')
        ->assertSee('Order confirmed, Jane!')
        ->assertSee('Your beef box is being prepared')
        ->assertNoJavaScriptErrors();

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->total)->toBe(16000)
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('takes a delivery order with extras and a delivery day', function (): void {
    FakePaymentGateway::swap()->paysOnReturn();
    $shop = StockedDrop::create(boxes: 5, mince: 10);

    $page = visit(route('order'))
        ->radio('box', (string) $shop->box->id)
        ->radio('deliveryMethod', 'delivery')
        ->radio('deliveryDay', $shop->drop->delivery_days[0])
        ->click('[aria-label="One more 500g Beef Mince"]')
        ->click('[aria-label="One more 500g Beef Mince"]')
        ->press('Continue to payment')
        ->assertSee('Thank you for supporting our small family farm!');

    $page->assertPathIs('/order-confirmed')->assertNoJavaScriptErrors();

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->total)->toBe(16000 + 2 * 1200 + 1500)
        ->and($shop->mince->refresh()->available)->toBe(8);
});

it('puts the stock back and says so when the customer presses back on the payment page', function (): void {
    FakePaymentGateway::swap()->cancelsOnReturn();
    $shop = StockedDrop::create(boxes: 5);

    visit(route('order'))
        ->radio('box', (string) $shop->box->id)
        ->radio('deliveryMethod', 'pickup')
        ->press('Continue to payment')
        ->assertSee('Your order was cancelled and nothing was charged.')
        ->assertPathIs('/order')
        ->assertNoJavaScriptErrors();

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('shows a sold-out box as sold out and won’t let it be chosen', function (): void {
    $shop = StockedDrop::create(boxes: 0);

    visit(route('order'))
        ->assertSee('Sold out')
        ->assertDisabled('input[name="box"][value="'.$shop->box->id.'"]')
        ->assertNoJavaScriptErrors();
});

it('shows the same sold-out box on the beef boxes page', function (): void {
    StockedDrop::create(boxes: 0);

    visit(route('home'))->assertNoJavaScriptErrors();
    visit('/beef-boxes')->assertSee('Sold out')->assertNoJavaScriptErrors();
});

it('sends someone who has nothing to confirm back to the order page', function (): void {
    visit('/order-confirmed')->assertPathIs('/order')->assertNoJavaScriptErrors();
    visit('/checkout/cancel')->assertPathIs('/order')->assertNoJavaScriptErrors();
});
