<?php

declare(strict_types=1);

use App\Actions\Orders\ChangeDeliveryNight;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SmsStatus;
use App\Mail\DeliveryNightChanged;
use App\Models\Order;
use App\Models\SmsMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

beforeEach(fn () => travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'Australia/Melbourne')));

/**
 * Ask to move the order to this night from its page.
 *
 * @return TestResponse<Response>
 */
function changeNightTo(Order $order, ?string $night): TestResponse
{
    return post(route('orders.night', ['customerOrder' => $order->access_token]), $night === null ? [] : ['night' => $night]);
}

it('moves a delivery to another of the drop’s nights, and tells the farm', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    changeNightTo($order, '2026-11-22')
        ->assertRedirect($order->pageUrl().'#delivery')
        ->assertSessionHas('order-page.night', 'Done. We’ll deliver on Sunday night, 22 November.');

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
    Mail::assertQueued(DeliveryNightChanged::class, fn (DeliveryNightChanged $mail): bool => $mail->hasTo(config()->string('shop.email'))
        && $mail->previousNight === 'Saturday night, 21 November'
        && $mail->newNight === 'Sunday night, 22 November');
});

it('changes the night of an order that’s still to be paid for', function (Order $order): void {
    Mail::fake();

    changeNightTo($order, '2026-11-22')->assertSessionHasNoErrors();

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
    Mail::assertQueued(DeliveryNightChanged::class, 1);
})->with([
    'bank payment clearing' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Processing])],
    'cash owed' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::CashOwed, 'payment_method' => PaymentMethod::Cash, 'paid_at' => null])],
]);

it('takes either night when the customer says either suits', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    changeNightTo($order, ChangeDeliveryNight::EITHER)->assertSessionHas('order-page.night', 'Done. We’ll deliver on Saturday or Sunday night.');

    expect($order->refresh()->delivery_day)->toBeNull();
});

it('still changes the night until 12pm the day before the earlier night', function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-20 11:59', 'Australia/Melbourne'));
    $order = samsWeekendOrder();

    changeNightTo($order, '2026-11-22')->assertSessionHasNoErrors();

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
});

it('says it’s too late from 12pm the day before the earlier night', function (?string $from, string $to): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'Australia/Melbourne'));
    $order = samsWeekendOrder(['delivery_day' => $from]);

    changeNightTo($order, $to)
        ->assertRedirect($order->pageUrl().'#delivery')
        ->assertSessionHasErrorsIn('night', ['night' => 'It’s too late to change your night here. Text us on 0427 458 706 and we’ll see what we can do.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe($from);
    Mail::assertNothingQueued();
})->with([
    'Saturday to Sunday' => ['2026-11-21', '2026-11-22'],
    'Sunday to either night, which counts as Saturday' => ['2026-11-22', ChangeDeliveryNight::EITHER],
]);

it('closes a change by the earlier of its two nights, not the drop’s first', function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-20 13:00', 'Australia/Melbourne'));
    $order = samsWeekendOrder(['delivery_day' => '2026-11-22'], nights: ['2026-11-21', '2026-11-22', '2026-11-23']);

    changeNightTo($order, '2026-11-23')->assertSessionHasNoErrors();

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-23');
});

it('says it’s too late to move from Sunday to Monday from 12pm on Saturday', function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'Australia/Melbourne'));
    $order = samsWeekendOrder(['delivery_day' => '2026-11-22'], nights: ['2026-11-21', '2026-11-22', '2026-11-23']);

    changeNightTo($order, '2026-11-23')->assertSessionHasErrorsIn('night', ['night' => 'It’s too late to change your night here. Text us on 0427 458 706 and we’ll see what we can do.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
    Mail::assertNothingQueued();
});

it('takes five changes an hour, so the order’s link can’t fill the farm’s inbox', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    foreach (['2026-11-22', '2026-11-21', '2026-11-22', '2026-11-21', '2026-11-22'] as $night) {
        changeNightTo($order, $night)->assertSessionHasNoErrors();
    }

    changeNightTo($order, '2026-11-21')->assertSessionHasErrorsIn('night', ['night' => 'You’ve changed your night a few times now. Text us on 0427 458 706 if it needs to change again.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
    Mail::assertQueued(DeliveryNightChanged::class, 5);

    travel(1)->hours();
    changeNightTo($order, '2026-11-21')->assertSessionHasNoErrors();

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-21');
    Mail::assertQueued(DeliveryNightChanged::class, 6);
});

it('counts only the changes that go through towards the hour’s five', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    foreach (range(1, 5) as $try) {
        changeNightTo($order, '2026-11-23');
    }

    changeNightTo($order, '2026-11-22')->assertSessionHasNoErrors();

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
});

