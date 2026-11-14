# Push Notifications for Incoming Texts Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The owner adds the admin to their iPhone's home screen and gets a push notification for each new text to the shop's number, plus a warning when pushes have stopped reaching any phone.

**Architecture:** The admin becomes an installable web app (its own manifest and service worker, both scoped to `/admin`). Phones sign up for Web Push from the Conversations page and are stored with `laravel-notification-channels/webpush`. `TwilioInboundController` queues an `AdminPush` to the admin for each new text that isn't STOP or START. A banner on every admin page shows while no phone is signed up, and the admin is emailed when Apple reports the last phone's sign-up gone.

**Tech Stack:** Laravel 13, PHP 8.5, Postgres, Filament 5.9, Livewire 4, Alpine (bundled with Livewire), Vite, Pest 5, `laravel-notification-channels/webpush` ^13 (approved by the owner on 2026-11-15).

**Spec:** `docs/superpowers/specs/2026-11-15-text-push-notifications-design.md`

**Conventions to follow:** read `AGENTS.md`. PHPDoc over inline comments, curly braces always, explicit types. Owner-facing text uses curly apostrophes (’). Run tests with `php artisan test --compact <file>`. If tests fail with phantom errors, another run may be sharing the test database: use a private `DB_DATABASE` (see the memory note "Shared test database collisions"). Commit messages are plain sentences in the repo's style ("Push new texts to the admin’s phone"), no `feat:` prefix. Don't push to `main` until Task 10: pushes reach real customers.

**Traps found while planning:**
- The manifest and service worker scope must be `/admin`, not `/admin/`. The dashboard lives at `/admin`, and with `/admin/` a tap on the logo would leave the installed app.
- Push links must be relative (`Conversations::getUrl(..., isAbsolute: false)`). An absolute URL built from `APP_URL` can differ from the address the app was installed from, and iOS would open it in Safari instead of the app.
- iOS only shows the permission prompt in direct answer to a tap, so `Notification.requestPermission()` must be the first `await` in the click handler.
- The package's config defaults its database connection to `mysql` when `DB_CONNECTION` isn't set. The published config sets it to null, which means the app's own connection.
- Apple rejects pushes without a valid VAPID subject, so the config falls back to `SHOP_URL`.

---

### Task 1: Install the package, the subscriptions table and `User::admin()`

**Files:**
- Modify: `composer.json`, `composer.lock` (via composer)
- Create: `config/webpush.php` (via vendor:publish, then replaced)
- Create: `database/migrations/<timestamp>_create_push_subscriptions_table.php` (via artisan)
- Modify: `app/Models/User.php`
- Modify: `app/Actions/CheckDropBeforeItOpens.php:57`
- Modify: `.env.example`

**Step 1: Install the package**

Run: `composer require laravel-notification-channels/webpush:^13.0 --no-interaction`
Expected: installs `laravel-notification-channels/webpush` 13.x and `minishlink/web-push` 11.x.

**Step 2: Publish and replace the config**

Run: `php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag=config --no-interaction`

Replace `config/webpush.php` with:

```php
<?php

declare(strict_types=1);

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
     * The key pair that signs pushes to the admin's phones (VAPID). Generate it once per environment with
     * `php artisan webpush:vapid --show` and never change it: a new pair cuts off every phone signed up with the
     * old one. Apple refuses pushes without a real subject, so it falls back to the shop's address.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('SHOP_URL', 'https://fergusonlivestock.com.au')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /*
     * Null is the app's own database. The package's default falls back to "mysql" when DB_CONNECTION isn't set.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION'),

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
```

**Step 3: Create the migration**

