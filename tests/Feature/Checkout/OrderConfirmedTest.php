<?php

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

beforeEach(fn () => Mail::fake());

/**
 * @param  array<int, int>  $quantities
 */
function paidFor(StockedDrop $shop, array $quantities, bool $delivery, FakePaymentGateway $stripe, string $name = 'Sam Buyer', string $paymentStatus = 'paid'): Order
{
    app(StartCheckout::class)(new CheckoutRequest(
        $shop->drop, $quantities, $delivery ? DeliveryMethod::Delivery : DeliveryMethod::Pickup,
        $delivery ? CarbonImmutable::parse($shop->drop->delivery_days[0]) : null, 'visitor-a', '192.0.2.1',
    ));
    $stripe->completeSession('cs_test_1', $paymentStatus, $name);

    return Order::sole();
}

it('confirms a paid delivery order, checking with Stripe if the webhook hasn’t arrived yet', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    $order = paidFor($shop, [$shop->box->id => 1, $shop->mince->id => 2], delivery: true, stripe: $stripe);

    $this->get(route('order-confirmed', ['session_id' => 'cs_test_1']))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false)
        ->assertSeeInOrder(['Order confirmed, Sam!', 'Your beef box is being prepared', $order->reference(), '5kg Beef Box', '500g Beef Mince', 'Delivery', '$199']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('shows an order that’s already paid without calling Stripe', function () {
    config(['services.stripe.secret' => null]);
    $order = Order::factory()->create(['customer_name' => 'Sam Buyer']);
    OrderItem::factory()->for($order)->create();

    $this->get(route('order-confirmed', ['session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee('Order confirmed, Sam!');
});

it('says when a bank payment is still clearing', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    paidFor($shop, [$shop->box->id => 1], delivery: false, stripe: $stripe, paymentStatus: 'unpaid');

    $this->get(route('order-confirmed', ['session_id' => 'cs_test_1']))
        ->assertOk()
        ->assertSee('Your payment is on its way');
});

it('tells the customer not to pay again when Stripe can’t confirm the payment yet', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    app(StartCheckout::class)(new CheckoutRequest($shop->drop, [$shop->mince->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));
    $stripe->goOffline();

    $this->get(route('order-confirmed', ['session_id' => 'cs_test_1']))
        ->assertOk()
        ->assertSee('there’s no need to pay again');
});

it('explains farm pickup, and doesn’t mention a box for an extras-only order', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    paidFor($shop, [$shop->mince->id => 1], delivery: false, stripe: $stripe);

    $this->get(route('order-confirmed', ['session_id' => 'cs_test_1']))
        ->assertSee('Your order is being prepared')
        ->assertSee('collect from our farm')
        ->assertDontSee('Your beef box is being prepared');
});

it('escapes the name Stripe collected', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    paidFor($shop, [$shop->mince->id => 1], delivery: false, stripe: $stripe, name: '<script>alert(1)</script> Buyer');

    $this->get(route('order-confirmed', ['session_id' => 'cs_test_1']))
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('sends anyone without a paid order to the order page', function (?string $sessionId) {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    app(StartCheckout::class)(new CheckoutRequest($shop->drop, [$shop->mince->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));
    $stripe->timeOutSession('cs_test_1');

    $this->get(route('order-confirmed', array_filter(['session_id' => $sessionId])))->assertRedirect(route('order'));
})->with(['cs_test_1', 'cs_test_someone_else', null]);
