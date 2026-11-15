<?php

declare(strict_types=1);

use App\Enums\SmsStatus;
use App\Filament\Pages\Conversations;
use App\Filament\Widgets\TextsOverview;
use App\Models\ConversationState;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Fakes\FakeSmsGateway;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('lists the people who have texted in, most recent conversation first', function (): void {
    $jane = Subscriber::factory()->create(['first_name' => 'Jane']);
    $sam = Subscriber::factory()->create(['first_name' => 'Sam']);
    SmsMessage::factory()->for($jane)->reply('Is Saturday on?')->create(['created_at' => now()->subHours(2)]);
    SmsMessage::factory()->for($sam)->reply('Can I pick up Sunday?')->create(['created_at' => now()->subHour()]);
    Subscriber::factory()->create(['first_name' => 'Quiet']);

    livewire(Conversations::class)
        ->assertSeeInOrder(['Sam', 'Can I pick up Sunday?', 'Jane', 'Is Saturday on?'])
        ->assertDontSee('Quiet');
});

it('lists someone who isn’t on the wait list by their number', function (): void {
    SmsMessage::factory()->reply('Do you deliver to Creswick?')->create(['subscriber_id' => null, 'from' => '+61499999999']);

    livewire(Conversations::class)->assertSee('0499 999 999')->assertSee('Do you deliver to Creswick?');
});

it('finds a conversation by name or number', function (): void {
    SmsMessage::factory()->for(Subscriber::factory()->create(['first_name' => 'Jane', 'phone' => '+61411111111']))->reply('One')->create();
    SmsMessage::factory()->for(Subscriber::factory()->create(['first_name' => 'Sam', 'phone' => '+61422222222']))->reply('Two')->create();

    livewire(Conversations::class)->set('search', 'jan')->assertSee('One')->assertDontSee('Two')
        ->set('search', '0422')->assertSee('Two')->assertDontSee('One');
});

