<?php

use App\Enums\OrderStatus;
use App\Livewire\OrderForm;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

it('shows the open drop’s boxes, extras and delivery days with what’s left', function () {
    $shop = StockedDrop::create(boxes: 3, mince: 20);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->assertSeeInOrder(['5kg Beef Box', '$160', '3 left', '10kg Beef Box', '$275', '1 left', '500g Beef Mince', '$12', 'Available'])
        ->assertSee(now()->addDays(3)->format('l j F'));
});

it('sends the customer to Stripe with what they chose', function () {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set("extras.{$shop->mince->id}", 2)
        ->set('deliveryMethod', 'delivery')
        ->set('deliveryDay', $shop->drop->delivery_days[0])
        ->call('checkout')
        ->assertRedirect('https://checkout.stripe.test/cs_test_1');

    expect(Order::sole()->status)->toBe(OrderStatus::Pending)
        ->and(Order::sole()->total)->toBe(16000 + 2 * 1200 + 1500);
});

it('says what to fix when the order can’t be taken', function () {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$shop->mince->id}", 1)
        ->set('deliveryMethod', 'delivery')
        ->call('checkout')
        ->assertSet('problem', 'Choose a delivery day.')
        ->assertNoRedirect();
});

it('cuts an extra down to what’s left, and says so', function () {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(mince: 10);
    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$shop->mince->id}", 3)
        ->set('deliveryMethod', 'pickup');
    DropItem::whereKey($shop->mince->id)->update(['available' => 2]);

    $form->call('checkout')
        ->assertSet("extras.{$shop->mince->id}", 2)
        ->assertSet('notices', ['There are only 2 of the 500g Beef Mince left, so we’ve changed your order to 2.'])
        ->assertNoRedirect();

    expect(Order::count())->toBe(0);
});

it('takes a box that just sold out off the order, and says so', function () {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 1);
    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set('deliveryMethod', 'pickup');
    DropItem::whereKey($shop->box->id)->update(['available' => 0]);

    $form->call('checkout')
        ->assertSet('box', '')
        ->assertSet('notices', ['The 5kg Beef Box just sold out, so we’ve taken it off your order.']);
});

it('shows when the next drop opens, and doesn’t take orders before then', function () {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(drop: ['opens_at' => now()->addDay()->startOfHour()]);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->assertSee('Orders open')
        ->set("extras.{$shop->mince->id}", 1)
        ->set('deliveryMethod', 'pickup')
        ->call('checkout')
        ->assertSet('problem', 'This drop isn’t taking orders right now.');
});

it('puts the order form on the order page while a drop is open or coming up', function () {
    $shop = StockedDrop::create();

    $this->get(route('order'))
        ->assertOk()
        ->assertSee('1. Choose a box')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
});

it('shows the wait list on the order page between drops', function () {
    Drop::factory()->closed()->create();

    $this->get(route('order'))
        ->assertOk()
        ->assertDontSee('1. Choose a box')
        ->assertSee('Join the wait list');
});

it('says the order was cancelled when the customer comes back from Stripe', function () {
    $shop = StockedDrop::create();

    $this->withSession(['checkout.notice' => 'Your order was cancelled and nothing was charged.'])
        ->get(route('order'))
        ->assertSee('Your order was cancelled and nothing was charged.');
});
