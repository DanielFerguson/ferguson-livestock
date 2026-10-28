<?php

use App\Enums\SmsStatus;
use App\Filament\Pages\Conversations;
use App\Filament\Widgets\TextsOverview;
use App\Models\ConversationState;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Tests\Fakes\FakeSmsGateway;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('lists the people who have texted in, most recent conversation first', function () {
    $jane = Subscriber::factory()->create(['first_name' => 'Jane']);
    $sam = Subscriber::factory()->create(['first_name' => 'Sam']);
    SmsMessage::factory()->for($jane)->reply('Is Saturday on?')->create(['created_at' => now()->subHours(2)]);
    SmsMessage::factory()->for($sam)->reply('Can I pick up Sunday?')->create(['created_at' => now()->subHour()]);
    Subscriber::factory()->create(['first_name' => 'Quiet']);

    livewire(Conversations::class)
        ->assertSeeInOrder(['Sam', 'Can I pick up Sunday?', 'Jane', 'Is Saturday on?'])
        ->assertDontSee('Quiet');
});

it('lists someone who isn’t on the wait list by their number', function () {
    SmsMessage::factory()->reply('Do you deliver to Creswick?')->create(['subscriber_id' => null, 'from' => '+61499999999']);

    livewire(Conversations::class)->assertSee('0499 999 999')->assertSee('Do you deliver to Creswick?');
});

it('finds a conversation by name or number', function () {
    SmsMessage::factory()->for(Subscriber::factory()->create(['first_name' => 'Jane', 'phone' => '+61411111111']))->reply('One')->create();
    SmsMessage::factory()->for(Subscriber::factory()->create(['first_name' => 'Sam', 'phone' => '+61422222222']))->reply('Two')->create();

    livewire(Conversations::class)->set('search', 'jan')->assertSee('One')->assertDontSee('Two')
        ->set('search', '0422')->assertSee('Two')->assertDontSee('One');
});

it('shows a person’s whole thread in order, broadcasts included, and marks their texts read', function () {
    $jane = Subscriber::factory()->create(['first_name' => 'Jane']);
    SmsMessage::factory()->for($jane)->create(['body' => 'Ferguson Livestock: The drop opens Saturday.', 'created_at' => now()->subHours(3)]);
    $reply = SmsMessage::factory()->for($jane)->reply('What time?')->create(['created_at' => now()->subHours(2)]);
    SmsMessage::factory()->for($jane)->create(['sms_broadcast_id' => null, 'body' => '9am sharp.', 'created_at' => now()->subHour()]);

    livewire(Conversations::class)
        ->call('open', $jane->phone)
        ->assertSeeInOrder(['The drop opens Saturday.', 'What time?', '9am sharp.'])
        ->assertSee('Broadcast');

    expect($reply->refresh()->read_at)->not->toBeNull();
});

it('leaves other people’s texts unread when one conversation is opened', function () {
    $jane = Subscriber::factory()->create();
    $sam = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();
    $samsText = SmsMessage::factory()->for($sam)->reply()->create();

    livewire(Conversations::class)->call('open', $jane->phone);

    expect($samsText->refresh()->read_at)->toBeNull();
});

it('sends an answer and shows it in the thread', function () {
    $sms = FakeSmsGateway::swap();
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply('What time?')->create();

    livewire(Conversations::class)
        ->call('open', $jane->phone)
        ->set('draft', 'Nine o’clock.')
        ->call('send')
        ->assertSet('draft', '')
        ->assertSee('Nine o\'clock.');

    expect($sms->sent)->toBe([['to' => $jane->phone, 'body' => 'Nine o\'clock.']]);
});

it('says so when Twilio refuses an answer, and keeps what was typed', function () {
    $sms = FakeSmsGateway::swap();
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();
    $sms->refuse($jane->phone, 21211);

    livewire(Conversations::class)
        ->call('open', $jane->phone)
        ->set('draft', 'Hello')
        ->call('send')
        ->assertNotified('The text didn’t send')
        ->assertSet('draft', 'Hello');

    expect(SmsMessage::where('status', SmsStatus::Failed)->count())->toBe(1);
});

it('refuses an empty answer', function () {
    $sms = FakeSmsGateway::swap();
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();

    livewire(Conversations::class)->call('open', $jane->phone)->set('draft', '  ')->call('send')->assertNotified('Write something to send.');

    expect($sms->sent)->toBe([]);
});

it('doesn’t offer a reply box for someone who has opted out', function () {
    $sms = FakeSmsGateway::swap();
    $jane = Subscriber::factory()->unsubscribed()->create();
    SmsMessage::factory()->for($jane)->reply('Stop')->create();

    livewire(Conversations::class)
        ->call('open', $jane->phone)
        ->assertSee('Opted out')
        ->assertSee('They’ve opted out of texts')
        ->assertDontSee('Send')
        ->set('draft', 'Wait!')
        ->call('send')
        ->assertNotified('They’ve opted out of texts, so you can’t text them.');

    expect($sms->sent)->toBe([]);
});

it('says when a text asked to stop', function () {
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply('Stop please')->create();

    livewire(Conversations::class)->call('open', $jane->phone)->assertSee('Asked to stop');
});

it('counts the conversations with unread texts in the menu and on the dashboard', function () {
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->count(2)->for($jane)->reply()->create();
    SmsMessage::factory()->reply()->create(['read_at' => now()]);
    SmsMessage::factory()->reply()->create(['subscriber_id' => null]);
    SmsMessage::factory()->create();

    expect(Conversations::getNavigationBadge())->toBe('2');

    livewire(TextsOverview::class)->assertSeeInOrder(['Need attention', '2']);
});

