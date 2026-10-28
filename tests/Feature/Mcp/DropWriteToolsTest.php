<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Drops\CloseDropNowTool;
use App\Mcp\Tools\Drops\CreateDropTool;
use App\Mcp\Tools\Drops\DeleteDropTool;
use App\Mcp\Tools\Drops\DuplicateDropTool;
use App\Mcp\Tools\Drops\PrepareDeleteDropTool;
use App\Mcp\Tools\Drops\PreparePublishDropTool;
use App\Mcp\Tools\Drops\PublishDropTool;
use App\Mcp\Tools\Drops\RemoveDropItemTool;
use App\Mcp\Tools\Drops\RunDropPreflightTool;
use App\Mcp\Tools\Drops\SetDropItemTool;
use App\Mcp\Tools\Drops\UnpublishDropTool;
use App\Mcp\Tools\Drops\UpdateDropTool;
use App\Mcp\Tools\Orders\MarkOrderFulfilledTool;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\StripeWebhookEvents;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Mcp\Server\Tool;
use Tests\Fakes\FakePaymentGateway;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
    $this->stripe = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->stripe);
    $this->stripe->addPrice('price_box', 16000)->addPrice('price_delivery', 1500)->addPrice('price_wrong', 999);
    $this->box = Product::factory()->box()->create(['name' => '5kg Beef Box']);
    $this->delivery = Product::factory()->delivery()->create(['name' => 'Delivery']);
});

/** A saved draft with a box and the delivery fee, and an environment where the pre-flight check passes. */
function draftReadyToPublish(FakePaymentGateway $stripe, Product $box, Product $delivery): Drop
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

    Http::preventStrayRequests();
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    $stripe->addWebhookEndpoint(config()->string('app.url').'/api/webhooks/stripe', StripeWebhookEvents::REQUIRED);

    $drop = Drop::factory()->draft()->create(['opens_at' => now()->addDays(2), 'delivery_days' => [now()->addDays(5)->toDateString()]]);
    DropItem::factory()->for($drop)->for($box)->create(['price' => 16000, 'stripe_price_id' => 'price_box', 'quantity' => 5]);
    DropItem::factory()->for($drop)->for($delivery)->withoutStock()->create(['price' => 1500, 'stripe_price_id' => 'price_delivery']);

    return $drop;
}

describe('creating and editing drops', function (): void {
    it('creates a draft with items, reading Melbourne wall-clock times', function (): void {
        $this->server->tool(CreateDropTool::class, [
            'name' => 'November drop',
            'opens_at' => '2027-11-13T09:00:00',
            'delivery_days' => ['2027-11-20', '2027-11-19', '2027-11-20'],
            'items' => [
                ['product_id' => $this->box->id, 'stripe_price_id' => 'price_box', 'quantity' => 8],
                ['product_id' => $this->delivery->id, 'stripe_price_id' => 'price_delivery'],
            ],
        ])->assertOk()->assertSee('"created":true')->assertSee('"status":"draft"');

        $drop = Drop::query()->sole();

        expect($drop->opens_at->toIso8601String())->toBe('2027-11-12T22:00:00+00:00')
            ->and($drop->published_at)->toBeNull()
            ->and($drop->delivery_days)->toBe(['2027-11-19', '2027-11-20'])
            ->and($drop->items)->toHaveCount(2)
            ->and($drop->items->where('product_id', $this->box->id)->sole()->available)->toBe(8);
    });

    it('creates nothing when one item is wrong', function (): void {
        $this->server->tool(CreateDropTool::class, [
            'name' => 'Bad drop',
            'opens_at' => '2027-11-13T09:00:00',
            'items' => [['product_id' => $this->box->id, 'stripe_price_id' => 'price_wrong', 'price_cents' => 16000, 'quantity' => 8]],
        ])->assertHasErrors();

        expect(Drop::query()->count())->toBe(0);
    });

    it('refuses a time that daylight saving skips', function (): void {
        $this->server->tool(CreateDropTool::class, ['name' => 'Skipped', 'opens_at' => '2027-10-03T02:30:00'])->assertHasErrors();

        expect(Drop::query()->count())->toBe(0);
    });

    it('refuses closing before opening', function (): void {
        $this->server->tool(CreateDropTool::class, ['name' => 'Backwards', 'opens_at' => '2027-11-13T09:00:00', 'closes_at' => '2027-11-13T08:00:00'])
            ->assertHasErrors(['Orders must close after they open.']);
    });

    it('updates only what it is given, and can clear the closing time', function (): void {
        $drop = Drop::factory()->draft()->create(['name' => 'Old', 'closes_at' => now()->addWeeks(2)]);

        $this->server->tool(UpdateDropTool::class, ['drop_id' => $drop->id, 'name' => 'New'])->assertOk();
        expect($drop->refresh()->name)->toBe('New')->and($drop->closes_at)->not->toBeNull();

        $this->server->tool(UpdateDropTool::class, ['drop_id' => $drop->id, 'clear_closes_at' => true])->assertOk();
        expect($drop->refresh()->closes_at)->toBeNull();

        $this->server->tool(UpdateDropTool::class, ['drop_id' => $drop->id])->assertHasErrors();
    });

    it('will not move a published drop on top of another', function (): void {
        Drop::factory()->create(['name' => 'Existing', 'opens_at' => now()->addDays(10), 'closes_at' => now()->addDays(12)]);
        $other = Drop::factory()->create(['opens_at' => now()->addDays(20)]);

        $this->server->tool(UpdateDropTool::class, ['drop_id' => $other->id, 'opens_at' => now()->addDays(11)->setTimezone('Australia/Melbourne')->format('Y-m-d\TH:i:s')])
            ->assertHasErrors();
    });
});