Don't publish the package's migrations: its second one only widens the endpoint column for old installs. Instead run
`php artisan make:migration create_push_subscriptions_table --no-interaction` and replace the file's contents with:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per phone signed up for a push with each new text, kept by the push package.
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subscribable');
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
```

Run: `php artisan migrate --no-interaction`
Expected: `create_push_subscriptions_table ... DONE`.

**Step 4: Give `User` push subscriptions and an `admin()` lookup**

In `app/Models/User.php`, import `NotificationChannels\WebPush\HasPushSubscriptions`, add it to the `use` list
(alphabetical: `HasFactory, HasPushSubscriptions, InteractsWithAppAuthentication, ...`), and add below
`canAccessPanel()`:

```php
    /**
     * The shop owner's account, the only one allowed into the admin. Null until SHOP_ADMIN_EMAIL is set and the
     * account exists.
     */
    public static function admin(): ?self
    {
        $email = config('shop.admin_email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return self::query()->where('email', $email)->first();
    }
```

**Step 5: Use it in the pre-flight alert**

In `app/Actions/CheckDropBeforeItOpens.php`, `notifyAdmin()`, replace
`$admin = User::query()->where('email', $email)->first();` with `$admin = User::admin();`. It keeps its own
missing-email check, which it needs for the warning log and the mail fallback.

**Step 6: Add the keys to `.env.example` and generate local ones**

In `.env.example`, after the `TWILIO_*` block, add:

```dotenv
# Signs pushes to the admin's phones. Generate with `php artisan webpush:vapid --show`; never change once set.
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Run: `php artisan webpush:vapid --no-interaction`
Expected: "VAPID keys set successfully." (this writes local keys to `.env`, which isn't committed).

**Step 7: Run the pre-flight tests to check the refactor**

Run: `php artisan test --compact tests/Feature/DropPreflightTest.php tests/Feature/ScheduledPreflightTest.php`
Expected: PASS.

**Step 8: Commit**

```bash
git add composer.json composer.lock config/webpush.php database/migrations/*_create_push_subscriptions_table.php app/Models/User.php app/Actions/CheckDropBeforeItOpens.php .env.example
git commit -m "Add web push and a table of the phones that get pushes"
```

---

### Task 2: The `AdminPush` notification

**Files:**
- Create: `app/Notifications/AdminPush.php`
- Test: `tests/Feature/AdminPushTest.php`

**Step 1: Write the failing test**

Run: `php artisan make:test --pest AdminPushTest --no-interaction`, then replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\AdminPush;

it('shows who texted and what they said, opens their conversation inside the app, and counts what needs a look', function (): void {
    $jess = Subscriber::factory()->create(['first_name' => 'Jess', 'phone' => '+61411222333']);
    $text = SmsMessage::factory()->for($jess)->reply('Can I swap to Thursday pickup?')->create();
    SmsMessage::factory()->reply('Someone else')->create(['subscriber_id' => null, 'from' => '+61499999999']);

    expect(AdminPush::forText($text)->toWebPush(new User)->toArray())->toMatchArray([
        'title' => 'Jess',
        'body' => 'Can I swap to Thursday pickup?',
        'data' => ['url' => '/admin/conversations?phone=%2B61411222333', 'badge' => 2],
    ]);
});

it('names someone who isn’t on the wait list by their number', function (): void {
    $text = SmsMessage::factory()->reply('Do you deliver to Creswick?')->create(['subscriber_id' => null, 'from' => '+61412000111']);

    expect(AdminPush::forText($text)->toWebPush(new User)->toArray())->toMatchArray(['title' => '0412 000 111']);
});
```

**Step 2: Run it to make sure it fails**

Run: `php artisan test --compact tests/Feature/AdminPushTest.php`
Expected: FAIL, `Class "App\Notifications\AdminPush" not found`.

**Step 3: Write the notification**

Create `app/Notifications/AdminPush.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\Conversations;
use App\Models\SmsMessage;
use App\Sms\ConversationList;
use App\Support\AustralianMobile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A push to every phone the admin has turned notifications on for: a new text, or a test. Tapping it opens $url in
 * the installed admin app, and the app icon's badge shows how many conversations need a look. It's queued, so
 * Twilio's webhook answers straight away; the test is sent with notifyNow().
 */
class AdminPush extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body, public string $url) {}

    /**
     * Someone has just texted the shop's number. The link is relative, so the tap stays inside the installed app.
     */
    public static function forText(SmsMessage $text): self
    {
        return new self(
            title: $text->subscriber?->first_name ?? AustralianMobile::readable($text->contact_phone),
            body: $text->body,
            url: Conversations::getUrl(['phone' => $text->contact_phone], isAbsolute: false),
        );
    }

    /**
     * The "Send a test" button on Conversations.
     */
    public static function test(): self
    {
        return new self('Notifications are on', 'New texts will show up like this.', Conversations::getUrl(isAbsolute: false));
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->data(['url' => $this->url, 'badge' => ConversationList::attentionCount()])
            // Wake the phone now, and keep trying for a day if it's off or out of signal.
            ->options(['TTL' => 86400, 'urgency' => 'high']);
    }
}
```

**Step 4: Run the tests to make sure they pass**

Run: `php artisan test --compact tests/Feature/AdminPushTest.php`
Expected: PASS (2 tests).

**Step 5: Commit**

```bash
git add app/Notifications/AdminPush.php tests/Feature/AdminPushTest.php
git commit -m "Add the push the admin’s phone gets for a new text"
```

---

### Task 3: Push each new text from the Twilio webhook

**Files:**
- Modify: `app/Http/Controllers/Webhooks/TwilioInboundController.php`
- Test: `tests/Feature/Sms/TwilioWebhookTest.php`

**Step 1: Write the failing tests**

Add to the imports of `tests/Feature/Sms/TwilioWebhookTest.php`: `App\Models\User`, `App\Notifications\AdminPush`,
`Illuminate\Support\Facades\Notification`. Append:

```php
it('pushes a new text to the admin’s phone', function (): void {
    Notification::fake();
    config(['shop.admin_email' => 'daniel@example.com']);
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $jess = Subscriber::factory()->create(['first_name' => 'Jess']);

    postFromTwilio('webhooks.twilio.inbound', ['MessageSid' => 'SMreply1', 'From' => $jess->phone, 'To' => '+61400000000', 'Body' => 'Can I swap to Thursday pickup?'])
        ->assertOk();

    Notification::assertSentTo($admin, AdminPush::class, fn (AdminPush $push): bool => $push->title === 'Jess' && $push->body === 'Can I swap to Thursday pickup?');
});

