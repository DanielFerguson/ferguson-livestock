<?php

declare(strict_types=1);

use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Mail\NewOrderNotification;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\StockedDrop;

function paidDelivery(string $name = 'Sam Buyer', string $line1 = '1 Main St'): Order
{
    Mail::fake();
    $shop = StockedDrop::create(drop: ['delivery_days' => ['2026-10-10']]);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1, $shop->delivery->id => 1], DeliveryMethod::Delivery, CarbonImmutable::parse('2026-10-10'), 'fingerprint', now()->addMinutes(31));

    app(OrderPayments::class)->markPaid($order, new CheckoutSession('cs_test_1', 'complete', 'paid',
        customerName: $name, email: 'buyer@example.test', phone: '+61400000002',
        shippingAddress: ['line1' => $line1, 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU'],
    ));

    return $order->refresh();
}

it('confirms the order to the customer, with what they bought and when it arrives', function (): void {
    $order = paidDelivery();
    $mail = new OrderConfirmation($order);

    $mail->assertHasSubject("Your Ferguson Livestock order {$order->reference()}")
        ->assertHasReplyTo(config()->string('shop.email'))
        ->assertSeeInOrderInHtml(['Thanks, Sam!', $order->reference(), 'is paid and we’re getting it ready.', '5kg Beef Box', '$160', 'Delivery', '$15', '$175', 'Saturday night, 10 October', '1 Main St', 'Ballarat 3350', 'We’ll be in touch to confirm a time.']);
});

it('says where the order is up to when the customer asks for it again from their page', function (Order $order, string $opening): void {
    (new OrderConfirmation($order))
        ->assertSeeInOrderInHtml(['Thanks, Sam!', $order->reference(), $opening])
        ->assertDontSeeInHtml('getting it ready');
})->with([
    'payment clearing' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Processing]), 'is waiting for your bank payment to clear'],
    'delivered' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Fulfilled]), 'Thanks again for buying from us.'],
    'refunded in full' => [fn (): Order => samsWeekendOrder(['amount_refunded' => 17500]), 'has been refunded in full.'],
]);

it('doesn’t promise to be in touch about a delivery that’s been made', function (): void {
    (new OrderConfirmation(samsWeekendOrder(['status' => OrderStatus::Fulfilled])))
        ->assertSeeInOrderInHtml(['Saturday night, 21 November', '1 Main St'])
        ->assertDontSeeInHtml('We’ll be in touch to confirm a time.');
});

it('shows what was refunded, and no delivery for an order refunded in full', function (): void {
    (new OrderConfirmation(samsWeekendOrder(['amount_refunded' => 17500])))
        ->assertSeeInOrderInHtml(['Total', '$175', 'Refunded', '−$175'])
        ->assertDontSeeInHtml('1 Main St');
});

it('tells a customer who chose either night that it comes one of the drop’s nights', function (): void {
    Mail::fake();
    $shop = StockedDrop::create(drop: ['delivery_days' => ['2026-11-14', '2026-11-15']]);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1, $shop->delivery->id => 1], DeliveryMethod::Delivery, null, 'fingerprint', now()->addMinutes(31));

    (new OrderConfirmation($order))->assertSeeInHtml('Delivery</strong> on Saturday or Sunday night, to:', escape: false);
});

it('tells a pickup customer when to come to the farm', function (): void {
    Mail::fake();
    $shop = StockedDrop::create(drop: ['delivery_days' => ['2026-11-14', '2026-11-15']]);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'fingerprint', now()->addMinutes(31));

    (new OrderConfirmation($order))->assertSeeInHtml('from Snake Valley, Saturday or Sunday, 10am to 4pm.');
});

it('tells the farm who ordered and where it’s going', function (): void {
    $order = paidDelivery();

    (new NewOrderNotification($order))
        ->assertHasSubject("New order {$order->reference()}: Sam Buyer")
        ->assertSeeInOrderInHtml(['Sam Buyer', 'buyer@example.test', '+61400000002', '5kg Beef Box', 'Saturday 10 October', '1 Main St, Ballarat 3350']);
});

it('shows Markdown in what the buyer typed as plain text, not links or images', function (): void {
    $order = paidDelivery('[Track your order](https://phish.test/track)', '![](https://phish.test/pixel.png) 1 Main St');

    foreach ([new OrderConfirmation($order), new NewOrderNotification($order)] as $mail) {
        expect($mail->render())
            ->not->toMatch('/(href|src)="https:\/\/phish\.test/')
            ->toContain('https://phish.test/pixel.png');

        $mail->assertSeeInText('![](https://phish.test/pixel.png) 1 Main St');
    }
});

it('escapes names in both emails', function (): void {
    $order = paidDelivery('<b onclick="x()">Sam</b>');

    foreach ([new OrderConfirmation($order), new NewOrderNotification($order)] as $mail) {
        expect($mail->render())
            ->toContain('&lt;b')
            ->not->toContain('<b onclick')
            ->not->toContain('Thanks, <b');
    }
});

it('retries a failed order email a few times before giving up', function (): void {
    $order = Order::factory()->create();
    $confirmation = new OrderConfirmation($order);
    $notification = new NewOrderNotification($order);

    expect([$confirmation->tries, $notification->tries])->toBe([5, 5])
        ->and([$confirmation->backoff, $notification->backoff])->toBe([[10, 30, 120, 600], [10, 30, 120, 600]]);
});

it('links the customer to their order page', function (): void {
    $order = paidDelivery();

    (new OrderConfirmation($order))
        ->assertSeeInHtml('View your order')
        ->assertSeeInHtml($order->pageUrl());
});
