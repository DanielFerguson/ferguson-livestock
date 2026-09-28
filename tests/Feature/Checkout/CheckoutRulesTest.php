<?php

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\TooManyCheckoutAttempts;
use App\Models\DropItem;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

beforeEach(fn () => FakePaymentGateway::swap());

/**
 * @param  Closure(StockedDrop): array<int, int>  $cart
 */
function attempt(StockedDrop $shop, Closure $cart, DeliveryMethod $method = DeliveryMethod::Pickup, ?string $day = null, string $visitor = 'visitor-a', string $ip = '192.0.2.1'): string
{
    return app(StartCheckout::class)(new CheckoutRequest($shop->drop, $cart($shop), $method, $day === null ? null : CarbonImmutable::parse($day), $visitor, $ip));
}

it('explains what’s wrong with an order it can’t take', function (Closure $cart, DeliveryMethod $method, ?string $day, string $problem) {
    $shop = StockedDrop::create();

    expect(fn () => attempt($shop, $cart, $method, $day === 'offered' ? $shop->drop->delivery_days[0] : $day))
        ->toThrow(InvalidCheckout::class, $problem);

    expect(Order::count())->toBe(0);
})->with([
    'nothing chosen' => [fn (StockedDrop $shop) => [], DeliveryMethod::Pickup, null, 'Choose a box or at least one extra.'],
    'two boxes' => [fn (StockedDrop $shop) => [$shop->box->id => 1, $shop->largeBox->id => 1], DeliveryMethod::Pickup, null, 'Choose one box per order.'],
    'over the limit' => [fn (StockedDrop $shop) => [$shop->mince->id => 11], DeliveryMethod::Pickup, null, 'You can order up to 10 of 500g Beef Mince.'],
    'another drop’s item' => [fn (StockedDrop $shop) => [DropItem::factory()->create()->id => 1], DeliveryMethod::Pickup, null, 'Something in your order isn’t part of this drop.'],
    'the delivery fee on its own' => [fn (StockedDrop $shop) => [$shop->delivery->id => 1], DeliveryMethod::Pickup, null, 'Something in your order isn’t part of this drop.'],
    'no delivery day' => [fn (StockedDrop $shop) => [$shop->mince->id => 1], DeliveryMethod::Delivery, null, 'Choose a delivery day.'],
    'a day that isn’t offered' => [fn (StockedDrop $shop) => [$shop->mince->id => 1], DeliveryMethod::Delivery, '2020-01-01', 'That delivery day isn’t available. Choose another.'],
]);

it('only takes orders while the drop is open', function () {
    $shop = StockedDrop::create(drop: ['closed_at' => now()->subMinute()]);

    attempt($shop, fn (StockedDrop $shop) => [$shop->mince->id => 1]);
})->throws(DropNotOpen::class);

it('limits each visitor to five tries every ten minutes', function () {
    $shop = StockedDrop::create();
    $empty = fn (StockedDrop $shop) => [];

    foreach (range(1, 5) as $try) {
        expect(fn () => attempt($shop, $empty))->toThrow(InvalidCheckout::class);
    }

    expect(fn () => attempt($shop, $empty))->toThrow(TooManyCheckoutAttempts::class);

    $this->travel(11)->minutes();
    expect(fn () => attempt($shop, $empty))->toThrow(InvalidCheckout::class);
});

it('limits one address to thirty tries every ten minutes, however many visitors it has', function () {
    $shop = StockedDrop::create();
    $empty = fn (StockedDrop $shop) => [];

    foreach (range(1, 30) as $try) {
        expect(fn () => attempt($shop, $empty, visitor: "visitor-{$try}"))->toThrow(InvalidCheckout::class);
    }

    expect(fn () => attempt($shop, $empty, visitor: 'visitor-31'))->toThrow(TooManyCheckoutAttempts::class);
});
