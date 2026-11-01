<?php

declare(strict_types=1);

use App\Enums\SmsStatus;
use App\Filament\Resources\Subscribers\Pages\EditSubscriber;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Resources\Subscribers\RelationManagers\MessagesRelationManager;
use App\Jobs\SendSignUpConfirmation;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Australia/Melbourne'));
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('finds subscribers by name, postcode or mobile number as it’s usually written', function (string $search): void {
    $jane = Subscriber::factory()->create(['first_name' => 'Jane', 'phone' => '+61412345678', 'postcode' => '3363']);
    $other = Subscriber::factory()->create(['first_name' => 'Bob', 'phone' => '+61498765432', 'postcode' => '3350']);

    livewire(ListSubscribers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$jane])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['Jane', '3363', '0412 345 678', '+61412345678']);

it('shows only the people who opted out', function (): void {
    $subscribed = Subscriber::factory()->create();
    $optedOut = Subscriber::factory()->unsubscribed()->create();

    livewire(ListSubscribers::class)
        ->filterTable('opted_out', true)
        ->assertCanSeeTableRecords([$optedOut])
        ->assertCanNotSeeTableRecords([$subscribed]);
});

it('opts someone out by hand', function (): void {
    $subscriber = Subscriber::factory()->create();

    livewire(ListSubscribers::class)
        ->callAction(TestAction::make('optOut')->table($subscriber));

    expect($subscriber->refresh()->isSubscribed())->toBeFalse();
});

it('shows where each person’s confirmation text is up to', function (): void {
    $delivered = Subscriber::factory()->create();
    SmsMessage::factory()->signUpConfirmation()->create(['subscriber_id' => $delivered->id, 'status' => SmsStatus::Delivered]);
    $failed = Subscriber::factory()->create();
    SmsMessage::factory()->signUpConfirmation()->create(['subscriber_id' => $failed->id, 'status' => SmsStatus::Failed]);
    Subscriber::factory()->create();

    livewire(ListSubscribers::class)
        ->assertTableColumnStateSet('signUpConfirmation.status', SmsStatus::Delivered, $delivered)
        ->assertTableColumnStateSet('signUpConfirmation.status', SmsStatus::Failed, $failed)
        ->assertSeeText('Delivered')
        ->assertSeeText('Failed')
        ->assertSeeText('Not sent');
});

it('sends a confirmation text by hand', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();

    livewire(ListSubscribers::class)
        ->callAction(TestAction::make('sendConfirmation')->table($subscriber))
        ->assertNotified('Confirmation text on its way');

    Queue::assertPushed(SendSignUpConfirmation::class, fn (SendSignUpConfirmation $job): bool => $job->subscriberId === $subscriber->id);
});

it('shows a text as waiting and takes the button away as soon as it’s queued', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();

    $page = livewire(ListSubscribers::class)->callAction(TestAction::make('sendConfirmation')->table($subscriber));

    $page->assertTableColumnStateSet('signUpConfirmation.status', SmsStatus::Queued, $subscriber);
    $page->assertSeeText('Waiting');
    $page->assertActionHidden(TestAction::make('sendConfirmation')->table($subscriber));
});

it('sends the confirmation text to everyone selected who can have one', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    [$first, $second] = Subscriber::factory()->count(2)->create()->all();
    $optedOut = Subscriber::factory()->unsubscribed()->create();
    $alreadySent = Subscriber::factory()->create();
    SmsMessage::factory()->signUpConfirmation()->create(['subscriber_id' => $alreadySent->id]);
    $notSelected = Subscriber::factory()->create();

    livewire(ListSubscribers::class)
        ->selectTableRecords([$first, $second, $optedOut, $alreadySent])
        ->callAction(TestAction::make('sendConfirmations')->table()->bulk())
        ->assertNotified('2 confirmation texts on their way');

    Queue::assertPushedTimes(SendSignUpConfirmation::class, 2);
    expect($first->signUpConfirmation?->status)->toBe(SmsStatus::Queued);
    expect($second->signUpConfirmation?->status)->toBe(SmsStatus::Queued);
    expect($optedOut->signUpConfirmation)->toBeNull();
    expect($notSelected->signUpConfirmation)->toBeNull();
});

it('shows who a bulk send reaches, what it costs and what it says before sending', function (): void {
    [$first, $second] = Subscriber::factory()->count(2)->create()->all();
    $optedOut = Subscriber::factory()->unsubscribed()->create();

    $page = livewire(ListSubscribers::class)
        ->selectTableRecords([$first, $second, $optedOut])
        ->mountAction(TestAction::make('sendConfirmations')->table()->bulk());

    $page->assertMountedActionModalSee(['It goes to 2 subscribers as 1 text each, about $0.16.', '1 of those selected is left out', 'on the wait list', 'Reply STOP to opt out']);
    $page->assertMountedActionModalDontSee('after 8am');
});

it('says a bulk send will wait until morning during quiet hours', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 22:00', 'Australia/Melbourne'));

    $page = livewire(ListSubscribers::class)
        ->selectTableRecords([$subscriber])
        ->mountAction(TestAction::make('sendConfirmations')->table()->bulk());

    $page->assertMountedActionModalSee('after 8am');
    $page->callMountedAction();
    $page->assertNotified('1 confirmation text queued for 8am');
});

it('says so when nobody selected can have a confirmation text', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $optedOut = Subscriber::factory()->unsubscribed()->create();

    livewire(ListSubscribers::class)
        ->selectTableRecords([$optedOut])
        ->callAction(TestAction::make('sendConfirmations')->table()->bulk())
        ->assertNotified('Nobody selected can have a confirmation text');

    Queue::assertNotPushed(SendSignUpConfirmation::class);
});