it('shows a person’s whole thread in order, broadcasts included, and marks their texts read', function (): void {
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

it('leaves other people’s texts unread when one conversation is opened', function (): void {
    $jane = Subscriber::factory()->create();
    $sam = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();
    $samsText = SmsMessage::factory()->for($sam)->reply()->create();

    livewire(Conversations::class)->call('open', $jane->phone);

    expect($samsText->refresh()->read_at)->toBeNull();
});

it('sends an answer and shows it in the thread', function (): void {
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

it('says so when Twilio refuses an answer, and keeps what was typed', function (): void {
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

    expect(SmsMessage::query()->where('status', SmsStatus::Failed)->count())->toBe(1);
});

it('refuses an empty answer', function (): void {
    $sms = FakeSmsGateway::swap();
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();

    livewire(Conversations::class)->call('open', $jane->phone)->set('draft', '  ')->call('send')->assertNotified('Write something to send.');

    expect($sms->sent)->toBe([]);
});

it('doesn’t offer a reply box for someone who has opted out', function (): void {
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

it('says when a text asked to stop', function (): void {
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply('Stop please')->create();

    livewire(Conversations::class)->call('open', $jane->phone)->assertSee('Asked to stop');
});

it('counts the conversations with unread texts in the menu and on the dashboard', function (): void {
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

it('filters to the conversations that still need a reply', function (): void {
    conversationWith('Waiting', theyHaveTheLastWord: true);
    conversationWith('Answered', theyHaveTheLastWord: false);

    livewire(Conversations::class)
        ->assertSee('Waiting asks')->assertSee('Answer to Answered')
        ->set('filter', 'needs_reply')
        ->assertSee('Waiting asks')->assertDontSee('Answered');
});

it('filters to unread texts and the conversations flagged to come back to', function (): void {
    conversationWith('Seen', theyHaveTheLastWord: true);
    conversationWith('Fresh', theyHaveTheLastWord: true, theirText: ['read_at' => null]);
    $later = conversationWith('Later', theyHaveTheLastWord: true);
    ConversationState::query()->create(['contact_phone' => $later->phone, 'follow_up_at' => now()]);

    livewire(Conversations::class)
        ->set('filter', 'unread')
        ->assertSee('Fresh asks')->assertSee('Later asks')->assertDontSee('Seen asks');
});

it('flags a conversation to come back to, which keeps it unread after it has been opened', function (): void {
    $person = conversationWith('Later', theyHaveTheLastWord: true);

    livewire(Conversations::class)
        ->call('open', $person->phone)
        ->call('followUp')
        ->set('phone')->set('filter', 'unread')
        ->assertSee('Later asks')
        ->call('open', $person->phone)->call('clearFollowUp')
        ->set('phone')
        ->assertDontSee('Later asks');
});

it('keeps a flag after you answer, until you tick it done', function (): void {
    $sms = FakeSmsGateway::swap();
    $person = conversationWith('Later', theyHaveTheLastWord: true);

    livewire(Conversations::class)
        ->call('open', $person->phone)
        ->call('followUp')
        ->set('draft', 'Found it: Saturday.')
        ->call('send')
        ->set('phone')
        ->set('filter', 'needs_reply')->assertDontSee('Later asks')->assertDontSee('Found it')
        ->set('filter', 'unread')->assertSee('Found it: Saturday.')
        ->call('open', $person->phone)->call('done')
        ->set('phone')
        ->assertDontSee('Found it: Saturday.');

    expect($sms->sent)->toHaveCount(1)
        ->and(ConversationState::query()->sole()->follow_up_at)->toBeNull()
        ->and(ConversationState::query()->sole()->done_at)->not->toBeNull();
});

it('takes a finished conversation out of the filters until they text again', function (): void {
    $person = conversationWith('Done', theyHaveTheLastWord: true, theirText: ['read_at' => null]);

    livewire(Conversations::class)->call('open', $person->phone)->call('done')
        ->set('phone')
        ->set('filter', 'needs_reply')->assertDontSee('Done asks')
        ->set('filter', 'unread')->assertDontSee('Done asks');

    $this->travel(1)->minute();
    SmsMessage::factory()->for($person)->reply('One more thing')->create();

    livewire(Conversations::class)->set('filter', 'needs_reply')->assertSee('One more thing');
});

it('opening a conversation doesn’t finish it', function (): void {
    $person = conversationWith('Open', theyHaveTheLastWord: true, theirText: ['read_at' => null]);

    livewire(Conversations::class)->call('open', $person->phone)->set('phone')->set('filter', 'needs_reply')->assertSee('Open asks');
});

it('puts conversations that need attention first, and a broadcast never moves or replaces anything', function (): void {
    $quiet = conversationWith('Quiet', theyHaveTheLastWord: false);
    $waiting = conversationWith('Waiting', theyHaveTheLastWord: true);
    SmsMessage::factory()->for($quiet)->create(['body' => 'Ferguson Livestock: Beef boxes drop Saturday.', 'created_at' => now()]);

    livewire(Conversations::class)
        ->assertSeeInOrder(['Waiting', 'Waiting asks', 'Quiet', 'Answer to Quiet'])
        ->assertDontSee('Beef boxes drop Saturday.');
});

it('counts flagged conversations as needing attention in the menu', function (): void {
    $later = conversationWith('Later', theyHaveTheLastWord: true);
    conversationWith('Fresh', theyHaveTheLastWord: true, theirText: ['read_at' => null]);
    ConversationState::query()->create(['contact_phone' => $later->phone, 'follow_up_at' => now()]);

    expect(Conversations::getNavigationBadge())->toBe('2');
});

it('doesn’t say someone who opted out needs a reply', function (): void {
    $sam = Subscriber::factory()->unsubscribed()->create(['first_name' => 'Sam']);
    SmsMessage::factory()->for($sam)->reply('Stop please')->create(['read_at' => now()]);

    livewire(Conversations::class)->set('filter', 'needs_reply')->assertDontSee('Stop please')
        ->set('filter', 'all')->assertSee('Stop please');
});

/**
 * What a phone's browser hands over when it signs up for pushes.
 *
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}
 */
function phonePushSignUp(
    string $endpoint = 'https://web.push.apple.com/QGuHRmBXgM',
    string $p256dh = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
    string $auth = 'tBHItJI5svbpez7KI4CCXg',
): array {
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
        'contentEncoding' => 'aes128gcm',
    ];
}

it('signs this phone up for pushes once, however often it’s saved, with its latest keys', function (): void {
    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp())
        ->assertReturned(fn (mixed $returned): bool => $returned === true)
        ->call('savePushSubscription', phonePushSignUp(auth: 'k3PbVq9Zx0RmT7yLwN2cHg'))
        ->assertReturned(fn (mixed $returned): bool => $returned === true);

    expect(PushSubscription::query()->sole())
        ->endpoint->toBe('https://web.push.apple.com/QGuHRmBXgM')
        ->auth_token->toBe('k3PbVq9Zx0RmT7yLwN2cHg')
        ->subscribable_id->toBe(auth()->id());
});

it('refuses a push sign-up with a bad address or keys', function (array $signUp): void {
    livewire(Conversations::class)
        ->call('savePushSubscription', $signUp)
        ->assertReturned(fn (mixed $returned): bool => $returned === false);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'an address that isn’t secure' => [phonePushSignUp(endpoint: 'http://web.push.apple.com/QGuHRmBXgM')],
    'not a web address' => [phonePushSignUp(endpoint: 'not a web address')],
    'a junk encryption key' => [phonePushSignUp(p256dh: 'BKey')],
    'a junk secret' => [phonePushSignUp(auth: 'not-a-secret')],
]);

it('stops pushing to this phone when notifications are turned off', function (): void {
    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp())
        ->call('removePushSubscription', 'https://web.push.apple.com/QGuHRmBXgM');

    expect(PushSubscription::query()->count())->toBe(0);
});

it('sends a test push to the admin’s phones and says when Apple has taken it', function (): void {
    fakeApplePush(201);

    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp())
        ->call('sendTestPush')
        ->assertNotified('Test sent');

    Http::assertSent(fn (Request $push): bool => $push->url() === 'https://web.push.apple.com/QGuHRmBXgM');
});

it('says when Apple refuses the test push, and what it answered', function (): void {
    fakeApplePush(403);
    Exceptions::fake();

    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp())
        ->call('sendTestPush')
        ->assertNotified(Notification::make()->title('Apple didn’t take the test')->body('Apple answered 403 (Forbidden).')->danger());

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), '403'));
});

