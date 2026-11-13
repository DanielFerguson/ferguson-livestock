<?php

declare(strict_types=1);

use App\Actions\Orders\OrderConfirmationTexts;
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
 * A paid pickup of one 10kg box for Sam, on a weekend drop, with its text saved and waiting.
 */
function waitingOrderText(string $phone = '+61412345678'): SmsMessage
{
    $order = Order::factory()->for(Drop::factory()->open()->create(['delivery_days' => ['2026-11-14', '2026-11-15']]))->pickup()
        ->create(['customer_name' => 'Sam Buyer', 'phone' => $phone]);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '10kg Beef Box']))->create();

    return app(OrderConfirmationTexts::class)->forPaidOrder($order) ?? throw new RuntimeException('No text was saved.');
}

function sendOrderText(SmsMessage $message, bool $evenInQuietHours = false): void
{
    (new SendOrderConfirmationText($message->id, $evenInQuietHours))->handle(app(SmsGateway::class));
}

beforeEach(fn () => Queue::fake([SendOrderConfirmationText::class]));

it('saves the text for a paid order, waiting to go, and links the order to it', function (): void {
    $message = waitingOrderText();

    expect($message)
        ->status->toBe(SmsStatus::Queued)
        ->is_order_confirmation->toBeTrue()
        ->to->toBe('+61412345678')
        ->body->toStartWith('Ferguson Livestock: Hi Sam, thanks for your order');
    expect($message->orders()->sole()->confirmation_sms_message_id)->toBe($message->id);
    Queue::assertPushed(SendOrderConfirmationText::class, fn (SendOrderConfirmationText $job): bool => $job->messageId === $message->id && ! $job->evenInQuietHours);
});

it('sends a saved text once, however many times it runs', function (): void {
    $this->travelTo('2027-03-10 02:00:00'); // 1pm in Melbourne
    $sms = FakeSmsGateway::swap();
    $message = waitingOrderText();

    sendOrderText($message);
    sendOrderText($message);

    expect($sms->recipients())->toBe(['+61412345678'])
        ->and($sms->sent[0]['body'])->toBe($message->body);
    expect($message->refresh()->status)->toBe(SmsStatus::Sent)
        ->and($message->sent_at)->not->toBeNull();
});

it('doesn’t save a text for a number that has opted out', function (): void {
    Subscriber::factory()->unsubscribed()->create(['phone' => '+61412345678']);

    expect(fn (): SmsMessage => waitingOrderText())->toThrow(RuntimeException::class);

    expect(SmsMessage::query()->count())->toBe(0);
});

it('skips a waiting text if they opt out before it goes', function (): void {
    $this->travelTo('2027-03-10 02:00:00');
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);
    $message = waitingOrderText();
    $subscriber->optOut();

    sendOrderText($message);

    expect($sms->sent)->toBe([])
        ->and($message->refresh()->status)->toBe(SmsStatus::Skipped);
});

it('records a text Twilio refused, and opts out a number that replied STOP', function (): void {
    $this->travelTo('2027-03-10 02:00:00');
    FakeSmsGateway::swap()->refuse('+61412345678', 21610);
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);
    $message = waitingOrderText();

    sendOrderText($message);

    expect($message->refresh())
        ->status->toBe(SmsStatus::Failed)
        ->error_code->toBe(21610);
    expect($subscriber->refresh()->isSubscribed())->toBeFalse();
});

it('waits until 8am to send a text saved at night, unless the owner chose to send now', function (): void {
    $this->travelTo('2027-03-10 10:00:00'); // 9pm in Melbourne
    $sms = FakeSmsGateway::swap();
    $waiting = waitingOrderText('+61412345678');
    $now = waitingOrderText('+61412345679');

    sendOrderText($waiting);
    sendOrderText($now, evenInQuietHours: true);

    expect($sms->recipients())->toBe(['+61412345679'])
        ->and($waiting->refresh()->status)->toBe(SmsStatus::Queued);
});