it('refuses a night the drop doesn’t deliver on', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    changeNightTo($order, '2026-11-23')->assertSessionHasErrorsIn('night', ['night' => 'That isn’t one of this drop’s delivery nights. Choose another.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-21');
    Mail::assertNothingQueued();
});

it('asks the customer to choose a night', function (): void {
    changeNightTo(samsWeekendOrder(), null)->assertSessionHasErrorsIn('night', ['night' => 'Choose a night.']);
});

it('refuses a pickup, which has no night to change', function (): void {
    $order = samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]);

    changeNightTo($order, '2026-11-22')->assertSessionHasErrorsIn('night', ['night' => 'This order is a farm pickup, so there’s no delivery night to change.']);
});

it('refuses an order that’s been delivered', function (): void {
    $order = samsWeekendOrder(['status' => OrderStatus::Fulfilled]);

    changeNightTo($order, '2026-11-22')->assertSessionHasErrorsIn('night', ['night' => 'It’s too late to change your night here. Text us on 0427 458 706 and we’ll see what we can do.']);
});

it('refuses an order that’s been refunded in full', function (): void {
    Mail::fake();
    $order = samsWeekendOrder(['amount_refunded' => 17500]);

    changeNightTo($order, '2026-11-22')->assertSessionHasErrorsIn('night', ['night' => 'This order has been refunded, so there’s no delivery night to change.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-21');
    Mail::assertNothingQueued();
});

it('can’t change a checkout that never became an order', function (): void {
    changeNightTo(samsWeekendOrder(['status' => OrderStatus::Expired]), '2026-11-22')->assertNotFound();
});

it('rewrites the order text if it’s still waiting for the morning', function (): void {
    Mail::fake();
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Queued)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);

    app(ChangeDeliveryNight::class)($order->load('drop'), '2026-11-22');

    expect($text->refresh()->body)->toContain("We'll deliver order {$order->reference()} on Sunday after 4pm");
});

it('leaves an order text that’s already gone alone', function (): void {
    Mail::fake();
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Sent)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);

    app(ChangeDeliveryNight::class)($order->load('drop'), '2026-11-22');

    expect($text->refresh()->body)->toBe('Ferguson Livestock: Hi Sam, thanks for your order FL-ABC123: 1 x 5kg Beef Box.');
});

it('tells the farm the nights it moved from and to, even if the customer has moved it again since', function (): void {
    $order = samsWeekendOrder(['delivery_day' => '2026-11-22']);
    $mail = new DeliveryNightChanged($order, 'Saturday night, 21 November', 'Sunday night, 22 November');

    $order->update(['delivery_day' => '2026-11-21']);

    $mail->assertHasSubject("{$order->reference()} moved to Sunday night, 22 November")
        ->assertSeeInOrderInHtml(['Sam Buyer', 'Saturday night, 21 November', 'Sunday night, 22 November']);
});

it('offers the drop’s other nights on the order page until changes close', function (): void {
    $this->get(samsWeekendOrder()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Need a different night?', 'Saturday night, 21 November', 'Sunday night, 22 November', 'Either night suits me', 'Change night']);
});

it('doesn’t offer to change the night when there’s nothing to change it to', function (Order $order): void {
    $this->get($order->pageUrl())->assertOk()->assertDontSee('Need a different night?');
})->with([
    'a pickup' => [fn (): Order => samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null])],
    'a one-night drop' => [fn (): Order => samsWeekendOrder(nights: ['2026-11-21'])],
    'already delivered' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Fulfilled])],
    'refunded in full' => [fn (): Order => samsWeekendOrder(['amount_refunded' => 17500])],
    'from 12pm the day before' => [function (): Order {
        travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'Australia/Melbourne'));

        return samsWeekendOrder();
    }],
]);
