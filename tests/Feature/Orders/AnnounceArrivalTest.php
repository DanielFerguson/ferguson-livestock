<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AdminPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    Notification::fake();
    travelTo(CarbonImmutable::parse('2026-11-21 10:30', 'Australia/Melbourne'));

    // The shop's admin, with a phone that gets pushes.
    config(['shop.admin_email' => 'daniel@example.com']);
    User::factory()->create(['email' => 'daniel@example.com'])
        ->updatePushSubscription('https://web.push.apple.com/phone', 'public-key', 'auth-token', 'aes128gcm');
});

/**
 * Sam's farm pickup in a drop collected on the 21st and 22nd.
 *
 * @param  array<string, mixed>  $attributes
 */
function pickupForSam(array $attributes = []): Order
{
    return samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null, ...$attributes]);
}

/**
 * @return TestResponse<Response>
 */
function tapWereHere(Order $order): TestResponse
{
    return post(route('orders.arrived', ['customerOrder' => $order->access_token]));
}

it('shows a pickup customer where to park and the button, on a pickup day', function (): void {
    $this->get(pickupForSam()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Here to collect?', 'Come up the driveway and park anywhere near the house', 'We’re here', 'Farm pickup']);
});

it('tells the farm who’s arrived and their order, and the customer that we’re coming out', function (): void {
    $admin = User::admin();
    $order = pickupForSam();

    tapWereHere($order)->assertRedirect($order->pageUrl().'#arrival');

    Notification::assertSentTo($admin, AdminPush::class, fn (AdminPush $push): bool => $push->title === 'Sam Buyer is here'
        && $push->body === $order->reference()
        && $push->url === "/admin/orders/{$order->id}");
    $this->get($order->pageUrl())->assertSee('Thanks, we know you’re here! Dan or Tahlia will be out in a moment. If no one’s out in 5 minutes, call us on 0412 144 625.');
});

it('pushes once for taps close together, and again for a later arrival', function (): void {
    $admin = User::admin();
    $order = pickupForSam();

    tapWereHere($order);
    tapWereHere($order)->assertSessionHas('order-page.arrival', 'We know you’re here, and we’ll be out in a moment. If no one’s out in 5 minutes, call us on 0412 144 625.');
    Notification::assertSentToTimes($admin, AdminPush::class, 1);

    $this->travel(3)->minutes();
    tapWereHere($order);
    Notification::assertSentToTimes($admin, AdminPush::class, 2);
});

it('offers the button only on a pickup day, for a pickup still to collect, while the admin gets pushes', function (Order $order): void {
    $this->get($order->pageUrl())->assertOk()->assertDontSee('We’re here');
    tapWereHere($order)->assertSessionHas('order-page.arrival', 'Sorry, we couldn’t pass that on. Call or text us on 0412 144 625 and we’ll come out to meet you.');
    Notification::assertNothingSent();
})->with([
    'a delivery' => [fn (): Order => samsWeekendOrder()],
    'a day that isn’t a pickup day' => [function (): Order {
        travelTo(CarbonImmutable::parse('2026-11-20 10:30', 'Australia/Melbourne'));

        return pickupForSam();
    }],
    'an order already collected' => [fn (): Order => pickupForSam(['status' => OrderStatus::Fulfilled])],
    'an order refunded in full' => [fn (): Order => pickupForSam(['amount_refunded' => 17500])],
    'an admin without a phone getting pushes' => [function (): Order {
        User::admin()?->pushSubscriptions()->delete();

        return pickupForSam();
    }],
]);

it('asks them to call when the push can’t be queued', function (): void {
    $order = pickupForSam();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('The queue is down'));

    tapWereHere($order)->assertSessionHas('order-page.arrival', 'Sorry, we couldn’t pass that on. Call or text us on 0412 144 625 and we’ll come out to meet you.');
});