it('shows exactly what will be sent before sending it', function (): void {
    $subscriber = Subscriber::factory()->create();

    $page = livewire(ListSubscribers::class)->mountAction(TestAction::make('sendConfirmation')->table($subscriber));

    $page->assertMountedActionModalSee(['on the wait list', 'Reply STOP to opt out']);
    $page->assertMountedActionModalDontSee('after 8am');
});

it('says a confirmation text will wait until morning during quiet hours', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 22:00', 'Australia/Melbourne'));

    $page = livewire(ListSubscribers::class)->mountAction(TestAction::make('sendConfirmation')->table($subscriber));

    $page->assertMountedActionModalSee('after 8am');
    $page->callMountedAction();
    $page->assertNotified('Confirmation text queued for 8am');
});

it('offers the confirmation text only to people who can have one', function (): void {
    $waiting = Subscriber::factory()->create();
    $optedOut = Subscriber::factory()->unsubscribed()->create();
    $alreadySent = Subscriber::factory()->create();
    SmsMessage::factory()->signUpConfirmation()->create(['subscriber_id' => $alreadySent->id]);
    $failed = Subscriber::factory()->create();
    SmsMessage::factory()->signUpConfirmation()->create(['subscriber_id' => $failed->id, 'status' => SmsStatus::Failed]);
    $queued = Subscriber::factory()->create();
    SmsMessage::factory()->signUpConfirmation()->create(['subscriber_id' => $queued->id, 'status' => SmsStatus::Queued]);

    livewire(ListSubscribers::class)
        ->assertActionVisible(TestAction::make('sendConfirmation')->table($waiting))
        ->assertActionHidden(TestAction::make('sendConfirmation')->table($optedOut))
        ->assertActionHidden(TestAction::make('sendConfirmation')->table($alreadySent))
        ->assertActionHidden(TestAction::make('sendConfirmation')->table($failed))
        ->assertActionHidden(TestAction::make('sendConfirmation')->table($queued));
});

it('saves a corrected mobile number in the international format', function (): void {
    $subscriber = Subscriber::factory()->create();

    livewire(EditSubscriber::class, ['record' => $subscriber->id])
        ->fillForm(['phone' => '0412 345 678'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subscriber->refresh()->phone)->toBe('+61412345678');
});

it('only accepts an Australian mobile number that isn’t already on the list', function (string $phone, string $message): void {
    Subscriber::factory()->create(['phone' => '+61412345678']);
    $subscriber = Subscriber::factory()->create();

    livewire(EditSubscriber::class, ['record' => $subscriber->id])
        ->fillForm(['phone' => $phone])
        ->call('save')
        ->assertHasFormErrors(['phone' => $message]);
})->with([
    'landline' => ['03 5342 0000', 'Enter an Australian mobile number, like 0412 345 678.'],
    'already on the list' => ['0412 345 678', 'Someone on the list already has this number.'],
]);

it('shows the evidence of consent', function (): void {
    $subscriber = Subscriber::factory()->create(['consent_ip' => '203.0.113.9', 'consent_source' => 'website wait list']);

    livewire(EditSubscriber::class, ['record' => $subscriber->id])
        ->assertSee(Subscriber::CONSENT_WORDING)
        ->assertSee('website wait list')
        ->assertSee('203.0.113.9')
        ->assertSee('1 Oct 2026, 12:00pm');
});

it('lists the texts sent to and received from someone', function (): void {
    $subscriber = Subscriber::factory()->create();
    $sent = SmsMessage::factory()->for($subscriber)->create();
    $reply = SmsMessage::factory()->for($subscriber)->reply()->create();
    $someoneElses = SmsMessage::factory()->create();

    livewire(MessagesRelationManager::class, ['ownerRecord' => $subscriber, 'pageClass' => EditSubscriber::class])
        ->assertCanSeeTableRecords([$sent, $reply])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('downloads the list with the evidence of consent', function (): void {
    Subscriber::factory()->create(['first_name' => 'Jane', 'phone' => '+61412345678', 'postcode' => '3363', 'consent_ip' => '203.0.113.9']);

    livewire(ListSubscribers::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded('subscribers-2026-10-01.csv', implode("\n", [
            'first_name,phone,postcode,subscribed,consented_at,consent_source,consent_wording,consent_ip,unsubscribed_at',
            'Jane,+61412345678,3363,yes,"2026-10-01 12:00:00 AEST","website wait list","'.Subscriber::CONSENT_WORDING.'",203.0.113.9,',
            '',
        ]));
});

it('stops spreadsheet formulas in names from running when the list is opened', function (): void {
    Subscriber::factory()->create(['first_name' => '=HYPERLINK("https://example.com")', 'phone' => '+61412345678', 'postcode' => '3363', 'consent_ip' => null]);

    livewire(ListSubscribers::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded(content: implode("\n", [
            'first_name,phone,postcode,subscribed,consented_at,consent_source,consent_wording,consent_ip,unsubscribed_at',
            '"\'=HYPERLINK(""https://example.com"")",+61412345678,3363,yes,"2026-10-01 12:00:00 AEST","website wait list","'.Subscriber::CONSENT_WORDING.'",,',
            '',
        ]));
});

it('shows only the people Meta ads brought in, and which ad', function (): void {
    $fromAd = Subscriber::factory()->fromMetaAd('Spring wait list')->create();
    $other = Subscriber::factory()->create(['utm_source' => 'google']);
    $untagged = Subscriber::factory()->create();

    livewire(ListSubscribers::class)
        ->filterTable('from_meta_ads', true)
        ->assertCanSeeTableRecords([$fromAd])
        ->assertCanNotSeeTableRecords([$other, $untagged])
        ->assertTableColumnStateSet('utm_campaign', 'Spring wait list', $fromAd);
});
