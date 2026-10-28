<?php

declare(strict_types=1);

use App\Actions\RunDropPreflight;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use App\Payments\PaymentGateway;
use App\Payments\StripeWebhookEvents;
use App\Support\Turnstile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakePaymentGateway;

/**
 * A drop that should pass: a box, the delivery fee, a delivery day and matching Stripe prices, in an environment
 * with the Stripe webhook registered and Cloudflare's bot check set up.
 */
/**
 * @param  list<string>  $turnstileErrors  what Cloudflare says about the made-up token the check sends
 */
function readyDrop(FakePaymentGateway $stripe, array $turnstileErrors = ['invalid-input-response'], int $turnstileStatus = 200): Drop
{
    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
        'mail.default' => 'resend',
        'services.resend.key' => 're_test',
        'services.twilio.sid' => 'ACtest',
        'services.twilio.token' => 'token',
        'services.twilio.from' => '+61400000000',
        'queue.default' => 'database',
    ]);

    // Cloudflare says a made-up token is invalid, which is what it says when the secret is right.
    Http::preventStrayRequests();
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false, 'error-codes' => $turnstileErrors], $turnstileStatus)]);

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

beforeEach(function (): void {
    $this->stripe = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->stripe);
});

it('passes a drop that is ready to open, and records when it checked', function (): void {
    $this->freezeSecond();
    $drop = readyDrop($this->stripe);

    $report = app(RunDropPreflight::class)($drop);

    expect($report['passed'])->toBeTrue()
        ->and($report['problems'])->toBe([])
        ->and($drop->refresh()->preflight_ran_at?->equalTo(now()))->toBeTrue()
        ->and($drop->preflight_report['passed'] ?? null)->toBeTrue();
});

it('warns when Cloudflare’s bot check is off outside local development', function (string $missingKey): void {
    $drop = readyDrop($this->stripe);
    config(["services.turnstile.{$missingKey}" => null]);

    $report = app(RunDropPreflight::class)($drop);

    expect($report['passed'])->toBeFalse()
        ->and($report['problems'])->toBe(['Cloudflare’s bot check is off, so the order and wait-list forms accept anything. Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY in this environment.']);
})->with(['site_key', 'secret_key']);

it('doesn’t need the bot check when developing locally', function (): void {
    $drop = readyDrop($this->stripe);
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    $this->app->detectEnvironment(fn (): string => 'local');

    expect(app(RunDropPreflight::class)($drop)['passed'])->toBeTrue();
});

it('names the item when its Stripe price is wrong', function (): void {
    $drop = readyDrop($this->stripe);
    $this->stripe->addPrice('price_box', 15000);

    $report = app(RunDropPreflight::class)($drop);

    expect($report['passed'])->toBeFalse()
        ->and($report['problems'])->toContain('5kg Beef Box: Stripe charges $150 for this price, but the drop says $160.');
});

it('finds everything that would stop the drop working', function (Closure $breakIt, string $problem): void {
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

it('looks for the webhook at this site’s own address, so staging checks its own endpoint', function (): void {
    config(['app.url' => 'https://staging.example.test', 'shop.url' => 'https://fergusonlivestock.com.au']);
    $drop = readyDrop($this->stripe);
    app()->instance(PaymentGateway::class, (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)
        ->addWebhookEndpoint('https://fergusonlivestock.com.au/api/webhooks/stripe', StripeWebhookEvents::REQUIRED));

    expect(app(RunDropPreflight::class)($drop)['problems'])
        ->toBe(['Stripe isn’t set up to tell the site about payments. Add the webhook endpoint in Stripe.']);
});

it('names the webhook events Stripe isn’t sending', function (): void {
    $drop = readyDrop($this->stripe);
    $stripe = (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)
        ->addWebhookEndpoint(config()->string('app.url').'/api/webhooks/stripe', ['checkout.session.completed']);
    app()->instance(PaymentGateway::class, $stripe);

    expect(app(RunDropPreflight::class)($drop)['problems'])
        ->toContain('The Stripe webhook doesn’t send checkout.session.expired, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, charge.refunded.');
});

it('skips the webhook check locally, where stripe listen forwards events', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    $drop = readyDrop($this->stripe);
    app()->instance(PaymentGateway::class, (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500));

    expect(app(RunDropPreflight::class)($drop)['problems'])->toBe([]);
});

it('reports Stripe being unreachable instead of failing', function (): void {
    $drop = readyDrop($this->stripe);
    app()->instance(PaymentGateway::class, (new FakePaymentGateway)->goOffline('Connection timed out'));

    expect(app(RunDropPreflight::class)($drop)['problems'])->toContain('Couldn’t reach Stripe to check this drop: Connection timed out');
});

it('warns when the infrastructure a drop depends on isn’t set up', function (string $setting, ?string $value, string $problem): void {
    $drop = readyDrop($this->stripe);
    config([$setting => $value]);

    $report = app(RunDropPreflight::class)($drop);

    expect($report['passed'])->toBeFalse()
        ->and(implode("\n", $report['problems']))->toContain($problem);
})->with([
    'email only goes to the log' => ['mail.default', 'log', 'Order emails won’t reach customers'],
    'Resend has no API key' => ['services.resend.key', null, 'Order emails won’t reach customers'],
    'texts have no account' => ['services.twilio.sid', null, 'Texts aren’t set up'],
    'texts have no number' => ['services.twilio.from', null, 'Texts aren’t set up'],
    'jobs run in the request, with no worker' => ['queue.default', 'sync', 'There’s no queue worker'],
]);

it('warns when the queue can’t be reached', function (): void {
    $drop = readyDrop($this->stripe);
    Queue::shouldReceive('size')->andThrow(new RuntimeException('Connection refused'));

    $report = app(RunDropPreflight::class)($drop);

    expect(implode("\n", $report['problems']))->toContain('Can’t reach the queue');
});

it('warns about background jobs that have already failed', function (): void {
    $drop = readyDrop($this->stripe);
    DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    $report = app(RunDropPreflight::class)($drop);

    expect(implode("\n", $report['problems']))->toContain('1 background job has failed');
});

it('warns about debug mode and a test Stripe key on the live site', function (): void {
    $drop = readyDrop($this->stripe);
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true, 'services.stripe.secret' => 'sk_test_123']);

    $problems = implode("\n", app(RunDropPreflight::class)($drop)['problems']);

    expect($problems)->toContain('Debug mode is on')->toContain('Stripe is in test mode');
});

it('is happy with a live Stripe key and debug off on the live site', function (): void {
    $drop = readyDrop($this->stripe);
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => false, 'services.stripe.secret' => 'sk_live_123']);

    expect(app(RunDropPreflight::class)($drop)['passed'])->toBeTrue();
});

it('warns when Cloudflare rejects the bot-check secret, which would turn every customer away', function (): void {
    $drop = readyDrop($this->stripe, turnstileErrors: ['invalid-input-secret']);

    $report = app(RunDropPreflight::class)($drop);

    expect(implode("\n", $report['problems']))->toContain('Cloudflare doesn’t accept the bot-check secret');
});

it('doesn’t blame the bot check when Cloudflare can’t be reached', function (): void {
    $drop = readyDrop($this->stripe, turnstileErrors: [], turnstileStatus: 502);

    expect(app(RunDropPreflight::class)($drop)['passed'])->toBeTrue();
});
