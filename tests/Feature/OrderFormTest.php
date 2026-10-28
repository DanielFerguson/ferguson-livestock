<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\OrderForm;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Http;
use Livewire\Exceptions\MethodNotFoundException;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

it('shows the open drop’s boxes, extras and delivery days with what’s left', function (): void {
    $shop = StockedDrop::create(boxes: 3, mince: 20);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->assertSeeInOrder(['5kg Beef Box', '$160', '3 left', '10kg Beef Box', '$275', '1 left', '500g Beef Mince', '$12', 'Available'])
        ->assertSee(now()->addDays(3)->format('l j F'));
});

it('sends the customer to Stripe with what they chose', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set("extras.{$shop->mince->id}", 2)
        ->set('deliveryMethod', 'delivery')
        ->set('deliveryDay', $shop->drop->delivery_days[0])
        ->call('checkout')
        ->assertRedirect('https://checkout.stripe.test/cs_test_1');

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Pending)
        ->and(Order::query()->sole()->total)->toBe(16000 + 2 * 1200 + 1500);
});

it('won’t start checkout when Cloudflare’s bot check fails', function (): void {
    FakePaymentGateway::swap();
    fakeTurnstile(genuine: false);
    $shop = StockedDrop::create();

    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set('deliveryMethod', 'pickup')
        ->call('checkout', 'bot-token');

    $form->assertSet('problem', Turnstile::FAILED_MESSAGE)->assertNoRedirect();
    $form->assertDispatched('turnstile-reset');

    expect(Order::query()->count())->toBe(0);
});

it('starts checkout when Cloudflare’s bot check passes', function (): void {
    FakePaymentGateway::swap();
    fakeTurnstile(genuine: true);
    $shop = StockedDrop::create();

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set('deliveryMethod', 'pickup')
        ->call('checkout', 'person-token')
        ->assertRedirect('https://checkout.stripe.test/cs_test_1');
});

it('counts a failed bot check as a try, and stops asking Cloudflare once the tries run out', function (): void {
    FakePaymentGateway::swap();
    fakeTurnstile(genuine: false);
    $shop = StockedDrop::create();

    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set('deliveryMethod', 'pickup');

    foreach (range(1, 5) as $try) {
        $form->call('checkout', 'bot-token')->assertSet('problem', Turnstile::FAILED_MESSAGE);
    }

    $form->call('checkout', 'bot-token');

    expect($form->get('problem'))->toStartWith('That’s a lot of tries in a short time.');
    Http::assertSentCount(5);
});

it('refuses an extra sent under another spelling of its ID', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(mince: 30);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$shop->mince->id}", 10)
        ->set("extras.0{$shop->mince->id}", 10)
        ->set('deliveryMethod', 'pickup')
        ->call('checkout')
        ->assertSet('problem', 'Something in your order isn’t part of this drop.')
        ->assertNoRedirect();

    expect(Order::query()->count())->toBe(0);
});

it('only lets the browser call checkout', function (): void {
    $shop = StockedDrop::create();

    livewire(OrderForm::class, ['drop' => $shop->drop])->call('isTakingOrders');
})->throws(MethodNotFoundException::class);

it('says what to fix when the order can’t be taken', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$shop->mince->id}", 1)
        ->set('deliveryMethod', 'delivery')
        ->call('checkout')
        ->assertSet('problem', 'Choose a delivery day.')
        ->assertNoRedirect();
});

it('cuts an extra down to what’s left, and says so', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(mince: 10);
    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$shop->mince->id}", 3)
        ->set('deliveryMethod', 'pickup');
    DropItem::query()->whereKey($shop->mince->id)->update(['available' => 2]);

    $form->call('checkout')
        ->assertSet("extras.{$shop->mince->id}", 2)
        ->assertSet('notices', ['There are only 2 of the 500g Beef Mince left, so we’ve changed your order to 2.'])
        ->assertNoRedirect();

    expect(Order::query()->count())->toBe(0);
});

it('takes a box that just sold out off the order, and says so', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 1);
    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set('box', (string) $shop->box->id)
        ->set('deliveryMethod', 'pickup');
    DropItem::query()->whereKey($shop->box->id)->update(['available' => 0]);

    $form->call('checkout')
        ->assertSet('box', '')
        ->assertSet('notices', ['The 5kg Beef Box just sold out, so we’ve taken it off your order.']);
});

it('shows when the next drop opens, and doesn’t take orders before then', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(drop: ['opens_at' => now()->addDay()->startOfHour()]);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->assertSee('Orders open')
        ->set("extras.{$shop->mince->id}", 1)
        ->set('deliveryMethod', 'pickup')
        ->call('checkout')
        ->assertSet('problem', 'This drop isn’t taking orders right now.');
});

it('puts the order form on the order page while a drop is open or coming up', function (): void {
    $shop = StockedDrop::create();

    $this->get(route('order'))
        ->assertOk()
        ->assertSee('1. Choose a box')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
});

it('shows the wait list on the order page between drops', function (): void {
    Drop::factory()->closed()->create();

    $this->get(route('order'))
        ->assertOk()
        ->assertDontSee('1. Choose a box')
        ->assertSee('Join the wait list');
});

it('shows the announced date on the order page between drops', function (): void {
    $this->travelTo('2026-10-01 12:00');
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $this->get(route('order'))
        ->assertOk()
        ->assertSee('The next drop is Saturday 14 November.')
        ->assertDontSee('1. Choose a box')
        ->assertSee('Join the wait list');
});

it('shows the announced date on the order page after the last drop has closed', function (): void {
    $this->travelTo('2026-10-01 12:00');
    Drop::factory()->closed()->create();
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $this->get(route('order'))
        ->assertSee('The next drop is Saturday 14 November.')
        ->assertDontSee('1. Choose a box');
});

it('puts the order form ahead of an announced date while a drop is open', function (): void {
    $this->travelTo('2026-10-01 12:00');
    StockedDrop::create();
    Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    $this->get(route('order'))
        ->assertSee('1. Choose a box')
        ->assertDontSee('The next drop is Saturday 14 November.');
});

it('says the order was cancelled when the customer comes back from Stripe', function (): void {
    $shop = StockedDrop::create();

    $this->withSession(['checkout.notice' => 'Your order was cancelled and nothing was charged.'])
        ->get(route('order'))
        ->assertSee('Your order was cancelled and nothing was charged.');
});
