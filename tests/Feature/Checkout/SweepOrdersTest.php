<?php

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Stock\StockLedger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelBack;

/**
 * An order for one 5kg box whose payment page was opened, then left.
 */
function leftAtPaymentPage(StockedDrop $shop, int $minutesAgo = 40): Order
{
    travel(-$minutesAgo)->minutes();
    app(StartCheckout::class)(new CheckoutRequest($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-'.$minutesAgo, '192.0.2.1'));
    travelBack();

    return Order::latest('id')->firstOrFail();
}

it('puts stock back from payment pages Stripe has closed', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $order = leftAtPaymentPage($shop);
    $stripe->timeOutSession((string) $order->stripe_checkout_session_id);

    expect(Artisan::call('orders:sweep'))->toBe(0);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('closes payment pages that have run past their time', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $order = leftAtPaymentPage($shop);

    Artisan::call('orders:sweep');

    expect($stripe->session((string) $order->stripe_checkout_session_id)->isExpired())->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Expired);
});

it('catches payments whose webhook never arrived', function () {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $order = leftAtPaymentPage(StockedDrop::create());
    $stripe->completeSession((string) $order->stripe_checkout_session_id);

    Artisan::call('orders:sweep');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('puts stock back from orders that never reached Stripe', function () {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $this->travel(-3)->minutes();
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));
    $this->travelBack();

    Artisan::call('orders:sweep');

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('leaves customers who are still at the payment page alone', function () {
    $stripe = FakePaymentGateway::swap();
    $order = leftAtPaymentPage(StockedDrop::create(), minutesAgo: 10);

    Artisan::call('orders:sweep');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($stripe->session((string) $order->stripe_checkout_session_id)->status)->toBe('open');
});

it('sweeps every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'orders:sweep'));

    expect($event?->expression)->toBe('* * * * *');
});
