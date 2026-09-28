<?php

use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\StockedDrop;

use function Pest\Laravel\call;

beforeEach(fn () => config(['services.stripe.webhook_secret' => 'whsec_test']));

function heldOrder(StockedDrop $shop): Order
{
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));
    $order->update(['stripe_checkout_session_id' => 'cs_test_webhook']);

    return $order;
}

/**
 * @param  array<array-key, mixed>  $overrides
 * @return array<array-key, mixed>
 */
function sessionObject(Order $order, array $overrides = []): array
{
    return [
        'id' => $order->stripe_checkout_session_id, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
        'metadata' => ['order_id' => $order->public_id], 'payment_intent' => 'pi_webhook',
        'customer_details' => ['name' => 'Sam Buyer', 'email' => 'buyer@example.test', 'phone' => '+61400000002'],
        ...$overrides,
    ];
}

/**
 * POST an event to the webhook, signed the way Stripe signs it unless a signature is given.
 *
 * @param  array<array-key, mixed>  $object
 * @return TestResponse<Response>
 */
function sendStripeEvent(string $type, array $object, string $id = 'evt_1', ?string $signature = null): TestResponse
{
    $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature ??= "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

    return call('POST', route('webhooks.stripe'), server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);
}

it('moves the order on for each checkout event', function (string $type, array $session, OrderStatus $status, int $boxesLeft) {
    Mail::fake();
    $shop = StockedDrop::create(boxes: 5);
    $order = heldOrder($shop);

    sendStripeEvent($type, sessionObject($order, $session))->assertOk();

    expect($order->refresh()->status)->toBe($status)
        ->and($shop->box->refresh()->available)->toBe($boxesLeft)
        ->and(StripeEvent::sole()->processed_at)->not->toBeNull();
})->with([
    'paid' => ['checkout.session.completed', [], OrderStatus::Paid, 4],
    'bank payment started' => ['checkout.session.completed', ['payment_status' => 'unpaid'], OrderStatus::Processing, 4],
    'payment page expired' => ['checkout.session.expired', ['status' => 'expired', 'payment_status' => 'unpaid'], OrderStatus::Expired, 5],
]);

it('marks a bank payment paid, or puts the stock back if it fails', function (string $type, OrderStatus $status, int $boxesLeft) {
    Mail::fake();
    $shop = StockedDrop::create(boxes: 5);
    $order = heldOrder($shop);
    app(OrderPayments::class)->apply($order, new CheckoutSession('cs_test_webhook', 'complete', 'unpaid'));

    sendStripeEvent($type, sessionObject($order, ['payment_status' => $status === OrderStatus::Paid ? 'paid' : 'unpaid']))->assertOk();

    expect($order->refresh()->status)->toBe($status)
        ->and($shop->box->refresh()->available)->toBe($boxesLeft);
})->with([
    'cleared' => ['checkout.session.async_payment_succeeded', OrderStatus::Paid, 4],
    'failed' => ['checkout.session.async_payment_failed', OrderStatus::Failed, 5],
]);

it('records a refund', function () {
    Mail::fake();
    $order = heldOrder(StockedDrop::create());
    sendStripeEvent('checkout.session.completed', sessionObject($order), 'evt_paid');

    sendStripeEvent('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_webhook', 'amount_refunded' => 5000], 'evt_refund')->assertOk();

    expect($order->refresh()->amount_refunded)->toBe(5000);
});

it('handles an event once, however many times Stripe sends it', function () {
    Mail::fake();
    $order = heldOrder(StockedDrop::create());

    sendStripeEvent('checkout.session.completed', sessionObject($order))->assertOk();
    sendStripeEvent('checkout.session.completed', sessionObject($order))->assertOk();

    expect(StripeEvent::count())->toBe(1)
        ->and(StripeEvent::sole()->attempts)->toBe(1);
    Mail::assertQueued(OrderConfirmation::class, 1);
});

it('tries again when Stripe resends an event that failed earlier', function () {
    Mail::fake();
    $order = heldOrder(StockedDrop::create());
    $event = ['id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => sessionObject($order)]];
    StripeEvent::create(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'payload' => $event, 'received_at' => now()->subMinute(), 'attempts' => 1, 'last_error' => 'Database went away']);

    sendStripeEvent('checkout.session.completed', sessionObject($order))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(StripeEvent::sole()->only('attempts', 'last_error'))->toBe(['attempts' => 2, 'last_error' => null]);
});

it('acknowledges events it has nothing to do with', function (string $type, array $object) {
    sendStripeEvent($type, $object)->assertOk();

    expect(StripeEvent::sole()->processed_at)->not->toBeNull();
})->with([
    'another shop’s session' => ['checkout.session.completed', ['id' => 'cs_elsewhere', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid']],
    'an event it doesn’t use' => ['customer.created', ['id' => 'cus_1', 'object' => 'customer']],
]);

it('refuses events Stripe didn’t sign', function (?string $signature) {
    $order = heldOrder(StockedDrop::create());

    sendStripeEvent('checkout.session.completed', sessionObject($order), signature: $signature ?? '')->assertBadRequest();

    expect(StripeEvent::count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'unsigned' => [null],
    'signed with another secret' => ['t=1,v1=0000000000000000000000000000000000000000000000000000000000000000'],
]);
