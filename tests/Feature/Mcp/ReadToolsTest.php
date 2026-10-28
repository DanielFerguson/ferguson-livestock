<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Mcp\Prompts\DraftReplyPrompt;
use App\Mcp\Prompts\NewDropSetupPrompt;
use App\Mcp\Prompts\WeeklyBroadcastPrompt;
use App\Mcp\Resources\BusinessRulesResource;
use App\Mcp\Resources\CurrentDropResource;
use App\Mcp\Resources\SmsRulesResource;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Broadcasts\GetBroadcastTool;
use App\Mcp\Tools\Broadcasts\ListBroadcastsTool;
use App\Mcp\Tools\Conversations\GetConversationTool;
use App\Mcp\Tools\Conversations\ListConversationsTool;
use App\Mcp\Tools\Drops\GetDropTool;
use App\Mcp\Tools\Drops\ListDropsTool;
use App\Mcp\Tools\Drops\ListProductsTool;
use App\Mcp\Tools\Orders\GetDeliveryRunTool;
use App\Mcp\Tools\Orders\GetOrderTool;
use App\Mcp\Tools\Orders\ListOrdersTool;
use App\Mcp\Tools\Stripe\CheckStripeSetupTool;
use App\Mcp\Tools\Stripe\GetStripeEventTool;
use App\Mcp\Tools\Stripe\ListStripeEventsTool;
use App\Mcp\Tools\Stripe\ListStripePricesTool;
use App\Mcp\Tools\Subscribers\GetSubscriberStatsTool;
use App\Mcp\Tools\Subscribers\ListSubscribersTool;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\StripeEvent;
use App\Models\Subscriber;
use App\Models\User;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
});

describe('drops', function (): void {
    it('lists drops and filters by status', function (): void {
        Drop::factory()->open()->withStock()->create(['name' => 'Live one']);
        Drop::factory()->draft()->create(['name' => 'Draft one']);

        $this->server->tool(ListDropsTool::class)->assertOk()->assertSee('Live one')->assertSee('Draft one');
        $this->server->tool(ListDropsTool::class, ['status' => 'draft'])->assertOk()->assertSee('Draft one')->assertDontSee('Live one');
        $this->server->tool(ListDropsTool::class, ['status' => 'nonsense'])->assertHasErrors();
    });

    it('shows one drop with sales per item', function (): void {
        $stocked = StockedDrop::create(boxes: 5);
        $order = Order::factory()->for($stocked->drop)->create(['status' => OrderStatus::Paid]);
        OrderItem::factory()->for($order)->create(['drop_item_id' => $stocked->box->id, 'quantity' => 2, 'unit_price' => 16000]);

        $this->server->tool(GetDropTool::class, ['drop_id' => $stocked->drop->id])
            ->assertOk()
            ->assertSee('5kg Beef Box')
            ->assertSee('"sold":2')
            ->assertSee('"revenue":32000');
    });

    it('says so when the drop does not exist', function (): void {
        $this->server->tool(GetDropTool::class, ['drop_id' => 999])->assertHasErrors(['There is no drop with id 999. Use list-drops to find one.']);
    });

    it('lists the catalogue', function (): void {
        StockedDrop::create();

        $this->server->tool(ListProductsTool::class)->assertOk()->assertSee('10kg Beef Box')->assertSee('"type":"box"');
    });
});

describe('orders', function (): void {
    it('finds orders by reference, search and status', function (): void {
        $stocked = StockedDrop::create();
        $paid = Order::factory()->for($stocked->drop)->create(['customer_name' => 'Jane Smith', 'status' => OrderStatus::Paid]);
        Order::factory()->for($stocked->drop)->pending()->create();

        $this->server->tool(ListOrdersTool::class, ['search' => 'jane'])->assertOk()->assertSee($paid->reference());
        $this->server->tool(ListOrdersTool::class, ['status' => 'pending'])->assertOk()->assertDontSee('Jane Smith');
        $this->server->tool(GetOrderTool::class, ['order' => $paid->reference()])->assertOk()->assertSee('Jane Smith')->assertSee('stripe_checkout_session_id');
        $this->server->tool(GetOrderTool::class, ['order' => 'FL-NOSUCH'])->assertHasErrors();
    });

    it('builds the delivery run', function (): void {
        $stocked = StockedDrop::create();
        $order = Order::factory()->for($stocked->drop)->create(['customer_name' => 'Jane Smith']);
        OrderItem::factory()->for($order)->create(['drop_item_id' => $stocked->box->id, 'quantity' => 1]);

        $this->server->tool(GetDeliveryRunTool::class, ['drop_id' => $stocked->drop->id])->assertOk()->assertSee('Jane Smith')->assertSee('1 × 5kg Beef Box');
        $this->server->tool(GetDeliveryRunTool::class, ['drop_id' => $stocked->drop->id, 'format' => 'csv'])->assertOk()->assertSee('order,name,phone');
    });
});

