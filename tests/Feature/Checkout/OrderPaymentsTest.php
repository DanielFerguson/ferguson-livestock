<?php

declare(strict_types=1);

use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Jobs\SendMetaEvent;
use App\Mail\NewOrderNotification;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

it('marks an order paid, keeps the buyer’s details and emails them and the farm once', function (): void {
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

it('keeps holding the stock while a bank payment clears', function (): void {
    Mail::fake();
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);

    app(OrderPayments::class)->apply($order, stripeCheckout('complete'));

    expect($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($shop->box->refresh()->available)->toBe(4);
    Mail::assertNothingQueued();
});

it('marks a bank payment paid once it clears', function (): void {
    Mail::fake();
    $order = pendingOrder(StockedDrop::create());
    app(OrderPayments::class)->apply($order, stripeCheckout('complete'));

    app(OrderPayments::class)->markPaid($order, stripeCheckout('complete', 'paid'));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    Mail::assertQueued(OrderConfirmation::class, 1);
});

it('puts the stock back on sale when a bank payment fails', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);
    app(OrderPayments::class)->apply($order, stripeCheckout('complete'));

    app(OrderPayments::class)->markFailed($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Failed)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('puts the stock back on sale when the payment page expires', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);

    app(OrderPayments::class)->apply($order, stripeCheckout('expired'));

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('records refunds without putting stock back', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $order = pendingOrder($shop);
    app(OrderPayments::class)->apply($order, stripeCheckout('complete', 'paid'));

    app(OrderPayments::class)->recordRefund($order, 8000);
    app(OrderPayments::class)->recordRefund($order, 8000);

    expect($order->refresh()->amount_refunded)->toBe(8000)
        ->and($order->refunded_at)->not->toBeNull()
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('reports a paid order to Meta once, with the buyer’s details hashed and their browser from checkout', function (): void {
    Mail::fake();
    enableMetaAds();
    Queue::fake([SendMetaEvent::class]);
    $shop = StockedDrop::create();
    $order = pendingOrder($shop);
    $order->update(['meta_visitor' => ['ip' => '192.0.2.1', 'user_agent' => 'Test Browser', 'fbp' => 'fb.1.1700000000000.111', 'fbc' => null]]);
    $paid = new CheckoutSession('cs_test_1', 'complete', 'paid', paymentIntentId: 'pi_1', customerName: ' Sam  Van Buyer ', email: ' Buyer@Example.test ', phone: '+61400000002',
        shippingAddress: ['line1' => '1 Main St', 'line2' => null, 'city' => 'Snake Valley', 'state' => 'VIC', 'postal_code' => '3351', 'country' => 'AU']);

    app(OrderPayments::class)->apply($order, $paid);
    app(OrderPayments::class)->apply($order, $paid);

    expect(queuedMetaEvent())->toMatchArray([
        'event_name' => 'Purchase',
        'event_time' => $order->refresh()->paid_at?->getTimestamp(),
        'event_id' => "purchase-{$order->public_id}",
        'user_data' => [
            'em' => hash('sha256', 'buyer@example.test'),
            'ph' => hash('sha256', '61400000002'),
            'fn' => hash('sha256', 'sam'),
            'ln' => hash('sha256', 'buyer'),
            'ct' => hash('sha256', 'snakevalley'),
            'st' => hash('sha256', 'vic'),
            'zp' => hash('sha256', '3351'),
            'country' => hash('sha256', 'au'),
            'client_ip_address' => '192.0.2.1',
            'client_user_agent' => 'Test Browser',
            'fbp' => 'fb.1.1700000000000.111',
        ],
        'custom_data' => [
            'value' => 160.0,
            'currency' => 'AUD',
            'content_type' => 'product',
            'contents' => [['id' => (string) $shop->box->product_id, 'quantity' => 1]],
            'num_items' => 1,
            'order_id' => $order->reference(),
        ],
    ]);
});
