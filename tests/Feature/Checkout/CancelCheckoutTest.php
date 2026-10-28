<?php

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

/**
 * A customer at Stripe's payment page for one 5kg box.
 */
function atPaymentPage(StockedDrop $shop): Order
{
    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));

    return Order::sole();
}

it('puts the stock back and returns to the order page when the customer cancels', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $order = atPaymentPage($shop);

    $this->get(route('checkout.cancel', ['session_id' => $order->stripe_checkout_session_id]))
        ->assertRedirect(route('order'))
        ->assertSessionHas('checkout.notice', 'Your order was cancelled and nothing was charged.');

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5)
        ->and($stripe->session('cs_test_1')->isExpired())->toBeTrue();
});

it('shows the confirmation instead when the customer had already paid', function () {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $order = atPaymentPage(StockedDrop::create());
    $stripe->completeSession('cs_test_1');

    $this->get(route('checkout.cancel', ['session_id' => 'cs_test_1']))
        ->assertRedirect(route('order-confirmed', ['session_id' => 'cs_test_1']));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('ignores links that aren’t for one of its checkouts', function (?string $sessionId) {
    FakePaymentGateway::swap();
    $order = atPaymentPage(StockedDrop::create());

    $this->get(route('checkout.cancel', $sessionId === null ? [] : ['session_id' => $sessionId]))->assertRedirect(route('order'));

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
})->with(['cs_test_someone_else', 'not-a-session', null]);

it('answers a cancel link for a settled order without asking Stripe again', function (Closure $settle, string $redirect, ?string $notice) {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $order = atPaymentPage(StockedDrop::create());
    $settle($stripe);
    $this->get(route('checkout.cancel', ['session_id' => 'cs_test_1']));
    $stripe->goOffline();

    $response = $this->get(route('checkout.cancel', ['session_id' => 'cs_test_1']))->assertRedirect(route($redirect, $redirect === 'order' ? [] : ['session_id' => 'cs_test_1']));

    $notice === null ? $response->assertSessionMissing('checkout.notice') : $response->assertSessionHas('checkout.notice', $notice);
})->with([
    'cancelled earlier' => [fn (FakePaymentGateway $stripe) => null, 'order', 'Your order was cancelled and nothing was charged.'],
    'paid' => [fn (FakePaymentGateway $stripe) => $stripe->completeSession('cs_test_1'), 'order-confirmed', null],
]);

it('slows down repeated visits to the checkout return pages', function (string $route) {
    foreach (range(1, 20) as $visit) {
        $this->get(route($route, ['session_id' => 'cs_test_unknown']))->assertRedirect(route('order'));
    }

    $this->get(route($route, ['session_id' => 'cs_test_unknown']))->assertTooManyRequests();
})->with(['checkout.cancel', 'order-confirmed']);

it('ignores stray cancel links without calling Stripe', function () {
    config(['services.stripe.secret' => null]);

    $this->get(route('checkout.cancel', ['session_id' => 'cs_test_unknown']))->assertRedirect(route('order'));
});

it('leaves the order to the sweep when Stripe can’t be reached', function () {
    $stripe = FakePaymentGateway::swap();
    $order = atPaymentPage(StockedDrop::create());
    $stripe->goOffline();

    $this->get(route('checkout.cancel', ['session_id' => 'cs_test_1']))->assertRedirect(route('order'));

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});