describe('subscribers', function (): void {
    it('searches the list', function (): void {
        Subscriber::factory()->create(['first_name' => 'Alice', 'phone' => '+61411111111', 'postcode' => '3350']);
        Subscriber::factory()->unsubscribed()->create(['first_name' => 'Bob', 'phone' => '+61422222222']);

        $this->server->tool(ListSubscribersTool::class, ['search' => 'alice'])->assertOk()->assertSee('Alice')->assertDontSee('Bob');
        $this->server->tool(ListSubscribersTool::class, ['search' => '0411 111 111'])->assertOk()->assertSee('Alice');
        $this->server->tool(ListSubscribersTool::class, ['status' => 'opted_out'])->assertOk()->assertSee('Bob')->assertDontSee('Alice');
        $this->server->tool(ListSubscribersTool::class, ['postcode' => '3350'])->assertOk()->assertSee('Alice');
    });

    it('reports stats', function (): void {
        Subscriber::factory()->count(2)->create(['postcode' => '3350']);
        Subscriber::factory()->create(['postcode' => '3351']);
        Subscriber::factory()->unsubscribed()->create();

        $this->server->tool(GetSubscriberStatsTool::class)
            ->assertOk()
            ->assertSee('"subscribed":3')
            ->assertSee('"opted_out":1')
            ->assertSee('"postcode":"3350","subscribers":2');
    });
});

describe('broadcasts', function (): void {
    it('lists and shows broadcasts with failures explained', function (): void {
        $broadcast = SmsBroadcast::factory()->sent()->create(['body' => 'Drop opens Saturday']);
        SmsMessage::factory()->for($broadcast, 'broadcast')->create(['status' => 'failed', 'error_code' => 30007]);

        $this->server->tool(ListBroadcastsTool::class)->assertOk()->assertSee('Drop opens Saturday')->assertSee('"status":"sent"');
        $this->server->tool(GetBroadcastTool::class, ['broadcast_id' => $broadcast->id])
            ->assertOk()
            ->assertSee('Blocked by their phone company as spam')
            ->assertSee('Reply STOP to opt out');
        $this->server->tool(GetBroadcastTool::class, ['broadcast_id' => 999])->assertHasErrors();
    });
});

describe('conversations', function (): void {
    it('lists the inbox and shows a thread without marking it read', function (): void {
        $subscriber = Subscriber::factory()->create(['first_name' => 'Alice', 'phone' => '+61411111111']);
        SmsMessage::factory()->for($subscriber)->reply('Can I pick up instead?')->create();

        $this->server->tool(ListConversationsTool::class)->assertOk()->assertSee('Can I pick up instead?')->assertSee('"needs_reply":true');
        $this->server->tool(GetConversationTool::class, ['phone' => '0411 111 111'])->assertOk()->assertSee('Can I pick up instead?');

        expect(SmsMessage::query()->whereNull('read_at')->count())->toBe(1);

        $this->server->tool(GetConversationTool::class, ['phone' => '+61411111111', 'mark_read' => true])->assertOk();

        expect(SmsMessage::query()->whereNull('read_at')->count())->toBe(0);
    });

    it('explains when nobody with that number has texted in', function (): void {
        $this->server->tool(GetConversationTool::class, ['phone' => '0400 000 000'])->assertHasErrors();
    });
});

describe('stripe', function (): void {
    it('lists events by state and shows one with its order', function (): void {
        $order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_abc']);
        StripeEvent::query()->create(['id' => 'evt_ok', 'type' => 'checkout.session.completed', 'payload' => ['data' => ['object' => ['id' => 'cs_test_abc', 'object' => 'checkout.session', 'amount_total' => 17500]]], 'received_at' => now(), 'processed_at' => now()]);
        StripeEvent::query()->create(['id' => 'evt_bad', 'type' => 'charge.refunded', 'payload' => [], 'received_at' => now(), 'last_error' => 'No matching order']);

        $this->server->tool(ListStripeEventsTool::class, ['state' => 'failing'])->assertOk()->assertSee('evt_bad')->assertDontSee('evt_ok');
        $this->server->tool(GetStripeEventTool::class, ['event_id' => 'evt_ok'])->assertOk()->assertSee($order->reference());
        $this->server->tool(GetStripeEventTool::class, ['event_id' => 'evt_nope'])->assertHasErrors();
    });

    it('lists Stripe prices and checks the webhook set-up', function (): void {
        $gateway = FakePaymentGateway::swap();
        $gateway->addPrice('price_box', 16000, productName: '5kg Beef Box');
        $gateway->addWebhookEndpoint(rtrim(config()->string('app.url'), '/').'/api/webhooks/stripe', ['charge.refunded']);

        $this->server->tool(ListStripePricesTool::class)->assertOk()->assertSee('price_box');
        $this->server->tool(CheckStripeSetupTool::class)->assertOk()->assertSee('"webhook_ok":false')->assertSee('doesn’t send');
    });
});

describe('resources', function (): void {
    it('serves the rules and the current drop', function (): void {
        Drop::factory()->open()->withStock()->create(['name' => 'November drop']);

        $this->server->resource(SmsRulesResource::class)->assertOk()->assertSee('Reply STOP to opt out');
        $this->server->resource(BusinessRulesResource::class)->assertOk()->assertSee('Going live');
        $this->server->resource(CurrentDropResource::class)->assertOk()->assertSee('November drop');
    });
});

describe('prompts', function (): void {
    it('serves the three prompts', function (): void {
        $subscriber = Subscriber::factory()->create(['phone' => '+61411111111']);
        SmsMessage::factory()->reply('Can I collect on Sunday?')->for($subscriber)->create();

        $this->server->prompt(NewDropSetupPrompt::class, ['notes' => 'opens Saturday'])->assertOk()->assertSee('prepare-publish-drop')->assertSee('opens Saturday');
        $this->server->prompt(DraftReplyPrompt::class, ['phone' => '0411 111 111'])->assertOk()->assertSee('Can I collect on Sunday?')->assertSee('send-reply');
        $this->server->prompt(WeeklyBroadcastPrompt::class, ['topic' => 'the November drop'])->assertOk()->assertSee('the November drop')->assertSee('confirmation_token');
    });
});
