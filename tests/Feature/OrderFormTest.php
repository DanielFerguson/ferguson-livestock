<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\OrderForm;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\Product;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Http;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

it('shows the open drop’s boxes, extras and delivery days with what’s left', function (): void {
    $shop = StockedDrop::create(boxes: 3, mince: 20);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->assertSeeInOrder(['5kg Beef Box', '$160', '3 left', '10kg Beef Box', '$275', '1 left', '500g Beef Mince', '$12', 'Available'])
        ->assertSee(now()->addDays(3)->format('l j F'));
});

it('leaves an extra off the list only once none are left', function (int $mince, int $hidden): void {
    $shop = StockedDrop::create(mince: $mince);

    $form = livewire(OrderForm::class, ['drop' => $shop->drop])->html();

    expect(preg_match_all('/<li[^>]*\shidden\s/', $form))->toBe($hidden);
})->with([
    'none left' => [0, 1],
    'some left' => [10, 0],
]);

it('leaves sold-out boxes off the list, and says so once they’re all gone', function (int $boxes, int $hiddenBoxes, bool $soldOutLine): void {
    $shop = StockedDrop::create(boxes: $boxes);

    $form = livewire(OrderForm::class, ['drop' => $shop->drop])->html();

    expect(preg_match_all('/<label[^>]*\shidden\s[^>]*>\s*<input type="radio" name="box" value="\d+"/', $form))->toBe($hiddenBoxes)
        ->and(preg_match('/<p[^>]*\shidden\s[^>]*>The boxes have sold out/', $form) === 0)->toBe($soldOutLine);
})->with([
    'plenty left' => [5, 0, false],
    'one 5kg box left, too few for a 10kg box' => [1, 1, false],
    'none left' => [0, 2, true],
]);

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

it('says when deliveries and farm pickups happen, and lets the customer leave the night open', function (): void {
    $shop = StockedDrop::create(drop: ['delivery_days' => ['2026-11-14', '2026-11-15']]);

    livewire(OrderForm::class, ['drop' => $shop->drop])->assertSeeInOrder([
        'Saturday or Sunday night, around the Ballarat region.',
        'Saturday or Sunday, 10am to 4pm, from our farm in Snake Valley.',
        'Which night suits you?', 'Saturday 14 November', 'Sunday 15 November', 'Either night',
    ]);
});

it('takes a delivery for either night, leaving the night open', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(drop: ['delivery_days' => [now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString()]]);

    livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$shop->mince->id}", 1)
        ->set('deliveryMethod', 'delivery')
        ->set('deliveryDay', OrderForm::EITHER_NIGHT)
        ->call('checkout')
        ->assertRedirect('https://checkout.stripe.test/cs_test_1');

    expect(Order::query()->sole())
        ->delivery_day->toBeNull()
        ->total->toBe(1200 + 1500);
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
        ->assertSet('problem', 'Choose which night suits you for delivery.')
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

it('trims a mixed order of a mince pack and 500g mince to what is left in one go, so trying again works', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(mince: 12);
    $pack = DropItem::factory()->for($shop->drop)->for(Product::factory()->bundleOf($shop->mince->product, 10)->create(['name' => '5kg Mince Pack']))
        ->withoutStock()->create(['price' => 11000, 'stripe_price_id' => 'price_mince_5kg']);
    $form = livewire(OrderForm::class, ['drop' => $shop->drop])
        ->set("extras.{$pack->id}", 1)
        ->set("extras.{$shop->mince->id}", 3)
        ->set('deliveryMethod', 'pickup');
    DropItem::query()->whereKey($shop->mince->id)->update(['available' => 11]);

    $trimmed = $form->call('checkout');
    $trimmed->assertSet("extras.{$pack->id}", 1)
        ->assertSet("extras.{$shop->mince->id}", 1)
        ->assertSet('notices', ['There are only 1 of the 500g Beef Mince left, so we’ve changed your order to 1.'])
        ->assertNoRedirect();
    $trimmed->assertDispatched('drop-status-refresh');

    $form->call('checkout')->assertRedirect('https://checkout.stripe.test/cs_test_1');

    expect($shop->mince->refresh()->available)->toBe(0);
});

it('keeps Meta’s cookies and the ad campaign from the customer’s browser on their order', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    Testable::create(OrderForm::class, ['drop' => $shop->drop], cookies: ['_fbp' => 'fb.1.1700000000000.111', '_fbc' => 'fb.1.1700000000000.click-abc', 'fl_campaign' => 'source=meta&campaign=Drop+week'])
        ->set('box', (string) $shop->box->id)
        ->set('deliveryMethod', 'pickup')
        ->call('checkout')
        ->assertRedirect('https://checkout.stripe.test/cs_test_1');

    $order = Order::query()->sole();
    expect($order->meta_visitor)->toMatchArray(['fbp' => 'fb.1.1700000000000.111', 'fbc' => 'fb.1.1700000000000.click-abc'])
        ->and($order->only('utm_source', 'utm_medium', 'utm_campaign'))->toBe(['utm_source' => 'meta', 'utm_medium' => null, 'utm_campaign' => 'Drop week']);
});
