<?php

declare(strict_types=1);

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Jobs\SettleOrderWhenPaymentPageCloses;
use App\Models\Order;
use App\Stock\StockLedger;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-'.$minutesAgo, '192.0.2.1'));
    travelBack();

    return Order::query()->latest('id')->firstOrFail();
}

it('puts stock back from payment pages Stripe has closed', function (): void {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $order = leftAtPaymentPage($shop);
    $stripe->timeOutSession((string) $order->stripe_checkout_session_id);

    expect(Artisan::call('orders:sweep'))->toBe(0);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('closes payment pages that have run past their time', function (): void {
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $order = leftAtPaymentPage($shop);

    Artisan::call('orders:sweep');

    expect($stripe->session((string) $order->stripe_checkout_session_id)->isExpired())->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Expired);
});

it('catches payments whose webhook never arrived', function (): void {
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $order = leftAtPaymentPage(StockedDrop::create());
    $stripe->completeSession((string) $order->stripe_checkout_session_id);

    Artisan::call('orders:sweep');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('puts stock back from orders that never reached Stripe', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $this->travel(-3)->minutes();
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));
    $this->travelBack();

    Artisan::call('orders:sweep');

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('leaves customers who are still at the payment page alone', function (): void {
    $stripe = FakePaymentGateway::swap();
    $order = leftAtPaymentPage(StockedDrop::create(), minutesAgo: 10);

    Artisan::call('orders:sweep');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($stripe->session((string) $order->stripe_checkout_session_id)->status)->toBe('open');
});

it('settles a payment page once Stripe has closed it, without waiting for the hourly sweep', function (): void {
    Queue::fake();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor', '192.0.2.1'));
    $order = Order::query()->latest('id')->firstOrFail();
    $stripe->timeOutSession((string) $order->stripe_checkout_session_id);

    Queue::assertPushed(SettleOrderWhenPaymentPageCloses::class, fn (SettleOrderWhenPaymentPageCloses $job): bool => $job->orderId === $order->id);

    $this->travelTo($order->expires_at->addMinutes(3));
    app()->call([new SettleOrderWhenPaymentPageCloses($order->id), 'handle']);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('waits for the payment page to close a quarter of an hour at a time, the longest delay the queue allows', function (): void {
    FakePaymentGateway::swap();
    $order = leftAtPaymentPage(StockedDrop::create(), minutesAgo: 1);
    Queue::fake();
    $this->freezeTime();

    app()->call([new SettleOrderWhenPaymentPageCloses($order->id), 'handle']);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Queue::assertPushed(SettleOrderWhenPaymentPageCloses::class, fn (SettleOrderWhenPaymentPageCloses $job): bool => $job->orderId === $order->id
        && $job->delay instanceof CarbonInterface
        && $job->delay->eq(now()->addMinutes(15)));
});

it('settles an order that never reached Stripe two minutes after it was made', function (): void {
    FakePaymentGateway::swap();
    $shop = StockedDrop::create(boxes: 5);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));

    $this->travelTo($order->created_at->addMinutes(2));
    app()->call([new SettleOrderWhenPaymentPageCloses($order->id), 'handle']);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});

it('sweeps every hour, as a safety net for timed settles that went missing', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'orders:sweep'));

    expect($event?->expression)->toBe('0 * * * *');
});

it('clears failed jobs out after thirty days, so old order details don’t pile up', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'queue:prune-failed'));

    expect($event?->command)->toContain('--hours=720');
});