describe('items and stock', function (): void {
    it('adds an item, defaulting the price from Stripe, then raises and lowers stock', function (): void {
        $drop = Drop::factory()->draft()->create();

        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id, 'stripe_price_id' => 'price_box', 'quantity' => 5])->assertOk();

        $item = DropItem::query()->sole();
        expect($item->price)->toBe(16000)->and($item->quantity)->toBe(5)->and($item->available)->toBe(5);

        $item->update(['available' => 3]);

        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id, 'quantity' => 10])->assertOk();
        expect($item->refresh()->quantity)->toBe(10)->and($item->available)->toBe(8);

        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id, 'quantity' => 1])
            ->assertHasErrors();
        expect($item->refresh()->quantity)->toBe(10);

        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id, 'quantity' => 2])->assertOk();
        expect($item->refresh()->quantity)->toBe(2)->and($item->available)->toBe(0);
    });

    it('refuses a price that does not match Stripe', function (): void {
        $drop = Drop::factory()->draft()->create();

        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id, 'stripe_price_id' => 'price_box', 'price_cents' => 15000, 'quantity' => 5])
            ->assertHasErrors();
        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id, 'stripe_price_id' => 'price_nope', 'price_cents' => 100, 'quantity' => 5])
            ->assertHasErrors();

        expect(DropItem::query()->count())->toBe(0);
    });

    it('refuses a quantity for a product with no stock of its own', function (): void {
        $drop = Drop::factory()->draft()->create();

        $this->server->tool(SetDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->delivery->id, 'stripe_price_id' => 'price_delivery', 'quantity' => 5])
            ->assertHasErrors();
    });

    it('only removes an item nobody has bought', function (): void {
        $drop = Drop::factory()->open()->create();
        $held = DropItem::factory()->for($drop)->for($this->box)->create(['price' => 16000, 'quantity' => 5]);
        $free = DropItem::factory()->for($drop)->for($this->delivery)->withoutStock()->create();
        $order = Order::factory()->for($drop)->create();
        OrderItem::factory()->for($order)->create(['drop_item_id' => $held->id]);
        $held->update(['available' => 4]);

        $this->server->tool(RemoveDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->box->id])->assertHasErrors();
        $this->server->tool(RemoveDropItemTool::class, ['drop_id' => $drop->id, 'product_id' => $this->delivery->id])->assertOk();

        expect(DropItem::query()->whereKey($held->id)->exists())->toBeTrue()->and(DropItem::query()->whereKey($free->id)->exists())->toBeFalse();
    });
});

describe('preflight, duplicate, close', function (): void {
    it('runs the pre-flight check and saves the report', function (): void {
        $drop = draftReadyToPublish($this->stripe, $this->box, $this->delivery);

        $this->server->tool(RunDropPreflightTool::class, ['drop_id' => $drop->id])->assertOk()->assertSee('"passed":true');

        expect($drop->refresh()->preflight_ran_at)->not->toBeNull();
    });

    it('duplicates a drop as a draft a week later', function (): void {
        $drop = draftReadyToPublish($this->stripe, $this->box, $this->delivery);

        $this->server->tool(DuplicateDropTool::class, ['drop_id' => $drop->id])->assertOk()->assertSee('(copy)');

        expect(Drop::query()->count())->toBe(2);
    });

    it('closes an open drop, and refuses one that is not open', function (): void {
        $open = Drop::factory()->open()->withStock()->create();
        $draft = Drop::factory()->draft()->create();

        $this->server->tool(CloseDropNowTool::class, ['drop_id' => $draft->id])->assertHasErrors();
        $this->server->tool(CloseDropNowTool::class, ['drop_id' => $open->id])->assertOk();

        expect($open->refresh()->closed_at)->not->toBeNull();
    });
});

