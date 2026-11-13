<?php

declare(strict_types=1);

use App\Enums\SmsStatus;
use App\Jobs\SendOrderConfirmationText;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\SmsGateway;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeSmsGateway;

/**
 * A paid pickup of one 10kg box for Sam, on a weekend drop.
 */
function paidPickupFor(string $phone = '+61412345678'): Order
{
    $order = Order::factory()->for(Drop::factory()->open()->create(['delivery_days' => ['2026-11-14', '2026-11-15']]))->pickup()
        ->create(['customer_name' => 'Sam Buyer', 'phone' => $phone]);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '10kg Beef Box']))->create();

    return $order;
}

function textOrders(Order $order, bool $evenInQuietHours = false): void
{
    (new SendOrderConfirmationText([$order->id], $evenInQuietHours))->handle(app(SmsGateway::class));
}

it('texts the customer about their order once, however many times it runs', function (): void {
    $this->travelTo('2027-03-10 02:00:00'); // 1pm in Melbourne
    $sms = FakeSmsGateway::swap();
    $order = paidPickupFor();

    textOrders($order);
    textOrders($order);

    expect($sms->recipients())->toBe(['+61412345678'])
        ->and($sms->sent[0]['body'])->toStartWith('Ferguson Livestock: Hi Sam, thanks for your order '.$order->reference().': 1 x 10kg Beef Box.')
        ->and($order->refresh()->confirmation_texted_at)->not->toBeNull();
    expect(SmsMessage::query()->sole())
        ->status->toBe(SmsStatus::Sent)
        ->contact_phone->toBe('+61412345678')
        ->sms_broadcast_id->toBeNull();
});

it('doesn’t text a number that has opted out', function (): void {
    $this->travelTo('2027-03-10 02:00:00');
    $sms = FakeSmsGateway::swap();
    Subscriber::factory()->unsubscribed()->create(['phone' => '+61412345678']);
    $order = paidPickupFor();

    textOrders($order);

    expect($sms->sent)->toBe([])
        ->and($order->refresh()->confirmation_texted_at)->toBeNull();
});

it('records a text Twilio refused, and opts out a number that replied STOP', function (): void {
    $this->travelTo('2027-03-10 02:00:00');
    $sms = FakeSmsGateway::swap()->refuse('+61412345678', 21610);
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);

    textOrders(paidPickupFor());

    expect(SmsMessage::query()->sole())
        ->status->toBe(SmsStatus::Failed)
        ->error_code->toBe(21610);
    expect($subscriber->refresh()->isSubscribed())->toBeFalse();
});

it('waits until 8am to text an order paid at night, unless the owner chose to send now', function (): void {
    $this->travelTo('2027-03-10 10:00:00'); // 9pm in Melbourne
    $sms = FakeSmsGateway::swap();
    Queue::fake([SendOrderConfirmationText::class]);
    $waiting = paidPickupFor('+61412345678');
    $now = paidPickupFor('+61412345679');

    textOrders($waiting);
    textOrders($now, evenInQuietHours: true);

    expect($sms->recipients())->toBe(['+61412345679'])
        ->and($waiting->refresh()->confirmation_texted_at)->toBeNull();
    Queue::assertPushed(SendOrderConfirmationText::class, fn (SendOrderConfirmationText $job): bool => $job->orderIds === [$waiting->id]);
});
