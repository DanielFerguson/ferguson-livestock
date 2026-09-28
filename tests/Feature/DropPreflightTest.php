<?php

use App\Actions\RunDropPreflight;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use App\Payments\PaymentGateway;
use App\Payments\StripeWebhookEvents;
use Tests\Fakes\FakePaymentGateway;

/**
 * A drop that should pass: a box, the delivery fee, a delivery day and matching Stripe prices.
 */
function readyDrop(FakePaymentGateway $stripe): Drop
{
    $drop = Drop::factory()->create([
        'opens_at' => now()->addMinutes(5),
        'delivery_days' => [now()->addDays(3)->toDateString()],
    ]);

    DropItem::factory()->for($drop)->for(Product::factory()->box()->create(['name' => '5kg Beef Box']))
        ->create(['price' => 16000, 'stripe_price_id' => 'price_box', 'quantity' => 5]);
    DropItem::factory()->for($drop)->delivery()
        ->create(['price' => 1500, 'stripe_price_id' => 'price_delivery']);

    $stripe->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)
        ->addWebhookEndpoint(config()->string('app.url').'/api/webhooks/stripe', StripeWebhookEvents::REQUIRED);

    return $drop;
}

beforeEach(function () {
    $this->stripe = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->stripe);
});

it('passes a drop that is ready to open, and records when it checked', function () {
    $this->freezeSecond();
    $drop = readyDrop($this->stripe);

    $report = app(RunDropPreflight::class)($drop);

    expect($report['passed'])->toBeTrue()
        ->and($report['problems'])->toBe([])
        ->and($drop->refresh()->preflight_ran_at?->equalTo(now()))->toBeTrue()
        ->and($drop->preflight_report['passed'] ?? null)->toBeTrue();
});

it('names the item when its Stripe price is wrong', function () {
    $drop = readyDrop($this->stripe);
    $this->stripe->addPrice('price_box', 15000);

    $report = app(RunDropPreflight::class)($drop);

    expect($report['passed'])->toBeFalse()
        ->and($report['problems'])->toContain('5kg Beef Box: Stripe charges $150 for this price, but the drop says $160.');
});

it('finds everything that would stop the drop working', function (Closure $breakIt, string $problem) {
    $drop = readyDrop($this->stripe);
    $breakIt($drop, $this->stripe);

    expect(app(RunDropPreflight::class)($drop->refresh())['problems'])->toContain($problem);
})->with([
    'no stock' => [fn (Drop $drop) => $drop->items()->whereNotNull('quantity')->update(['quantity' => 0, 'available' => 0]), '5kg Beef Box has no stock. Set a quantity.'],
    'stock already held' => [fn (Drop $drop) => $drop->items()->whereNotNull('quantity')->update(['available' => 3]), '5kg Beef Box has 2 units held or sold before the drop has opened.'],
    'no delivery fee' => [fn (Drop $drop) => $drop->items()->whereNull('quantity')->delete(), 'Add the delivery fee to this drop.'],
    'no delivery days' => [fn (Drop $drop) => $drop->update(['delivery_days' => []]), 'Add at least one delivery day.'],
    'delivery day before opening' => [fn (Drop $drop) => $drop->update(['delivery_days' => [now()->subDay()->toDateString()]]), 'Delivery day '.now()->subDay()->format('D j M').' is before the drop opens.'],
    'webhook missing' => [fn (Drop $drop, FakePaymentGateway $stripe) => app()->instance(PaymentGateway::class, (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)), 'Stripe isn’t set up to tell the site about payments. Add the webhook endpoint in Stripe.'],
]);

it('looks for the webhook at this site’s own address, so staging checks its own endpoint', function () {
    config(['app.url' => 'https://staging.example.test', 'shop.url' => 'https://www.fergusonlivestock.com.au']);
    $drop = readyDrop($this->stripe);
    app()->instance(PaymentGateway::class, (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)
        ->addWebhookEndpoint('https://www.fergusonlivestock.com.au/api/webhooks/stripe', StripeWebhookEvents::REQUIRED));

    expect(app(RunDropPreflight::class)($drop)['problems'])
        ->toBe(['Stripe isn’t set up to tell the site about payments. Add the webhook endpoint in Stripe.']);
});

it('names the webhook events Stripe isn’t sending', function () {
    $drop = readyDrop($this->stripe);
    $stripe = (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)
        ->addWebhookEndpoint(config()->string('app.url').'/api/webhooks/stripe', ['checkout.session.completed']);
    app()->instance(PaymentGateway::class, $stripe);

    expect(app(RunDropPreflight::class)($drop)['problems'])
        ->toContain('The Stripe webhook doesn’t send checkout.session.expired, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, charge.refunded.');
});

it('reports Stripe being unreachable instead of failing', function () {
    $drop = readyDrop($this->stripe);
    app()->instance(PaymentGateway::class, (new FakePaymentGateway)->goOffline('Connection timed out'));

    expect(app(RunDropPreflight::class)($drop)['problems'])->toContain('Couldn’t reach Stripe to check this drop: Connection timed out');
});