describe('publishing', function (): void {
    it('publishes only with a token from the preview', function (): void {
        $drop = draftReadyToPublish($this->stripe, $this->box, $this->delivery);

        $this->server->tool(PublishDropTool::class, ['drop_id' => $drop->id, 'confirmation_token' => 'made-up'])->assertHasErrors();
        expect($drop->refresh()->published_at)->toBeNull();

        $token = preview($this->server, PreparePublishDropTool::class, $drop);

        $this->server->tool(PublishDropTool::class, ['drop_id' => $drop->id, 'confirmation_token' => $token])->assertOk()->assertSee('"published":true');
        expect($drop->refresh()->published_at)->not->toBeNull();

        $this->server->tool(PublishDropTool::class, ['drop_id' => $drop->id, 'confirmation_token' => $token])->assertHasErrors();
    });

    it('voids the token when the drop changes after the preview', function (): void {
        $drop = draftReadyToPublish($this->stripe, $this->box, $this->delivery);
        $token = preview($this->server, PreparePublishDropTool::class, $drop);

        $drop->items()->where('product_id', $this->box->id)->sole()->adjustQuantityTo(9);

        $this->server->tool(PublishDropTool::class, ['drop_id' => $drop->id, 'confirmation_token' => $token])->assertHasErrors();
        expect($drop->refresh()->published_at)->toBeNull();
    });

    it('gives no token while the pre-flight fails, unless the owner accepts the problems', function (): void {
        $drop = draftReadyToPublish($this->stripe, $this->box, $this->delivery);
        $drop->update(['delivery_days' => []]);

        $this->server->tool(PreparePublishDropTool::class, ['drop_id' => $drop->id])->assertOk()->assertSee('"can_publish":false')->assertSee('"confirmation_token":null');

        $this->server->tool(PreparePublishDropTool::class, ['drop_id' => $drop->id, 'ignore_preflight_problems' => true])->assertOk()->assertSee('"can_publish":true');
    });

    it('will not publish a drop with no items or one that clashes', function (): void {
        $empty = Drop::factory()->draft()->create();
        $this->server->tool(PreparePublishDropTool::class, ['drop_id' => $empty->id])->assertSee('"can_publish":false')->assertSee('Add at least one product');

        Drop::factory()->create(['opens_at' => now()->addDays(2), 'closes_at' => now()->addDays(4)]);
        $clashing = draftReadyToPublish($this->stripe, $this->box, $this->delivery);
        $this->server->tool(PreparePublishDropTool::class, ['drop_id' => $clashing->id])->assertSee('overlaps');
    });

    it('takes a scheduled drop back to a draft but not an open one', function (): void {
        $scheduled = Drop::factory()->create(['opens_at' => now()->addDays(3)]);
        $open = Drop::factory()->open()->withStock()->create(['opens_at' => now()->subHour()]);

        $this->server->tool(UnpublishDropTool::class, ['drop_id' => $open->id])->assertHasErrors();
        $this->server->tool(UnpublishDropTool::class, ['drop_id' => $scheduled->id])->assertOk();

        expect($scheduled->refresh()->published_at)->toBeNull();
    });
});

describe('deleting', function (): void {
    it('deletes a draft with a token and refuses a drop with orders', function (): void {
        $draft = Drop::factory()->draft()->create();

        $this->server->tool(DeleteDropTool::class, ['drop_id' => $draft->id, 'confirmation_token' => 'nope'])->assertHasErrors();
        expect(Drop::query()->whereKey($draft->id)->exists())->toBeTrue();

        $token = preview($this->server, PrepareDeleteDropTool::class, $draft);
        $this->server->tool(DeleteDropTool::class, ['drop_id' => $draft->id, 'confirmation_token' => $token])->assertOk();
        expect(Drop::query()->whereKey($draft->id)->exists())->toBeFalse();

        $withOrder = Drop::factory()->create(['opens_at' => now()->addDay()]);
        Order::factory()->for($withOrder)->create();

        $this->server->tool(PrepareDeleteDropTool::class, ['drop_id' => $withOrder->id])->assertSee('"can_delete":false')->assertSee('"confirmation_token":null');
    });
});

describe('orders', function (): void {
    it('marks a paid order fulfilled once', function (): void {
        $order = Order::factory()->create(['status' => OrderStatus::Paid]);

        $this->server->tool(MarkOrderFulfilledTool::class, ['order' => $order->reference()])->assertOk()->assertSee('"fulfilled":true');
        expect($order->refresh()->status)->toBe(OrderStatus::Fulfilled)->and($order->fulfilled_at)->not->toBeNull();

        $this->server->tool(MarkOrderFulfilledTool::class, ['order' => $order->reference()])->assertHasErrors();
    });

    it('will not fulfil an order that is still at checkout', function (): void {
        $order = Order::factory()->pending()->create();

        $this->server->tool(MarkOrderFulfilledTool::class, ['order' => $order->reference()])->assertHasErrors();
    });
});

/**
 * Run a prepare tool and read the confirmation token out of what the owner would be shown.
 *
 * @param  class-string<Tool>  $tool
 */
function preview(PendingTestResponse $server, string $tool, Drop $drop): string
{
    $response = $server->tool($tool, ['drop_id' => $drop->id]);
    $response->assertOk();

    $content = (new ReflectionMethod($response, 'structuredContent'))->invoke($response);

    return is_array($content) && is_string($content['confirmation_token'] ?? null) ? $content['confirmation_token'] : '';
}
