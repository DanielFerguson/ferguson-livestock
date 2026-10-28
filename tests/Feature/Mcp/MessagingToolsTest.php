<?php

declare(strict_types=1);

use App\Enums\BroadcastStatus;
use App\Jobs\ProcessStripeEvent;
use App\Jobs\SendBroadcastMessages;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Broadcasts\CountAudienceTool;
use App\Mcp\Tools\Broadcasts\DeleteBroadcastTool;
use App\Mcp\Tools\Broadcasts\DraftBroadcastTool;
use App\Mcp\Tools\Broadcasts\DraftDropAnnouncementTool;
use App\Mcp\Tools\Broadcasts\PreviewBroadcastTool;
use App\Mcp\Tools\Broadcasts\ScheduleBroadcastTool;
use App\Mcp\Tools\Broadcasts\SendBroadcastNowTool;
use App\Mcp\Tools\Broadcasts\SendBroadcastTestTool;
use App\Mcp\Tools\Broadcasts\UnscheduleBroadcastTool;
use App\Mcp\Tools\Broadcasts\UpdateBroadcastTool;
use App\Mcp\Tools\Conversations\PrepareReplyTool;
use App\Mcp\Tools\Conversations\SendReplyTool;
use App\Mcp\Tools\Conversations\UpdateConversationTool;
use App\Mcp\Tools\Drops\CreateProductTool;
use App\Mcp\Tools\Drops\UpdateProductTool;
use App\Mcp\Tools\Stripe\RetryStripeEventTool;
use App\Mcp\Tools\Subscribers\OptOutSubscriberTool;
use App\Models\Bundle;
use App\Models\ConversationState;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\StripeEvent;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Mcp\Server\Tool;
use Tests\Fakes\FakeSmsGateway;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com', 'shop.admin_phone' => '+61400111222']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
    $this->sms = FakeSmsGateway::swap();
    Queue::fake();
    // 1pm in Melbourne, well outside quiet hours.
    $this->travelTo('2027-03-10 02:00:00');
});

