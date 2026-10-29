<?php

declare(strict_types=1);

use App\Checkout\CheckoutRequest;
use App\Checkout\OrderPayments;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStock;
use App\Jobs\SettleOrderWhenPaymentPageCloses;
use App\Mail\LatePaymentNotification;
use App\Mail\NewOrderNotification;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

/**
 * An order for one 5kg box whose hold ran out, so its stock went back on sale.
 */
function orderThatTimedOut(StockedDrop $shop): Order
{
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(15));
    $order->update(['stripe_checkout_session_id' => 'cs_test_1']);
    app(OrderPayments::class)->markExpired($order);

    return $order->refresh();
}

function paidOnStripe(): CheckoutSession
{
    return new CheckoutSession('cs_test_1', 'complete', 'paid', paymentIntentId: 'pi_late', customerName: 'Sam Buyer', email: 'buyer@example.test', phone: '+61400000002');
}

it('confirms a payment that arrives after the hold ran out when the stock is still there', function (): void {
    Mail::fake();
    $shop = StockedDrop::create(boxes: 5);
    $order = orderThatTimedOut($shop);
    expect($shop->box->refresh()->available)->toBe(5);

    app(OrderPayments::class)->apply($order, paidOnStripe());

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->released_at)->toBeNull()
        ->and($order->customer_name)->toBe('Sam Buyer')
        ->and($shop->box->refresh()->available)->toBe(4);
    Mail::assertQueued(OrderConfirmation::class, 1);
    Mail::assertQueued(NewOrderNotification::class, 1);
    Mail::assertNotQueued(LatePaymentNotification::class);
});

it('takes the stock back for a late bundle order too', function (): void {
    Mail::fake();
    $shop = StockedDrop::create(boxes: 5);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->largeBox->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(15));
    $order->update(['stripe_checkout_session_id' => 'cs_test_1']);
    app(OrderPayments::class)->markExpired($order);
    expect($shop->box->refresh()->available)->toBe(5);

    app(OrderPayments::class)->apply($order, paidOnStripe());

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($shop->box->refresh()->available)->toBe(3);
});

it('refunds a payment that arrives after the stock was sold to someone else, once', function (): void {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 1);
    $order = orderThatTimedOut($shop);
    app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'someone-else', now()->addMinutes(15));
    expect($shop->box->refresh()->available)->toBe(0);

    app(OrderPayments::class)->apply($order, paidOnStripe());
    app(OrderPayments::class)->apply($order, paidOnStripe());

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($order->released_at)->not->toBeNull()
        ->and($order->amount_refunded)->toBe($order->total)
        ->and($order->refunded_at)->not->toBeNull()
        ->and($shop->box->refresh()->available)->toBe(0)
        ->and($stripe->refunds)->toBe(["late-payment-{$order->public_id}" => 'pi_late']);
    Mail::assertQueued(LatePaymentNotification::class, 1);
    Mail::assertQueued(LatePaymentNotification::class, fn (LatePaymentNotification $mail): bool => $mail->refunded && $mail->hasTo(config()->string('shop.email')));
    Mail::assertNotQueued(OrderConfirmation::class);
});

it('leaves an unpaid or still-pending session alone', function (): void {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 1);
    $order = orderThatTimedOut($shop);

    app(OrderPayments::class)->apply($order, new CheckoutSession('cs_test_1', 'expired', 'unpaid'));

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($stripe->refunds)->toBe([]);
    Mail::assertNothingQueued();
});

it('expires an order and puts its stock back in one transaction', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(15));
    $outside = DB::transactionLevel();
    $levels = [];
    DB::listen(function (QueryExecuted $query) use (&$levels): void {
        if (str_contains($query->sql, 'update "orders" set "released_at"')) {
            $levels[] = DB::transactionLevel();
        }
    });

    app(OrderPayments::class)->markExpired($order);

    // The status change and the release are both inside the one transaction, which release then nests its own in.
    expect($levels)->toBe([$outside + 2]);
});

it('leaves an order that is still being set up alone when the same visitor tries again', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $settingUp = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, hash('sha256', 'visitor-a'), now()->addMinutes(15));

    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));

    expect($settingUp->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($shop->box->refresh()->available)->toBe(3);
});

it('lets go of an order that never reached Stripe once it is clearly abandoned', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $abandoned = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, hash('sha256', 'visitor-a'), now()->addMinutes(15));
    $this->travel(3)->minutes();

    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));

    expect($abandoned->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('does not count being beaten to the last box against a visitor’s tries', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 1);
    app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'someone-else', now()->addMinutes(15));

    foreach (range(1, 8) as $try) {
        expect(fn (): string => app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1')))
            ->toThrow(InsufficientStock::class);
    }
});

it('holds an order for the shop’s hold time but leaves Stripe’s page open for its minimum', function (): void {
    $this->freezeSecond();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();

    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->mince->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));

    expect(Order::query()->sole()->expires_at->equalTo(now()->addMinutes(15)))->toBeTrue()
        ->and($stripe->createdSessions[0]->expiresAt->equalTo(now()->addMinutes(31)))->toBeTrue();
});

it('closes the payment page itself when the hold runs out, and puts the stock back', function (): void {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));
    $order = Order::query()->sole();
    $this->travel(16)->minutes();

    app()->call([new SettleOrderWhenPaymentPageCloses($order->id), 'handle']);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($stripe->session((string) $order->stripe_checkout_session_id)->status)->toBe('expired')
        ->and($shop->box->refresh()->available)->toBe(5);
});
