<?php

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\PaymentProviderUnavailable;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

/**
 * @param  array<int, int>  $quantities
 */
function startCheckout(StockedDrop $shop, array $quantities, bool $delivery = false, string $visitor = 'visitor-a'): string
{
    return app(StartCheckout::class)(new CheckoutRequest(
        drop: $shop->drop,
        quantities: $quantities,
        deliveryMethod: $delivery ? DeliveryMethod::Delivery : DeliveryMethod::Pickup,
        deliveryDay: $delivery ? CarbonImmutable::parse($shop->drop->delivery_days[0]) : null,
        sessionId: $visitor,
        ip: '192.0.2.1',
    ));
}

it('holds the stock, then sends the customer to Stripe for the items and delivery', function () {
    $this->freezeSecond();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);

    $url = startCheckout($shop, [$shop->box->id => 1, $shop->mince->id => 2], delivery: true);

    $order = Order::sole();
    $sent = $stripe->createdSessions[0];
    expect($url)->toBe('https://checkout.stripe.test/cs_test_1')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->stripe_checkout_session_id)->toBe('cs_test_1')
        ->and($order->total)->toBe(16000 + 2 * 1200 + 1500)
        ->and($shop->box->refresh()->available)->toBe(4)
        ->and($sent->orderPublicId)->toBe($order->public_id)
        ->and($sent->lineItems)->toBe([
            ['price' => 'price_box', 'quantity' => 1],
            ['price' => 'price_mince', 'quantity' => 2],
            ['price' => 'price_delivery', 'quantity' => 1],
        ])
        ->and($sent->collectShippingAddress)->toBeTrue()
        ->and($sent->expiresAt->equalTo(now()->addMinutes(31)))->toBeTrue()
        ->and($sent->successUrl)->toBe(route('order-confirmed').'?session_id={CHECKOUT_SESSION_ID}')
        ->and($sent->cancelUrl)->toBe(route('checkout.cancel').'?session_id={CHECKOUT_SESSION_ID}');
});

it('skips the delivery fee and the address for farm pickup', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    startCheckout($shop, [$shop->mince->id => 1]);

    expect($stripe->createdSessions[0]->lineItems)->toBe([['price' => 'price_mince', 'quantity' => 1]])
        ->and($stripe->createdSessions[0]->collectShippingAddress)->toBeFalse();
});

it('lets go of a customer’s earlier unpaid checkout when they start again', function () {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    startCheckout($shop, [$shop->box->id => 1]);

    startCheckout($shop, [$shop->mince->id => 1]);

    $first = Order::oldest('id')->firstOrFail();
    expect($first->status)->toBe(OrderStatus::Expired)
        ->and($stripe->session('cs_test_1')->isExpired())->toBeTrue()
        ->and($shop->box->refresh()->available)->toBe(5)
        ->and(Order::where('status', OrderStatus::Pending)->count())->toBe(1);
});

it('keeps an earlier checkout the customer finished paying for', function () {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    startCheckout($shop, [$shop->box->id => 1]);
    $stripe->completeSession('cs_test_1');

    startCheckout($shop, [$shop->mince->id => 1]);

    expect(Order::oldest('id')->firstOrFail()->status)->toBe(OrderStatus::Paid)
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('puts the stock back when Stripe can’t be reached', function () {
    FakePaymentGateway::swap()->goOffline();
    $shop = StockedDrop::create(boxes: 5);

    expect(fn () => startCheckout($shop, [$shop->box->id => 1]))->toThrow(PaymentProviderUnavailable::class);

    expect(Order::sole()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});