it('pushes a text once when Twilio sends it again', function (): void {
    Notification::fake();
    config(['shop.admin_email' => 'daniel@example.com']);
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $data = ['MessageSid' => 'SMreply1', 'From' => '+61499999999', 'To' => '+61400000000', 'Body' => 'Hello'];

    postFromTwilio('webhooks.twilio.inbound', $data)->assertOk();
    postFromTwilio('webhooks.twilio.inbound', $data)->assertOk();

    Notification::assertSentToTimes($admin, AdminPush::class, 1);
});

it('doesn’t push STOP or START texts, which need no answer', function (string $body): void {
    Notification::fake();
    config(['shop.admin_email' => 'daniel@example.com']);
    User::factory()->create(['email' => 'daniel@example.com']);

    postFromTwilio('webhooks.twilio.inbound', ['MessageSid' => 'SMreply1', 'From' => '+61499999999', 'To' => '+61400000000', 'Body' => $body])->assertOk();

    Notification::assertNothingSent();
})->with(['Stop please', 'START']);

it('keeps a text without pushing it while there’s no admin account', function (): void {
    Notification::fake();
    config(['shop.admin_email' => 'daniel@example.com']);

    postFromTwilio('webhooks.twilio.inbound', ['MessageSid' => 'SMreply1', 'From' => '+61499999999', 'To' => '+61400000000', 'Body' => 'Hello'])->assertOk();

    expect(SmsMessage::query()->count())->toBe(1);
    Notification::assertNothingSent();
});
```

**Step 2: Run them to make sure the new ones fail**

Run: `php artisan test --compact tests/Feature/Sms/TwilioWebhookTest.php`
Expected: the two "pushes" tests FAIL ("The expected [App\Notifications\AdminPush] notification was not sent."). The others pass.

**Step 3: Push from the controller**

In `TwilioInboundController::__invoke()`, keep the stored text and push it when it's new and not a keyword. Replace
from `SmsMessage::query()->createOrFirst(` down to the end of the `if`/`elseif` block with:

```php
        // Twilio sends a reply again if we're slow to answer, so each one is stored once, by its ID.
        $text = SmsMessage::query()->createOrFirst(['twilio_sid' => $request->string('MessageSid')->value()], [
            'subscriber_id' => $subscriber?->id,
            'direction' => SmsDirection::Inbound,
            'to' => $request->string('To')->value(),
            'from' => $from,
            'body' => $body,
            'status' => SmsStatus::Received,
            'segments' => $request->integer('NumSegments', 1),
        ]);

        if (OptOutKeywords::matches($body)) {
            $subscriber?->optOut();
        } elseif (OptInKeywords::matches($body)) {
            $subscriber?->optBackIn();
        } elseif ($text->wasRecentlyCreated) {
            User::admin()?->notify(AdminPush::forText($text));
        }
```

Import `App\Models\User` and `App\Notifications\AdminPush`. Update the class docblock to:

```php
/**
 * Replies to the shop's number arrive here. Replies asking to stop opt the sender out straight away, and START
 * opts someone back in. Every other new text is pushed to the admin's phone.
 */
```

**Step 4: Run the tests to make sure they pass**

Run: `php artisan test --compact tests/Feature/Sms/TwilioWebhookTest.php`
Expected: PASS.

**Step 5: Commit**

```bash
git add app/Http/Controllers/Webhooks/TwilioInboundController.php tests/Feature/Sms/TwilioWebhookTest.php
git commit -m "Push each new text to the admin’s phone, but not STOP or START"
```

---

### Task 4: Sign a phone up from Conversations

**Files:**
- Modify: `app/Filament/Pages/Conversations.php`
- Modify: `resources/views/filament/pages/conversations.blade.php`
- Test: `tests/Feature/Admin/ConversationsTest.php`

**Step 1: Write the failing tests**

Add to the imports of `tests/Feature/Admin/ConversationsTest.php`: `App\Notifications\AdminPush`,
`Illuminate\Support\Facades\Notification`, `NotificationChannels\WebPush\PushSubscription`. Append:

```php
/**
 * What a phone's browser hands over when it signs up for pushes.
 *
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}
 */
function phonePushSignUp(string $endpoint = 'https://web.push.apple.com/QGuHRmBXgM'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        'contentEncoding' => 'aes128gcm',
    ];
}

it('signs this phone up for pushes, once however often it’s saved', function (): void {
    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp())
        ->assertHasNoErrors()
        ->call('savePushSubscription', phonePushSignUp());

    expect(PushSubscription::query()->sole())
        ->endpoint->toBe('https://web.push.apple.com/QGuHRmBXgM')
        ->auth_token->toBe('tBHItJI5svbpez7KI4CCXg')
        ->subscribable_id->toBe(auth()->id());
});

it('refuses a push address that isn’t a secure web address', function (string $endpoint): void {
    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp($endpoint))
        ->assertHasErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
})->with(['http://web.push.apple.com/QGuHRmBXgM', 'not a web address']);

