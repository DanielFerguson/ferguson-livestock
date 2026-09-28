<?php

use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Mail\NewOrderNotification;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\StockedDrop;

function paidDelivery(string $name = 'Sam Buyer'): Order
{
    Mail::fake();
    $shop = StockedDrop::create(drop: ['delivery_days' => ['2026-10-10']]);
    $order = app(StockLedger::class)->reserve($shop->drop, [$shop->box->id => 1, $shop->delivery->id => 1], DeliveryMethod::Delivery, CarbonImmutable::parse('2026-10-10'), 'fingerprint', now()->addMinutes(31));

    app(OrderPayments::class)->markPaid($order, new CheckoutSession('cs_test_1', 'complete', 'paid',
        customerName: $name, email: 'buyer@example.test', phone: '+61400000002',
        shippingAddress: ['line1' => '1 Main St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU'],
    ));

    return $order->refresh();
}

it('confirms the order to the customer, with what they bought and when it arrives', function () {
    $order = paidDelivery();
    $mail = new OrderConfirmation($order);

    $mail->assertHasSubject("Your Ferguson Livestock order {$order->reference()}")
        ->assertHasReplyTo(config()->string('shop.email'))
        ->assertSeeInOrderInHtml(['Thanks, Sam!', $order->reference(), '5kg Beef Box', '$160', 'Delivery', '$15', '$175', 'Saturday 10 October', '1 Main St', 'Ballarat 3350']);
});

it('tells the farm who ordered and where it’s going', function () {
    $order = paidDelivery();

    (new NewOrderNotification($order))
        ->assertHasSubject("New order {$order->reference()}: Sam Buyer")
        ->assertSeeInOrderInHtml(['Sam Buyer', 'buyer@example.test', '+61400000002', '5kg Beef Box', 'Saturday 10 October', '1 Main St, Ballarat 3350']);
});

it('escapes names in both emails', function () {
    $order = paidDelivery('<b onclick="x()">Sam</b>');

    foreach ([new OrderConfirmation($order), new NewOrderNotification($order)] as $mail) {
        expect($mail->render())
            ->toContain('&lt;b')
            ->not->toContain('<b onclick')
            ->not->toContain('Thanks, <b');
    }
});