/**
 * A conversation whose last word is the person's (so it needs a reply), or yours.
 *
 * @param  array<string, mixed>  $theirText  attributes to set on their text
 */
function conversationWith(string $name, bool $theyHaveTheLastWord, array $theirText = []): Subscriber
{
    $subscriber = Subscriber::factory()->create(['first_name' => $name]);
    SmsMessage::factory()->for($subscriber)->reply("{$name} asks")->create(array_merge(['created_at' => now()->subHours(3), 'read_at' => now()], $theirText));

    if (! $theyHaveTheLastWord) {
        SmsMessage::factory()->for($subscriber)->create(['sms_broadcast_id' => null, 'body' => "Answer to {$name}", 'created_at' => now()->subHours(2)]);
    }

    return $subscriber;
}

it('filters to the conversations that still need a reply', function () {
    conversationWith('Waiting', theyHaveTheLastWord: true);
    conversationWith('Answered', theyHaveTheLastWord: false);

    livewire(Conversations::class)
        ->assertSee('Waiting asks')->assertSee('Answer to Answered')
        ->set('filter', 'needs_reply')
        ->assertSee('Waiting asks')->assertDontSee('Answered');
});

it('filters to unread texts and the conversations flagged to come back to', function () {
    conversationWith('Seen', theyHaveTheLastWord: true);
    conversationWith('Fresh', theyHaveTheLastWord: true, theirText: ['read_at' => null]);
    $later = conversationWith('Later', theyHaveTheLastWord: true);
    ConversationState::create(['contact_phone' => $later->phone, 'follow_up_at' => now()]);

    livewire(Conversations::class)
        ->set('filter', 'unread')
        ->assertSee('Fresh asks')->assertSee('Later asks')->assertDontSee('Seen asks');
});

it('flags a conversation to come back to, which keeps it unread after it has been opened', function () {
    $person = conversationWith('Later', theyHaveTheLastWord: true);

    livewire(Conversations::class)
        ->call('open', $person->phone)
        ->call('followUp')
        ->set('phone', null)->set('filter', 'unread')
        ->assertSee('Later asks')
        ->call('open', $person->phone)->call('clearFollowUp')
        ->set('phone', null)
        ->assertDontSee('Later asks');
});

it('keeps a flag after you answer, until you tick it done', function () {
    $sms = FakeSmsGateway::swap();
    $person = conversationWith('Later', theyHaveTheLastWord: true);

    livewire(Conversations::class)
        ->call('open', $person->phone)
        ->call('followUp')
        ->set('draft', 'Found it: Saturday.')
        ->call('send')
        ->set('phone', null)
        ->set('filter', 'needs_reply')->assertDontSee('Later asks')->assertDontSee('Found it')
        ->set('filter', 'unread')->assertSee('Found it: Saturday.')
        ->call('open', $person->phone)->call('done')
        ->set('phone', null)
        ->assertDontSee('Found it: Saturday.');

    expect($sms->sent)->toHaveCount(1)
        ->and(ConversationState::sole()->follow_up_at)->toBeNull()
        ->and(ConversationState::sole()->done_at)->not->toBeNull();
});

it('takes a finished conversation out of the filters until they text again', function () {
    $person = conversationWith('Done', theyHaveTheLastWord: true, theirText: ['read_at' => null]);

    livewire(Conversations::class)->call('open', $person->phone)->call('done')
        ->set('phone', null)
        ->set('filter', 'needs_reply')->assertDontSee('Done asks')
        ->set('filter', 'unread')->assertDontSee('Done asks');

    $this->travel(1)->minute();
    SmsMessage::factory()->for($person)->reply('One more thing')->create();

    livewire(Conversations::class)->set('filter', 'needs_reply')->assertSee('One more thing');
});

it('opening a conversation doesn’t finish it', function () {
    $person = conversationWith('Open', theyHaveTheLastWord: true, theirText: ['read_at' => null]);

    livewire(Conversations::class)->call('open', $person->phone)->set('phone', null)->set('filter', 'needs_reply')->assertSee('Open asks');
});

it('puts conversations that need attention first, and a broadcast never moves or replaces anything', function () {
    $quiet = conversationWith('Quiet', theyHaveTheLastWord: false);
    $waiting = conversationWith('Waiting', theyHaveTheLastWord: true);
    SmsMessage::factory()->for($quiet)->create(['body' => 'Ferguson Livestock: Beef boxes drop Saturday.', 'created_at' => now()]);

    livewire(Conversations::class)
        ->assertSeeInOrder(['Waiting', 'Waiting asks', 'Quiet', 'Answer to Quiet'])
        ->assertDontSee('Beef boxes drop Saturday.');
});

it('counts flagged conversations as needing attention in the menu', function () {
    $later = conversationWith('Later', theyHaveTheLastWord: true);
    conversationWith('Fresh', theyHaveTheLastWord: true, theirText: ['read_at' => null]);
    ConversationState::create(['contact_phone' => $later->phone, 'follow_up_at' => now()]);

    expect(Conversations::getNavigationBadge())->toBe('2');
});

it('doesn’t say someone who opted out needs a reply', function () {
    $sam = Subscriber::factory()->unsubscribed()->create(['first_name' => 'Sam']);
    SmsMessage::factory()->for($sam)->reply('Stop please')->create(['read_at' => now()]);

    livewire(Conversations::class)->set('filter', 'needs_reply')->assertDontSee('Stop please')
        ->set('filter', 'all')->assertSee('Stop please');
});