it('stops pushing to this phone when notifications are turned off', function (): void {
    livewire(Conversations::class)
        ->call('savePushSubscription', phonePushSignUp())
        ->call('removePushSubscription', 'https://web.push.apple.com/QGuHRmBXgM');

    expect(PushSubscription::query()->count())->toBe(0);
});

it('sends a test push to the admin’s phones straight away', function (): void {
    Notification::fake();

    livewire(Conversations::class)->call('sendTestPush')->assertNotified('Test sent');

    Notification::assertSentTo(auth()->user(), AdminPush::class, fn (AdminPush $push): bool => $push->title === 'Notifications are on');
});

it('offers notifications for new texts once the push keys are set', function (): void {
    config(['webpush.vapid.public_key' => null]);
    livewire(Conversations::class)->assertSee('aren’t set up yet')->assertDontSee('Turn on notifications');

    config(['webpush.vapid.public_key' => 'BPublicKey']);
    livewire(Conversations::class)->assertSee('Turn on notifications')->assertDontSee('aren’t set up yet');
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Admin/ConversationsTest.php`
Expected: the five new tests FAIL (unknown methods, missing text).

**Step 3: Add the Livewire methods**

In `app/Filament/Pages/Conversations.php`, add the imports `App\Models\User`, `App\Notifications\AdminPush`,
`Filament\Facades\Filament`, `Illuminate\Support\Facades\Validator`, `Illuminate\Validation\Rule`,
`Minishlink\WebPush\ContentEncoding` and `NotificationChannels\WebPush\PushSubscription`. Add these methods after
`done()`:

```php
    /**
     * The key a phone signs up for pushes with. Null until the site's push keys are set.
     */
    public function pushPublicKey(): ?string
    {
        $key = config('webpush.vapid.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Sign this phone up for a push with each new text. The browser hands over where to send them and the keys to
     * encrypt them with. Saving the same phone again refreshes its keys.
     *
     * @param  array<mixed>  $subscription
     */
    public function savePushSubscription(array $subscription): bool
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string} $valid */
        $valid = Validator::make($subscription, [
            'endpoint' => ['required', 'string', 'url:https', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['required', Rule::enum(ContentEncoding::class)],
        ])->validate();

        $this->admin()->updatePushSubscription($valid['endpoint'], $valid['keys']['p256dh'], $valid['keys']['auth'], $valid['contentEncoding']);

        return true;
    }

    public function removePushSubscription(string $endpoint): void
    {
        $this->admin()->deletePushSubscription($endpoint);
    }

    public function sendTestPush(): void
    {
        $this->admin()->notifyNow(AdminPush::test());

        Notification::make()->title('Test sent')->body('It should show up on your phone in a few seconds. If it doesn’t, turn notifications off and on again.')->success()->send();
    }
```

And a private helper at the bottom of the class:

```php
    private function admin(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
```

**Step 4: Add the control to the view**

In `resources/views/filament/pages/conversations.blade.php`:

1. In the top `@php` block, add `$pushKey = $this->pushPublicKey();`.
2. In the `<style>` block, add:

```css
        .chat-push { margin-bottom: 1rem; color: rgb(107 114 128); font-size: 0.875rem; }
        .chat-push-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; }
        .chat-push [x-cloak] { display: none !important; }
```

3. Directly before `<div class="chat" wire:poll.10s="refreshOpen">`, add:

```blade
    {{-- Each phone signs itself up, so what it shows depends on the phone: resources/js/admin-push.js runs it. --}}
    <div class="chat-push" wire:ignore>
        @if ($pushKey === null)
            <span>Notifications for new texts aren’t set up yet: the site needs VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.</span>
        @else
            <div class="chat-push-row" x-data="pushNotifications(@js($pushKey))">
                <span x-show="state === 'checking' || state === 'busy'">One moment…</span>
                <span x-show="state === 'unsupported'" x-cloak>To get a notification for each new text, open this page in Safari on your iPhone, tap Share, then Add to Home Screen, and use Ferguson Livestock from your home screen.</span>
                <span x-show="state === 'denied'" x-cloak>Notifications are blocked on this phone. Turn them on in Settings → Notifications → Ferguson Livestock.</span>
                <span x-show="state === 'off'" x-cloak>This phone doesn’t get notifications for new texts.</span>
                <span x-show="state === 'on'" x-cloak>This phone gets a notification for each new text.</span>
                <span x-show="state === 'failed'" x-cloak>That didn’t work. Try again in a moment.</span>
                <x-filament::button size="sm" icon="heroicon-o-bell" x-show="state === 'off' || state === 'failed'" x-cloak x-on:click="turnOn">Turn on notifications</x-filament::button>
                <x-filament::button size="sm" color="gray" x-show="state === 'on'" x-cloak x-on:click="sendTest">Send a test</x-filament::button>
                <x-filament::button size="sm" color="gray" x-show="state === 'on'" x-cloak x-on:click="turnOff">Turn off</x-filament::button>
            </div>
        @endif
    </div>
```

`pushNotifications` is defined in Task 7. Feature tests don't run JavaScript, so they pass without it.

**Step 5: Run the tests to make sure they pass**

Run: `php artisan test --compact tests/Feature/Admin/ConversationsTest.php`
Expected: PASS.

**Step 6: Commit**

```bash
git add app/Filament/Pages/Conversations.php resources/views/filament/pages/conversations.blade.php tests/Feature/Admin/ConversationsTest.php
git commit -m "Let a phone sign up for pushes from Conversations"
```

---

### Task 5: Keep the app icon's badge in step

**Files:**
- Modify: `app/Filament/Pages/Conversations.php`
- Test: `tests/Feature/Admin/ConversationsTest.php`

**Step 1: Write the failing test**

Append to `tests/Feature/Admin/ConversationsTest.php`:

```php
it('keeps the app icon’s count in step as conversations are read, flagged and finished', function (): void {
    $jane = Subscriber::factory()->create();
    SmsMessage::factory()->for($jane)->reply()->create();
    SmsMessage::factory()->reply()->create(['subscriber_id' => null, 'from' => '+61499999999']);

    livewire(Conversations::class)
        ->call('open', $jane->phone)->assertDispatched('attention-count', count: 1)
        ->call('followUp')->assertDispatched('attention-count', count: 2)
        ->call('done')->assertDispatched('attention-count', count: 1)
        ->call('refreshOpen')->assertDispatched('attention-count', count: 1);
});
```

**Step 2: Run it to make sure it fails**

Run: `php artisan test --compact tests/Feature/Admin/ConversationsTest.php --filter="app icon"`
Expected: FAIL ("Failed asserting that an event [attention-count] was fired").

**Step 3: Dispatch the count**

In `Conversations.php`, add a private method:

```php
    /**
     * Tell the installed app how many conversations need a look, for the badge on its icon.
     */
    private function updateBadge(): void
    {
        $this->dispatch('attention-count', count: ConversationList::attentionCount());
    }
```

Call `$this->updateBadge();` as the last line of `open()`, `refreshOpen()`, `followUp()`, `clearFollowUp()` and
`done()`, outside their `if` blocks.

**Step 4: Run the Conversations tests**

Run: `php artisan test --compact tests/Feature/Admin/ConversationsTest.php`
Expected: PASS.

**Step 5: Commit**

```bash
git add app/Filament/Pages/Conversations.php tests/Feature/Admin/ConversationsTest.php
git commit -m "Keep the admin app’s badge in step with the conversations that need a look"
```

---

### Task 6: Make the admin installable, and warn while no phone gets pushes

**Files:**
- Create: `public/admin.webmanifest`
- Create: `resources/views/filament/admin/head.blade.php`
- Create: `resources/views/filament/admin/push-warning.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `resources/css/filament/admin/theme.css`
- Test: `tests/Feature/Admin/AdminAppTest.php`

**Step 1: Write the failing tests**

Run: `php artisan make:test --pest Admin/AdminAppTest --no-interaction`, then replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Models\SmsMessage;
use App\Models\User;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com', 'webpush.vapid.public_key' => 'BPublicKey']));

it('lets the admin be added to a phone’s home screen as its own app', function (): void {
    $this->get('/admin/login')
        ->assertSee('<link rel="manifest" href="/admin.webmanifest">', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Ferguson Livestock">', false)
        ->assertDontSee('admin-attention-count', false);
});

it('keeps the whole admin inside the installed app, the dashboard at /admin included', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('admin.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray([
        'name' => 'Ferguson Livestock',
        'short_name' => 'Ferguson Livestock',
        'scope' => '/admin',
        'start_url' => '/admin/conversations',
        'display' => 'standalone',
    ]);
});

it('gives the app icon the number of conversations that need a look', function (): void {
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);
    SmsMessage::factory()->count(2)->reply()->create(['subscriber_id' => null]);

    $this->actingAs($admin)->get('/admin')->assertSee('<meta name="admin-attention-count" content="2">', false);
});

it('warns on every admin page while no phone gets pushes for new texts', function (): void {
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $this->actingAs($admin)->get('/admin')->assertSee('No phone gets a notification for new texts');

    $admin->updatePushSubscription('https://web.push.apple.com/QGuHRmBXgM', 'BKey', 'authSecret', 'aes128gcm');

    $this->actingAs($admin)->get('/admin')->assertDontSee('No phone gets a notification for new texts');
});

it('doesn’t warn before the push keys are set', function (): void {
    config(['webpush.vapid.public_key' => null]);
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $this->actingAs($admin)->get('/admin')->assertDontSee('No phone gets a notification for new texts');
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Admin/AdminAppTest.php`
Expected: FAIL (no manifest link, no manifest file, no banner).

**Step 3: Add the manifest**

Create `public/admin.webmanifest`. The scope has no trailing slash, so `/admin` (the dashboard) stays inside the app:

```json
{
  "id": "/admin",
  "name": "Ferguson Livestock",
  "short_name": "Ferguson Livestock",
  "description": "The shop’s admin: orders, drops and texts.",
  "start_url": "/admin/conversations",
  "scope": "/admin",
  "display": "standalone",
  "background_color": "#f5f2eb",
  "theme_color": "#1a2e1a",
  "lang": "en-AU",
  "icons": [
    {
      "src": "/android-chrome-192x192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/android-chrome-512x512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

**Step 4: Add the head and banner views**

Create `resources/views/filament/admin/head.blade.php`:

```blade
{{-- The admin as its own app on the owner's phone: added to the Home Screen, it can get a push for each new text. --}}
<link rel="manifest" href="/admin.webmanifest">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Ferguson Livestock">
@auth
    <meta name="admin-attention-count" content="{{ \App\Sms\ConversationList::attentionCount() }}">
@endauth
@vite('resources/js/admin-push.js')
```

Create `resources/views/filament/admin/push-warning.blade.php`:

```blade
{{-- Shown on every admin page while no phone is signed up for pushes, so texts can't go unseen without anyone knowing. --}}
@php
    $admin = filament()->auth()->user();
@endphp

@if ($admin instanceof \App\Models\User && filled(config('webpush.vapid.public_key')) && $admin->pushSubscriptions()->doesntExist())
    <div class="fl-push-warning" role="status">
        No phone gets a notification for new texts, so check
        <a href="{{ \App\Filament\Pages\Conversations::getUrl() }}">Conversations</a> yourself. To turn them on, open
        Ferguson Livestock from your phone’s home screen and tap Turn on notifications in Conversations.
    </div>
@endif
```

**Step 5: Hook them into the panel**

In `AdminPanelProvider::panel()`, after the existing `->renderHook(PanelsRenderHook::HEAD_END, ...)` line, add:

```php
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): Factory|View => view('filament.admin.head'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): Factory|View => view('filament.admin.push-warning'))
```

**Step 6: Style the banner**

Append to `resources/css/filament/admin/theme.css`:

```css
/* The warning from resources/views/filament/admin/push-warning.blade.php, while no phone gets pushes for new texts. */
.fl-push-warning {
    @apply mb-6 rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-800 ring-1 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-200 dark:ring-warning-500/20;
}

.fl-push-warning a {
    @apply font-semibold underline;
}
```

**Step 7: Run the tests to make sure they pass**

Run: `php artisan test --compact tests/Feature/Admin/AdminAppTest.php tests/Feature/Admin/AdminBrandingTest.php`
Expected: PASS.

**Step 8: Commit**

```bash
git add public/admin.webmanifest resources/views/filament/admin/head.blade.php resources/views/filament/admin/push-warning.blade.php app/Providers/Filament/AdminPanelProvider.php resources/css/filament/admin/theme.css tests/Feature/Admin/AdminAppTest.php
git commit -m "Make the admin installable as its own app, and warn while no phone gets pushes"
```

---

### Task 7: The service worker and the phone-side script

No automated tests: feature tests don't run JavaScript, and Pest browser tests can't receive a real push. This task
is checked by hand in Step 5.

**Files:**
- Create: `public/admin-sw.js`
- Create: `resources/js/admin-push.js`
- Modify: `vite.config.js`

**Step 1: Write the service worker**

Create `public/admin-sw.js`. It stays at a fixed address, unhashed: browsers check it for updates themselves.

```js
/**
 * The admin app's service worker. It shows the pushes the site sends for new texts and opens the conversation when
 * one is tapped. It's registered with the scope /admin, so the shop's pages never use it.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    const push = event.data?.json() ?? {};
    const badge = Number(push.data?.badge ?? 0);
    const setBadge = 'setAppBadge' in self.navigator
        ? (badge > 0 ? self.navigator.setAppBadge(badge) : self.navigator.clearAppBadge()).catch(() => {})
        : Promise.resolve();

    // iOS stops sending pushes to a site that receives one without showing it, so every push shows.
    event.waitUntil(Promise.all([
        self.registration.showNotification(push.title ?? 'Ferguson Livestock', { body: push.body, data: push.data }),
        setBadge,
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL(event.notification.data?.url ?? '/admin/conversations', self.location.origin).href;

    event.waitUntil((async () => {
        const [open] = await self.clients.matchAll({ type: 'window' });

        if (open) {
            try {
                await open.focus();
                await open.navigate(url);

                return;
            } catch {
                // Fall through and open a fresh window.
            }
        }

        await self.clients.openWindow(url);
    })());
});
```

**Step 2: Write the page script**

Create `resources/js/admin-push.js`. It runs as a module in the admin's `<head>`, so it's in place before Livewire
starts Alpine on `DOMContentLoaded`.

```js
/**
 * The admin as an app on the owner's phone. Registers the service worker that shows pushes, keeps the app icon's
 * badge in step with the conversations that need a look, and runs the Notifications control on Conversations.
 */

const worker = 'serviceWorker' in navigator
    ? navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin' }).then(() => navigator.serviceWorker.ready)
    : Promise.reject(new Error('This browser has no service workers.'));

// The control reports a browser without service workers itself, so the rejection needs no other handling.
worker.catch(() => {});

function setBadge(count) {
    if (!('setAppBadge' in navigator)) {
        return;
    }

    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
}

const attentionCount = document.querySelector('meta[name="admin-attention-count"]');

if (attentionCount !== null) {
    setBadge(Number(attentionCount.content));
}

// Sent by the Conversations page whenever reading, flagging or finishing a conversation changes the count.
window.addEventListener('attention-count', (event) => setBadge(Number(event.detail.count)));

function supportsPush() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * The public VAPID key as the bytes the Push API wants: it arrives as base64url text.
 */
function keyBytes(base64url) {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}

/**
 * What the site needs to send this phone a push: where to, the keys to encrypt with, and how.
 */
function describe(subscription) {
    const encodings = PushManager.supportedContentEncodings ?? ['aes128gcm'];

    return { ...subscription.toJSON(), contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : encodings[0] };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pushNotifications', (publicKey) => ({
        // checking, busy, unsupported, denied, off, on or failed
        state: 'checking',

        async init() {
            if (!supportsPush()) {
                this.state = 'unsupported';

                return;
            }

            if (Notification.permission === 'denied') {
                this.state = 'denied';

                return;
            }

            try {
                const subscription = await (await worker).pushManager.getSubscription();

                if (subscription === null) {
                    this.state = 'off';

                    return;
                }

                // Saved again on every visit, so the site always has this phone's latest address.
                this.state = (await this.$wire.savePushSubscription(describe(subscription))) === true ? 'on' : 'failed';
            } catch {
                this.state = 'unsupported';
            }
        },

        async turnOn() {
            this.state = 'busy';

            // Asked first, while the tap still counts: iOS only shows the prompt in direct answer to one.
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                this.state = permission === 'denied' ? 'denied' : 'off';

                return;
            }

            try {
                const subscription = await (await worker).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });

                this.state = (await this.$wire.savePushSubscription(describe(subscription))) === true ? 'on' : 'failed';
            } catch {
                this.state = 'failed';
            }
        },

        async turnOff() {
            this.state = 'busy';

            try {
                const subscription = await (await worker).pushManager.getSubscription();

                if (subscription !== null) {
                    await this.$wire.removePushSubscription(subscription.endpoint);
                    await subscription.unsubscribe();
                }

                this.state = 'off';
            } catch {
                this.state = 'failed';
            }
        },

        sendTest() {
            this.$wire.sendTestPush();
        },
    }));
});
```

**Step 3: Add it to Vite**

In `vite.config.js`, add `'resources/js/admin-push.js'` to `input` after `'resources/js/order-form.js'`, and update
the comment above it to:

```js
            // The live stock script runs on every page; the order form's script only on /order. The theme and the
            // push script are the admin panel's.
```

**Step 4: Build**

Run: `npm run build`
Expected: builds with no errors, and `public/build/manifest.json` lists `resources/js/admin-push.js`.

**Step 5: Check it by hand in a desktop browser**

Desktop Chrome and Safari support Web Push on `localhost`, so the whole flow can be checked before it reaches the phone:

1. Start the app (`composer run dev`, or ask the user how they run it) and get the admin's address with Boost's `get-absolute-url`.
2. Sign in and open Conversations. The control should say "This phone doesn’t get notifications for new texts" with **Turn on notifications**, and the yellow banner should show.
3. Tap **Turn on notifications** and allow. The control says "This phone gets a notification…", and after a reload the banner is gone.
4. Tap **Send a test**. A "Notifications are on" notification appears, and clicking it opens Conversations.
5. Tap **Turn off**. The control goes back to off, and the banner returns on reload.
6. Check `browser-logs` for JavaScript errors.

If any step fails, stop and debug (superpowers:systematic-debugging) before committing.

**Step 6: Commit**

```bash
git add public/admin-sw.js resources/js/admin-push.js vite.config.js
git commit -m "Show pushes on the phone and run the notifications control"
```

---

### Task 8: Email the admin when the last phone's sign-up lapses

**Files:**
- Create: `app/Notifications/PushesStopped.php`
- Create: `app/Listeners/WarnWhenPushesStop.php`
- Test: `tests/Feature/PushesStoppedTest.php`

The push package deletes a sign-up when Apple answers 404 or 410, then fires `NotificationFailed`. Laravel discovers
listeners in `app/Listeners` by their `handle()` type hint, the same way `AlertAdminOfFailedJob` is found.

**Step 1: Write the failing tests**

Run: `php artisan make:test --pest PushesStoppedTest --no-interaction`, then replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\PushesStopped;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushMessage;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com']));

/**
 * What the push package reports when Apple answers a push with this status. Like the package, it deletes the
 * sign-up first when Apple says it's gone.
 */
function failedPush(PushSubscription $subscription, int $status): NotificationFailed
{
    $report = new MessageSentReport(new Request('POST', $subscription->endpoint), new Response($status), false, 'Apple said no');

    if ($report->isSubscriptionExpired()) {
        $subscription->delete();
    }

    return new NotificationFailed($report, $subscription, new WebPushMessage);
}

it('emails the admin when the last phone signed up for pushes isn’t any more', function (int $status): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    event(failedPush($phone, $status));

    Notification::assertSentTo($admin, PushesStopped::class);
})->with([404, 410]);

it('stays quiet while another phone still gets pushes', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $oldPhone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');
    $admin->updatePushSubscription('https://web.push.apple.com/new-phone', 'BKey', 'authSecret', 'aes128gcm');

    event(failedPush($oldPhone, 410));

    Notification::assertNothingSent();
});

it('stays quiet when a push fails for another reason, since the phone is still signed up', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    event(failedPush($phone, 500));

    Notification::assertNothingSent();
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/PushesStoppedTest.php`
Expected: FAIL, `Class "App\Notifications\PushesStopped" not found`.

**Step 3: Write the email**

Create `app/Notifications/PushesStopped.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\Conversations;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the admin no phone gets pushes for new texts any more: Apple said the last one isn't signed up. Without it,
 * texts would just stop showing up on the phone, with nothing to say so.
 */
class PushesStopped extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your phone has stopped getting notifications for new texts')
            ->greeting('Texts aren’t reaching your phone.')
            ->line('The site just tried to send your phone a notification, and Apple says it isn’t signed up any more. That happens when Ferguson Livestock is taken off the home screen, or on a new phone.')
            ->line('Until notifications are back on, check Conversations for new texts yourself.')
            ->action('Open Conversations', Conversations::getUrl())
            ->line('To turn them back on, open Ferguson Livestock from your phone’s home screen, go to Conversations and tap Turn on notifications.');
    }
}
```

**Step 4: Write the listener**

Create `app/Listeners/WarnWhenPushesStop.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\PushesStopped;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * Emails the admin when Apple says the last phone signed up for pushes isn't any more. The push package has already
 * deleted the sign-up, so this fires once per lapse. Turning notifications off on purpose doesn't count: that never
 * reaches Apple.
 */
class WarnWhenPushesStop
{
    public function handle(NotificationFailed $event): void
    {
        if (! $event->report->isSubscriptionExpired()) {
            return;
        }

        $owner = $event->subscription->subscribable;

        if ($owner instanceof User && $owner->pushSubscriptions()->doesntExist()) {
            $owner->notify(new PushesStopped);
        }
    }
}
```

**Step 5: Run the tests to make sure they pass**

Run: `php artisan test --compact tests/Feature/PushesStoppedTest.php`
Expected: PASS (4 tests).

**Step 6: Commit**

```bash
git add app/Notifications/PushesStopped.php app/Listeners/WarnWhenPushesStop.php tests/Feature/PushesStoppedTest.php
git commit -m "Email the admin when no phone gets pushes for new texts any more"
```

---

### Task 9: Document the keys, then check everything

**Files:**
- Modify: `docs/laravel-cloud.md`

**Step 1: Document the keys**

In `docs/laravel-cloud.md`, add `VAPID_PUBLIC_KEY=...` and `VAPID_PRIVATE_KEY=...` to the environment variable
block after `TWILIO_FROM_NUMBER`. After the Twilio section (the paragraph ending "Keep it out of anywhere less
trusted than production, such as preview environments."), add:

```markdown
**Push notifications for new texts:** `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` sign the pushes the admin app on the owner's phone gets for each new text. Generate a pair once with `php artisan webpush:vapid --show` and set both in production. Never change them: a new pair cuts off every phone signed up with the old one. Staging doesn't need them, since replies only reach production. Without them, Conversations says notifications aren't set up. To install the app on the phone: open the admin in Safari, then Share → Add to Home Screen. Open it from the home screen, sign in with "remember me" ticked, and tap **Turn on notifications** in Conversations. Every admin page warns while no phone is signed up, and the admin is emailed if Apple reports the last phone gone.
```

**Step 2: Format, lint and analyse**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/rector process --dry-run --no-progress-bar`
Run: `composer analyse`
Expected: no rector changes and no PHPStan errors. Fix anything reported in the files this plan touched.

**Step 3: Run every affected test file**

Run: `php artisan test --compact tests/Feature/AdminPushTest.php tests/Feature/PushesStoppedTest.php tests/Feature/Sms/TwilioWebhookTest.php tests/Feature/Admin/ConversationsTest.php tests/Feature/Admin/AdminAppTest.php tests/Feature/Admin/AdminBrandingTest.php tests/Feature/DropPreflightTest.php tests/Feature/ScheduledPreflightTest.php`
Expected: PASS.

**Step 4: Commit, then ask the user to run the full suite**

```bash
git add docs/laravel-cloud.md
git commit -m "Document the push keys and how to install the admin app"
```

Ask the user to run `php artisan test --compact` before anything is pushed.

---

### Task 10: Roll out (with the owner)

Pushing to `main` deploys to the live site. It's safe in either order: without keys, Conversations says notifications
aren't set up and nothing is pushed. Do it in this order anyway, so it works on the first try:

1. The owner runs `php artisan webpush:vapid --show` locally and sets `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` on the production environment in Laravel Cloud. Never paste the private key into chat or commit it.
2. Push `main` once the owner says to. The deploy runs the migration.
3. On the iPhone: open the admin in Safari, then Share → Add to Home Screen → Add. Open **Ferguson Livestock** from the home screen, sign in with "remember me" ticked, open Conversations and tap **Turn on notifications** → Allow.
4. Tap **Send a test**. It should arrive within seconds, and tapping it should open Conversations inside the app.
5. Text the shop's number from another phone. The push shows the sender and the message, and tapping it opens that conversation.