it('says when no phone is signed up for the test push', function (): void {
    fakeApplePush();

    livewire(Conversations::class)->call('sendTestPush')->assertNotified('No phone is signed up');

    Http::assertNothingSent();
});

it('offers notifications for new texts from a menu by the heading once the push keys are set', function (): void {
    // The menu comes from a panel render hook, which only a full page load renders.
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
    $this->get(Conversations::getUrl())->assertSee('aren’t set up yet')->assertDontSee('Turn on notifications');

    config(['webpush.vapid.public_key' => 'BPublicKey']);
    $this->get(Conversations::getUrl())->assertSee('aren’t set up yet')->assertDontSee('Turn on notifications');

    config(['webpush.vapid.private_key' => 'PrivateKey']);
    $this->get(Conversations::getUrl())
        ->assertSeeInOrder(['fi-header-heading', 'Conversations', 'fl-push-menu', 'Notifications', 'Turn on notifications'], false)
        ->assertDontSee('aren’t set up yet');
});

it('keeps the app icon’s count in step as conversations are read, flagged and finished', function (): void {
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();
    SmsMessage::factory()->reply()->create(['subscriber_id' => null, 'from' => '+61499999999']);

    $page = livewire(Conversations::class);

    $page->call('open', $jane->phone)->assertDispatched('attention-count', count: 1);
    $page->call('followUp')->assertDispatched('attention-count', count: 2);
    $page->call('clearFollowUp')->assertDispatched('attention-count', count: 1);
    $page->call('followUp')->assertDispatched('attention-count', count: 2);
    $page->call('done')->assertDispatched('attention-count', count: 1);
    $page->call('refreshOpen')->assertDispatched('attention-count', count: 1);
});
