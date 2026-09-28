<?php

use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Mail\NewOrderNotification;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\StockedDrop;

function pendingOrder(StockedDrop $shop): Order
{
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));
    $order->update(['stripe_checkout_session_id' => 'cs_test_1']);

    return $order;
}

/**
 * @param  'open'|'complete'|'expired'  $status
 */
function stripeCheckout(string $status, string $paymentStatus = 'unpaid'): CheckoutSession
{
    return new CheckoutSession('cs_test_1', $status, $paymentStatus, paymentIntentId: 'pi_1', customerName: 'Sam Buyer', email: 'buyer@example.test', phone: '+61400000002');
}

it('marks an order paid, keeps the buyer’s details and emails them and the farm once', function () {
    Mail::fake();
    $shop = StockedDrop::create();
    $order = pendingOrder($shop);

    app(OrderPayments::class)->apply($order, stripeCheckout('complete', 'paid'));
    app(OrderPayments::class)->apply($order, stripeCheckout('complete', 'paid'));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->only('customer_name', 'email', 'phone', 'stripe_payment_intent_id'))->toBe([
            'customer_name' => 'Sam Buyer', 'email' => 'buyer@example.test', 'phone' => '+61400000002', 'stripe_payment_intent_id' => 'pi_1',
        ])
        ->and($order->paid_at)->not->toBeNull();
    Mail::assertQueued(OrderConfirmation::class, 1);
    Mail::assertQueued(OrderConfirmation::class, fn (OrderConfirmation $mail) => $mail->hasTo('buyer@example.test'));
    Mail::assertQueued(NewOrderNotification::class, fn (NewOrderNotification $mail) => $mail->hasTo(config()->string('shop.email')));
});

it('keeps holding the stock while a bank payment clears', function () {
    Mail::fake();
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);

    app(OrderPayments::class)->apply($order, stripeCheckout('complete'));

    expect($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($shop->box->refresh()->available)->toBe(4);
    Mail::assertNothingQueued();
});

it('marks a bank payment paid once it clears', function () {
    Mail::fake();
    $order = pendingOrder(StockedDrop::create());
    app(OrderPayments::class)->apply($order, stripeCheckout('complete'));

    app(OrderPayments::class)->markPaid($order, stripeCheckout('complete', 'paid'));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    Mail::assertQueued(OrderConfirmation::class, 1);
});

it('puts the stock back on sale when a bank payment fails', function () {
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);
    app(OrderPayments::class)->apply($order, stripeCheckout('complete'));

    app(OrderPayments::class)->markFailed($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Failed)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('puts the stock back on sale when the payment page expires', function () {
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);

    app(OrderPayments::class)->apply($order, stripeCheckout('expired'));

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('records refunds without putting stock back', function () {
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);
    app(OrderPayments::class)->apply($order, stripeCheckout('complete', 'paid'));

    app(OrderPayments::class)->recordRefund($order, 8000);
    app(OrderPayments::class)->recordRefund($order, 8000);

    expect($order->refresh()->amount_refunded)->toBe(8000)
        ->and($order->refunded_at)->not->toBeNull()
        ->and($shop->box->refresh()->available)->toBe(4);
});