/**
 * @param  class-string<Tool>  $tool
 * @param  array<string, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function content(PendingTestResponse $server, string $tool, array $arguments): array
{
    $response = $server->tool($tool, $arguments);
    $response->assertOk();

    $content = (new ReflectionMethod($response, 'structuredContent'))->invoke($response);

    return is_array($content) ? $content : [];
}

describe('drafting broadcasts', function (): void {
    it('drafts without scheduling or sending anything', function (): void {
        Subscriber::factory()->count(2)->create();

        $this->server->tool(DraftBroadcastTool::class, ['body' => 'Beef drop Saturday – 9am!'])->assertOk()->assertSee('"status":"draft"');

        $broadcast = SmsBroadcast::query()->sole();

        expect($broadcast->body)->toBe('Beef drop Saturday - 9am!')
            ->and($broadcast->scheduled_for)->toBeNull()
            ->and($broadcast->started_at)->toBeNull()
            ->and($broadcast->created_by)->not->toBeNull();
        Queue::assertNothingPushed();
        expect($this->sms->sent)->toBe([]);
    });

    it('refuses an empty or oversized message', function (): void {
        $this->server->tool(DraftBroadcastTool::class, ['body' => str_repeat('a', 641)])->assertHasErrors();
        $this->server->tool(DraftBroadcastTool::class, ['body' => '   '])->assertHasErrors();
        $this->server->tool(DraftBroadcastTool::class, ['body' => 'Hi', 'subscriber_ids' => [999]])->assertHasErrors();

        expect(SmsBroadcast::query()->count())->toBe(0);
    });

    it('edits a draft but not one that has been sent', function (): void {
        $draft = SmsBroadcast::factory()->create();
        $sent = SmsBroadcast::factory()->sent()->create();

        $this->server->tool(UpdateBroadcastTool::class, ['broadcast_id' => $draft->id, 'body' => 'New words', 'postcodes' => ['3350']])->assertOk();
        expect($draft->refresh()->body)->toBe('New words')->and($draft->postcodes())->toBe(['3350']);

        $this->server->tool(UpdateBroadcastTool::class, ['broadcast_id' => $sent->id, 'body' => 'Too late'])->assertHasErrors();
        $this->server->tool(UpdateBroadcastTool::class, ['broadcast_id' => $draft->id])->assertHasErrors();
    });

    it('drafts the standard announcement for a drop', function (): void {
        $drop = Drop::factory()->create(['opens_at' => now()->addDays(3)]);

        $this->server->tool(DraftDropAnnouncementTool::class, ['drop_id' => $drop->id])->assertOk()->assertSee('Our next beef drop opens');

        expect(SmsBroadcast::query()->sole()->drop_id)->toBe($drop->id);
    });

    it('counts an audience', function (): void {
        Subscriber::factory()->count(2)->create(['postcode' => '3350']);
        Subscriber::factory()->create(['postcode' => '3351']);
        Subscriber::factory()->unsubscribed()->create(['postcode' => '3350']);

        $this->server->tool(CountAudienceTool::class)->assertSee('"recipients":3');
        $this->server->tool(CountAudienceTool::class, ['postcodes' => ['3350']])->assertSee('"recipients":2');
    });
});

describe('sending now', function (): void {
    it('previews, then sends once with the token', function (): void {
        Subscriber::factory()->count(2)->create();
        $broadcast = SmsBroadcast::factory()->create();

        $preview = content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id]);
        $token = data_get($preview, 'send_now.confirmation_token');

        expect(data_get($preview, 'recipients'))->toBe(2)
            ->and(data_get($preview, 'message_as_sent'))->toStartWith('Ferguson Livestock: ')->toEndWith('Reply STOP to opt out')
            ->and($token)->toBeString();

        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $broadcast->id, 'confirmation_token' => 'guess'])->assertHasErrors();
        expect($broadcast->refresh()->started_at)->toBeNull();

        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $broadcast->id, 'confirmation_token' => $token])->assertOk()->assertSee('"queued":2');
        expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Sending);
        Queue::assertPushed(SendBroadcastMessages::class);

        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $broadcast->id, 'confirmation_token' => $token])->assertHasErrors();
    });

    it('voids the token when the message or the audience changes after the preview', function (): void {
        Subscriber::factory()->count(2)->create();
        $edited = SmsBroadcast::factory()->create();
        $grown = SmsBroadcast::factory()->create();

        $editedToken = data_get(content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $edited->id]), 'send_now.confirmation_token');
        $grownToken = data_get(content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $grown->id]), 'send_now.confirmation_token');

        $edited->update(['body' => 'Something else entirely']);
        Subscriber::factory()->create();

        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $edited->id, 'confirmation_token' => $editedToken])->assertHasErrors();
        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $grown->id, 'confirmation_token' => $grownToken])->assertHasErrors();

        expect($edited->refresh()->started_at)->toBeNull()->and($grown->refresh()->started_at)->toBeNull();
    });

    it('gives no token when nobody would receive it', function (): void {
        $broadcast = SmsBroadcast::factory()->create();

        $this->server->tool(PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id])
            ->assertSee('"recipients":0')
            ->assertSee('No one subscribed matches this audience.');
    });

    it('will not send during quiet hours without the owner agreeing', function (): void {
        Subscriber::factory()->create();
        $broadcast = SmsBroadcast::factory()->create();
        $this->travelTo('2027-03-10 11:00:00'); // 10pm in Melbourne

        $blocked = content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id]);
        expect(data_get($blocked, 'send_now.allowed'))->toBeFalse()->and(data_get($blocked, 'send_now.confirmation_token'))->toBeNull();

        $allowed = content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'acknowledge_quiet_hours' => true]);
        $token = data_get($allowed, 'send_now.confirmation_token');

        // The acknowledgement has to be repeated: a token for one isn't good for the other.
        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $broadcast->id, 'confirmation_token' => $token])->assertHasErrors();

        $token = data_get(content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'acknowledge_quiet_hours' => true]), 'send_now.confirmation_token');
        $this->server->tool(SendBroadcastNowTool::class, ['broadcast_id' => $broadcast->id, 'confirmation_token' => $token, 'acknowledge_quiet_hours' => true])->assertOk();
    });

    it('texts only the owner for a test', function (): void {
        Subscriber::factory()->count(3)->create();
        $broadcast = SmsBroadcast::factory()->create();

        $this->server->tool(SendBroadcastTestTool::class, ['broadcast_id' => $broadcast->id])->assertOk();
        expect($this->sms->recipients())->toBe(['+61400111222']);

        config(['shop.admin_phone' => null]);
        $this->server->tool(SendBroadcastTestTool::class, ['broadcast_id' => $broadcast->id])->assertHasErrors();
    });
});

describe('scheduling', function (): void {
    it('schedules with a token for that exact time, and can cancel', function (): void {
        Subscriber::factory()->create();
        $broadcast = SmsBroadcast::factory()->create();
        $sendAt = '2027-03-12T09:00:00';

        $token = data_get(content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'send_at' => $sendAt]), 'schedule.confirmation_token');

        $this->server->tool(ScheduleBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'send_at' => '2027-03-12T10:00:00', 'confirmation_token' => $token])->assertHasErrors();
        expect($broadcast->refresh()->scheduled_for)->toBeNull();

        $token = data_get(content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'send_at' => $sendAt]), 'schedule.confirmation_token');
        $this->server->tool(ScheduleBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'send_at' => $sendAt, 'confirmation_token' => $token])->assertOk();
        expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Scheduled)
            ->and($broadcast->scheduled_for?->toIso8601String())->toBe('2027-03-11T22:00:00+00:00');

        $this->server->tool(UnscheduleBroadcastTool::class, ['broadcast_id' => $broadcast->id])->assertOk();
        expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Draft);
        $this->server->tool(UnscheduleBroadcastTool::class, ['broadcast_id' => $broadcast->id])->assertHasErrors();
    });

    it('refuses a time in the past or in quiet hours', function (): void {
        Subscriber::factory()->create();
        $broadcast = SmsBroadcast::factory()->create();

        $past = content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'send_at' => '2027-03-09T09:00:00']);
        $quiet = content($this->server, PreviewBroadcastTool::class, ['broadcast_id' => $broadcast->id, 'send_at' => '2027-03-12T21:00:00']);

        expect(data_get($past, 'schedule.allowed'))->toBeFalse()->and(data_get($past, 'schedule.confirmation_token'))->toBeNull()
            ->and(data_get($quiet, 'schedule.allowed'))->toBeFalse()->and(data_get($quiet, 'schedule.blocker'))->toContain('8pm');
    });

    it('deletes only drafts', function (): void {
        $draft = SmsBroadcast::factory()->create();
        $scheduled = SmsBroadcast::factory()->scheduledFor(now()->addDay())->create();

        $this->server->tool(DeleteBroadcastTool::class, ['broadcast_id' => $scheduled->id])->assertHasErrors();
        $this->server->tool(DeleteBroadcastTool::class, ['broadcast_id' => $draft->id])->assertOk();

        expect(SmsBroadcast::query()->count())->toBe(1);
    });
});

describe('replies', function (): void {
    it('prepares then sends a reply once', function (): void {
        $subscriber = Subscriber::factory()->create(['phone' => '+61411111111']);
        SmsMessage::factory()->reply('Is Saturday still on?')->for($subscriber)->create();

        $prepared = content($this->server, PrepareReplyTool::class, ['phone' => '0411 111 111', 'body' => 'Yes, 9am sharp.']);
        expect(data_get($prepared, 'can_send'))->toBeTrue()->and(data_get($prepared, 'will_send'))->toBe('Yes, 9am sharp.');

        $arguments = ['phone' => '+61411111111', 'body' => 'Yes, 9am sharp.', 'confirmation_token' => data_get($prepared, 'confirmation_token')];

        $this->server->tool(SendReplyTool::class, ['phone' => '+61411111111', 'body' => 'Yes, 9am sharp.', 'confirmation_token' => 'guess'])->assertHasErrors();
        expect($this->sms->sent)->toBe([]);

        $this->server->tool(SendReplyTool::class, $arguments)->assertOk()->assertSee('"sent":true');
        expect($this->sms->sent)->toBe([['to' => '+61411111111', 'body' => 'Yes, 9am sharp.']]);

        $this->server->tool(SendReplyTool::class, $arguments)->assertHasErrors();
        expect($this->sms->sent)->toHaveCount(1);
    });

    it('voids the token if the text changes or the customer texts again', function (): void {
        $subscriber = Subscriber::factory()->create(['phone' => '+61411111111']);
        SmsMessage::factory()->reply('Question one')->for($subscriber)->create();

        $token = data_get(content($this->server, PrepareReplyTool::class, ['phone' => '+61411111111', 'body' => 'Answer']), 'confirmation_token');
        $this->server->tool(SendReplyTool::class, ['phone' => '+61411111111', 'body' => 'A different answer', 'confirmation_token' => $token])->assertHasErrors();

        $token = data_get(content($this->server, PrepareReplyTool::class, ['phone' => '+61411111111', 'body' => 'Answer']), 'confirmation_token');
        SmsMessage::factory()->reply('Actually, also...')->for($subscriber)->create();
        $this->server->tool(SendReplyTool::class, ['phone' => '+61411111111', 'body' => 'Answer', 'confirmation_token' => $token])->assertHasErrors();

        expect($this->sms->sent)->toBe([]);
    });

    it('gives no token to someone who never texted, opted out, or for an empty or long reply', function (): void {
        $never = Subscriber::factory()->create(['phone' => '+61422222222']);
        $out = Subscriber::factory()->unsubscribed()->create(['phone' => '+61433333333']);
        SmsMessage::factory()->reply('STOP')->for($out)->create();
        $talker = Subscriber::factory()->create(['phone' => '+61444444444']);
        SmsMessage::factory()->reply('Hello')->for($talker)->create();

        foreach ([[$never->phone, 'Hi'], [$out->phone, 'Hi'], [$talker->phone, str_repeat('a', 641)]] as [$phone, $body]) {
            $prepared = content($this->server, PrepareReplyTool::class, ['phone' => $phone, 'body' => $body]);

            expect(data_get($prepared, 'can_send'))->toBeFalse()->and(data_get($prepared, 'confirmation_token'))->toBeNull();
        }

        $this->server->tool(PrepareReplyTool::class, ['phone' => $talker->phone, 'body' => '   '])->assertHasErrors();
    });

    it('flags, clears and ticks off a conversation', function (): void {
        $subscriber = Subscriber::factory()->create(['phone' => '+61411111111']);
        SmsMessage::factory()->reply('Hello')->for($subscriber)->create();

        $this->server->tool(UpdateConversationTool::class, ['phone' => '0411 111 111', 'action' => 'follow_up'])->assertOk();
        expect(ConversationState::query()->sole()->follow_up_at)->not->toBeNull();

        $this->server->tool(UpdateConversationTool::class, ['phone' => '+61411111111', 'action' => 'done'])->assertOk();
        expect(ConversationState::query()->sole()->done_at)->not->toBeNull();

        $this->server->tool(UpdateConversationTool::class, ['phone' => '+61411111111', 'action' => 'read'])->assertOk();
        expect(SmsMessage::query()->whereNull('read_at')->count())->toBe(0);

        $this->server->tool(UpdateConversationTool::class, ['phone' => '+61400000000', 'action' => 'done'])->assertHasErrors();
        $this->server->tool(UpdateConversationTool::class, ['phone' => '+61411111111', 'action' => 'bogus'])->assertHasErrors();
    });
});

describe('subscribers, stripe and products', function (): void {
    it('opts a subscriber out', function (): void {
        $subscriber = Subscriber::factory()->create();

        $this->server->tool(OptOutSubscriberTool::class, ['subscriber_id' => $subscriber->id])->assertOk()->assertSee('"subscribed":false');

        expect($subscriber->refresh()->isSubscribed())->toBeFalse();
        $this->server->tool(OptOutSubscriberTool::class, ['subscriber_id' => 999])->assertHasErrors();
    });

    it('retries only an event that has not been processed', function (): void {
        StripeEvent::query()->create(['id' => 'evt_bad', 'type' => 'charge.refunded', 'payload' => [], 'received_at' => now(), 'last_error' => 'boom']);
        StripeEvent::query()->create(['id' => 'evt_ok', 'type' => 'charge.refunded', 'payload' => [], 'received_at' => now(), 'processed_at' => now()]);

        $this->server->tool(RetryStripeEventTool::class, ['event_id' => 'evt_bad'])->assertOk();
        Queue::assertPushed(ProcessStripeEvent::class, 1);

        $this->server->tool(RetryStripeEventTool::class, ['event_id' => 'evt_ok'])->assertHasErrors();
        $this->server->tool(RetryStripeEventTool::class, ['event_id' => 'evt_nope'])->assertHasErrors();
    });

    it('creates and edits products, but never changes a slug or type', function (): void {
        $this->server->tool(CreateProductTool::class, ['slug' => 'brisket', 'type' => 'extra', 'name' => 'Brisket', 'description' => 'Slow cook it.'])->assertOk();
        $product = Product::query()->where('slug', 'brisket')->sole();

        $this->server->tool(CreateProductTool::class, ['slug' => 'brisket', 'type' => 'extra', 'name' => 'Again', 'description' => 'x'])->assertHasErrors();
        $this->server->tool(CreateProductTool::class, ['slug' => 'bad box', 'type' => 'extra', 'name' => 'x', 'description' => 'x'])->assertHasErrors();
        $this->server->tool(CreateProductTool::class, ['slug' => 'mystery-box', 'type' => 'box', 'name' => 'Mystery', 'description' => 'x'])->assertHasErrors();

        $this->server->tool(UpdateProductTool::class, ['product_id' => $product->id, 'name' => 'Beef Brisket'])->assertOk();
        expect($product->refresh()->name)->toBe('Beef Brisket')->and($product->slug)->toBe('brisket');

        $this->server->tool(UpdateProductTool::class, ['product_id' => $product->id])->assertHasErrors();
    });

    it('creates a box with details, and a bigger box that is a bundle of it', function (): void {
        $this->server->tool(CreateProductTool::class, [
            'slug' => '5kg-box', 'type' => 'box', 'name' => '5kg Box', 'description' => 'Starter',
            'box_weight_kg' => 5, 'box_best_for' => 'Couples', 'box_freezer_guidance' => 'One drawer', 'box_contents' => ['Mince', 'Steak'],
        ])->assertOk();
        $small = Product::query()->where('slug', '5kg-box')->sole();

        $this->server->tool(CreateProductTool::class, [
            'slug' => '10kg-box', 'type' => 'box', 'name' => '10kg Box', 'description' => 'Family',
            'box_weight_kg' => 10, 'box_best_for' => 'Families', 'box_freezer_guidance' => 'Two drawers', 'box_contents' => ['Mince', 'Steak'],
            'bundle_of_product_id' => $small->id, 'bundle_units' => 2,
        ])->assertOk();

        $large = Product::query()->where('slug', '10kg-box')->with('bundle')->sole();

        expect(data_get($large, 'bundle.component_product_id'))->toBe($small->id)->and(data_get($large, 'bundle.units'))->toBe(2)->and($large->hasOwnStock())->toBeFalse();
    });

    it('makes an extra a bundle of another extra, and refuses the invalid shapes', function (): void {
        $mince = Product::factory()->create(['name' => '500g Mince', 'slug' => 'mince-500g']);
        $delivery = Product::factory()->delivery()->create(['slug' => 'delivery']);

        $this->server->tool(CreateProductTool::class, ['slug' => 'mince-5kg', 'type' => 'extra', 'name' => '5kg Mince Pack', 'description' => 'Ten packs.', 'bundle_of_product_id' => $mince->id, 'bundle_units' => 10])->assertOk();
        $pack = Product::query()->where('slug', 'mince-5kg')->with('bundle')->sole();
        expect(data_get($pack, 'bundle.units'))->toBe(10)->and($pack->hasOwnStock())->toBeFalse();

        // Not of the delivery fee, not of another bundle, not a delivery bundle, and no half-specified bundle.
        $this->server->tool(CreateProductTool::class, ['slug' => 'bad-1', 'type' => 'extra', 'name' => 'x', 'description' => 'x', 'bundle_of_product_id' => $delivery->id, 'bundle_units' => 2])->assertHasErrors();
        $this->server->tool(CreateProductTool::class, ['slug' => 'bad-2', 'type' => 'extra', 'name' => 'x', 'description' => 'x', 'bundle_of_product_id' => $pack->id, 'bundle_units' => 2])->assertHasErrors();
        $this->server->tool(CreateProductTool::class, ['slug' => 'bad-3', 'type' => 'delivery', 'name' => 'x', 'description' => 'x', 'bundle_of_product_id' => $mince->id, 'bundle_units' => 2])->assertHasErrors();
        $this->server->tool(CreateProductTool::class, ['slug' => 'bad-4', 'type' => 'extra', 'name' => 'x', 'description' => 'x', 'bundle_of_product_id' => $mince->id])->assertHasErrors();

        expect(Product::query()->whereIn('slug', ['bad-1', 'bad-2', 'bad-3', 'bad-4'])->count())->toBe(0);

        // The 500g mince is a component, so it cannot become a bundle itself.
        $other = Product::factory()->create(['slug' => 'other']);
        $this->server->tool(UpdateProductTool::class, ['product_id' => $mince->id, 'bundle_of_product_id' => $other->id, 'bundle_units' => 2])->assertHasErrors();
    });

    it('changes and removes a bundle, but not while it is on a drop', function (): void {
        $mince = Product::factory()->create(['slug' => 'mince-500g']);
        $pack = Product::factory()->bundleOf($mince, 10)->create(['slug' => 'mince-5kg']);

        $this->server->tool(UpdateProductTool::class, ['product_id' => $pack->id, 'bundle_units' => 12])->assertOk();
        expect(Bundle::query()->where('product_id', $pack->id)->sole()->units)->toBe(12);

        $drop = Drop::factory()->draft()->create();
        DropItem::factory()->for($drop)->for($pack)->withoutStock()->create();

        $this->server->tool(UpdateProductTool::class, ['product_id' => $pack->id, 'remove_bundle' => true])->assertHasErrors();

        $drop->delete();
        $this->server->tool(UpdateProductTool::class, ['product_id' => $pack->id, 'remove_bundle' => true])->assertOk();
        expect(Bundle::query()->where('product_id', $pack->id)->exists())->toBeFalse();
    });
});
