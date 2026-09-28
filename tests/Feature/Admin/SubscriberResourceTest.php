<?php

use App\Filament\Resources\Subscribers\Pages\EditSubscriber;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Resources\Subscribers\RelationManagers\MessagesRelationManager;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Australia/Melbourne'));
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('finds subscribers by name, postcode or mobile number as it’s usually written', function (string $search) {
    $jane = Subscriber::factory()->create(['first_name' => 'Jane', 'phone' => '+61412345678', 'postcode' => '3363']);
    $other = Subscriber::factory()->create(['first_name' => 'Bob', 'phone' => '+61498765432', 'postcode' => '3350']);

    livewire(ListSubscribers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$jane])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['Jane', '3363', '0412 345 678', '+61412345678']);

it('shows only the people who opted out', function () {
    $subscribed = Subscriber::factory()->create();
    $optedOut = Subscriber::factory()->unsubscribed()->create();

    livewire(ListSubscribers::class)
        ->filterTable('opted_out', true)
        ->assertCanSeeTableRecords([$optedOut])
        ->assertCanNotSeeTableRecords([$subscribed]);
});

it('opts someone out by hand', function () {
    $subscriber = Subscriber::factory()->create();

    livewire(ListSubscribers::class)
        ->callAction(TestAction::make('optOut')->table($subscriber));

    expect($subscriber->refresh()->isSubscribed())->toBeFalse();
});

it('saves a corrected mobile number in the international format', function () {
    $subscriber = Subscriber::factory()->create();

    livewire(EditSubscriber::class, ['record' => $subscriber->id])
        ->fillForm(['phone' => '0412 345 678'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subscriber->refresh()->phone)->toBe('+61412345678');
});

it('only accepts an Australian mobile number that isn’t already on the list', function (string $phone, string $message) {
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

it('shows the evidence of consent', function () {
    $subscriber = Subscriber::factory()->create(['consent_ip' => '203.0.113.9', 'consent_source' => 'website wait list']);

    livewire(EditSubscriber::class, ['record' => $subscriber->id])
        ->assertSee(Subscriber::CONSENT_WORDING)
        ->assertSee('website wait list')
        ->assertSee('203.0.113.9')
        ->assertSee('1 Oct 2026, 12:00pm');
});

it('lists the texts sent to and received from someone', function () {
    $subscriber = Subscriber::factory()->create();
    $sent = SmsMessage::factory()->for($subscriber)->create();
    $reply = SmsMessage::factory()->for($subscriber)->reply()->create();
    $someoneElses = SmsMessage::factory()->create();

    livewire(MessagesRelationManager::class, ['ownerRecord' => $subscriber, 'pageClass' => EditSubscriber::class])
        ->assertCanSeeTableRecords([$sent, $reply])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('downloads the list with the evidence of consent', function () {
    Subscriber::factory()->create(['first_name' => 'Jane', 'phone' => '+61412345678', 'postcode' => '3363', 'consent_ip' => '203.0.113.9']);

    livewire(ListSubscribers::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded('subscribers-2026-10-01.csv', implode("\n", [
            'first_name,phone,postcode,subscribed,consented_at,consent_source,consent_wording,consent_ip,unsubscribed_at',
            'Jane,+61412345678,3363,yes,"2026-10-01 12:00:00 AEST","website wait list","'.Subscriber::CONSENT_WORDING.'",203.0.113.9,',
            '',
        ]));
});

it('stops spreadsheet formulas in names from running when the list is opened', function () {
    Subscriber::factory()->create(['first_name' => '=HYPERLINK("https://example.com")', 'phone' => '+61412345678', 'postcode' => '3363', 'consent_ip' => null]);

    livewire(ListSubscribers::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded(content: implode("\n", [
            'first_name,phone,postcode,subscribed,consented_at,consent_source,consent_wording,consent_ip,unsubscribed_at',
            '"\'=HYPERLINK(""https://example.com"")",+61412345678,3363,yes,"2026-10-01 12:00:00 AEST","website wait list","'.Subscriber::CONSENT_WORDING.'",,',
            '',
        ]));
});
