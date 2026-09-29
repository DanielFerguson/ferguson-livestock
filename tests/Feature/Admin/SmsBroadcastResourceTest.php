<?php

use App\Enums\BroadcastStatus;
use App\Enums\SmsStatus;
use App\Filament\Resources\Drops\Pages\EditDrop;
use App\Filament\Resources\SmsBroadcasts\Pages\CreateSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\EditSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\ViewSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Models\Drop;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Tests\Fakes\FakeSmsGateway;

use function Pest\Livewire\livewire;

beforeEach(function () {
    // Midday on 1 October 2026, before daylight saving starts on the 4th.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Australia/Melbourne'));
    config(['shop.admin_email' => 'daniel@example.com', 'shop.admin_phone' => '+61400000001', 'services.twilio.segment_cost' => 8]);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('saves a draft with curly quotes and dashes swapped for plain ones', function () {
    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => 'It’s back — Saturday.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $broadcast = SmsBroadcast::sole();
    expect($broadcast->body)->toBe("It's back - Saturday.")
        ->and($broadcast->postcodes())->toBe([])
        ->and($broadcast->created_by)->toBe(auth()->id())
        ->and($broadcast->status())->toBe(BroadcastStatus::Draft);
});

it('shows what subscribers receive, how many texts it takes and what it costs', function () {
    Subscriber::factory()->count(3)->create();

    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => str_repeat('a', 120)])
        ->assertSee('Ferguson Livestock: aaaa')
        ->assertSee('162 characters, so 2 texts each')
        ->assertSee('3 subscribers, about $0.48');
});

it('warns when a character halves the room in each text', function () {
    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => 'See you Saturday 👋'])
        ->assertSee('👋 isn’t in the standard text alphabet, so each text holds 70 characters instead of 160.');
});

it('warns on the broadcast’s page too', function () {
    $broadcast = SmsBroadcast::factory()->create(['body' => 'See you Saturday 👋']);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->assertSee('👋 isn’t in the standard text alphabet, so each text holds 70 characters instead of 160.');
});

it('goes only to the chosen postcodes', function () {
    Subscriber::factory()->create(['postcode' => '3350']);

    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => 'Hi', 'audience_scope' => 'postcodes', 'audience' => ['postcodes' => ['3350']]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SmsBroadcast::sole()->postcodes())->toBe(['3350']);
});

it('asks which postcodes', function () {
    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => 'Hi', 'audience_scope' => 'postcodes'])
        ->call('create')
        ->assertHasFormErrors(['audience.postcodes' => 'required']);
});

it('goes back to everyone when postcodes are no longer wanted', function () {
    $broadcast = SmsBroadcast::factory()->forPostcodes(['3350'])->create();

    livewire(EditSmsBroadcast::class, ['record' => $broadcast->id])
        ->assertFormSet(['audience_scope' => 'postcodes'])
        ->fillForm(['audience_scope' => 'everyone'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($broadcast->refresh()->postcodes())->toBe([]);
});

it('goes only to the chosen subscribers', function () {
    $tester = Subscriber::factory()->create(['first_name' => 'Daniel']);
    Subscriber::factory()->create();

    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => 'Hi', 'audience_scope' => 'subscribers', 'audience' => ['subscribers' => [$tester->id]]])
        ->assertSee('1 subscriber')
        ->call('create')
        ->assertHasNoFormErrors();

    $broadcast = SmsBroadcast::sole();
    expect($broadcast->subscriberIds())->toBe([$tester->id])
        ->and($broadcast->postcodes())->toBe([])
        ->and($broadcast->audienceLabel())->toBe('1 chosen subscriber');
});

it('asks which subscribers', function () {
    livewire(CreateSmsBroadcast::class)
        ->fillForm(['body' => 'Hi', 'audience_scope' => 'subscribers'])
        ->call('create')
        ->assertHasFormErrors(['audience.subscribers' => 'required']);
});

it('goes back to everyone when chosen subscribers are no longer wanted', function () {
    $broadcast = SmsBroadcast::factory()->forSubscribers([Subscriber::factory()->create()->id])->create();

    livewire(EditSmsBroadcast::class, ['record' => $broadcast->id])
        ->assertFormSet(['audience_scope' => 'subscribers'])
        ->fillForm(['audience_scope' => 'everyone'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($broadcast->refresh()->subscriberIds())->toBe([]);
});

it('finds subscribers to choose by name or mobile number, leaving out anyone who has opted out', function () {
    $daniel = Subscriber::factory()->create(['first_name' => 'Daniel', 'phone' => '+61412345678']);
    Subscriber::factory()->unsubscribed()->create(['first_name' => 'Danielle', 'phone' => '+61411111111']);
    Subscriber::factory()->create(['first_name' => 'Sam', 'phone' => '+61422222222']);

    livewire(CreateSmsBroadcast::class)
        ->fillForm(['audience_scope' => 'subscribers'])
        ->assertFormFieldExists('audience.subscribers', fn (Select $field): bool => $field->getSearchResults('dan') === [$daniel->id => 'Daniel (0412 345 678)']
            && array_keys($field->getSearchResults('0412 345')) === [$daniel->id]
            && $field->getSearchResults('nobody') === []);
});

it('texts a test to the admin without recording it', function () {
    $sms = FakeSmsGateway::swap();
    $broadcast = SmsBroadcast::factory()->create(['body' => 'Hi']);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('sendTest')
        ->assertNotified('Test sent to 0400 000 001');

    expect($sms->sent)->toBe([['to' => '+61400000001', 'body' => "Ferguson Livestock: Hi\nReply STOP to opt out"]])
        ->and(SmsMessage::count())->toBe(0);
});

it('explains how to get test texts when the admin’s number isn’t set', function () {
    $sms = FakeSmsGateway::swap();
    config(['shop.admin_phone' => null]);
    $broadcast = SmsBroadcast::factory()->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('sendTest')
        ->assertNotified('Add your mobile number first');

    expect($sms->sent)->toBe([]);
});

it('confirms how many people it goes to and the cost before sending', function () {
    Subscriber::factory()->count(2)->create();
    $broadcast = SmsBroadcast::factory()->create(['body' => 'Hi']);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->mountAction('sendNow')
        ->assertMountedActionModalSee('It goes to 2 subscribers as 1 text each, about $0.16.');
});

it('sends now', function () {
    $sms = FakeSmsGateway::swap();
    Subscriber::factory()->count(2)->create();
    $broadcast = SmsBroadcast::factory()->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('sendNow')
        ->assertHasNoActionErrors()
        ->assertNotified('Sending to 2 subscribers');

    expect($sms->sent)->toHaveCount(2)
        ->and($broadcast->refresh()->status())->toBe(BroadcastStatus::Sent);
});

it('won’t send when nobody matches the audience', function () {
    $broadcast = SmsBroadcast::factory()->forPostcodes(['3350'])->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('sendNow')
        ->assertNotified('Nobody to send to');

    expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Draft);
});

it('asks before sending between 8pm and 8am', function () {
    $sms = FakeSmsGateway::swap();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 21:00', 'Australia/Melbourne'));
    Subscriber::factory()->create();
    $broadcast = SmsBroadcast::factory()->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('sendNow')
        ->assertHasActionErrors(['send_during_quiet_hours' => 'accepted']);
    expect($sms->sent)->toBe([]);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('sendNow', ['send_during_quiet_hours' => true])
        ->assertHasNoActionErrors();
    expect($sms->sent)->toHaveCount(1);
});

it('schedules a broadcast in Melbourne time, across the clock change', function () {
    $broadcast = SmsBroadcast::factory()->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('schedule', ['scheduled_for' => '2026-10-10 09:00'])
        ->assertHasNoActionErrors();

    expect($broadcast->refresh()->scheduled_for?->toIso8601String())->toBe('2026-10-09T22:00:00+00:00')
        ->and($broadcast->status())->toBe(BroadcastStatus::Scheduled);
});

it('suggests sending when the drop opens', function () {
    $drop = Drop::factory()->create(['opens_at' => CarbonImmutable::parse('2026-10-10 09:00', 'Australia/Melbourne')->utc()]);
    $broadcast = SmsBroadcast::factory()->create(['drop_id' => $drop->id]);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('schedule')
        ->assertHasNoActionErrors();

    expect($broadcast->refresh()->scheduled_for?->equalTo($drop->opens_at))->toBeTrue();
});

it('won’t schedule a time that has passed', function () {
    $broadcast = SmsBroadcast::factory()->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('schedule', ['scheduled_for' => '2026-10-01 11:00'])
        ->assertHasActionErrors(['scheduled_for']);
});

it('asks before scheduling between 8pm and 8am', function () {
    $broadcast = SmsBroadcast::factory()->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('schedule', ['scheduled_for' => '2026-10-10 21:00'])
        ->assertHasActionErrors(['send_during_quiet_hours' => 'accepted']);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('schedule', ['scheduled_for' => '2026-10-10 21:00', 'send_during_quiet_hours' => true])
        ->assertHasNoActionErrors();

    expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Scheduled);
});

it('cancels a scheduled send', function () {
    $broadcast = SmsBroadcast::factory()->scheduledFor(now()->addDay())->create();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->callAction('unschedule');

    expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Draft);
});

it('can’t be changed or sent again once sending has started', function () {
    $broadcast = SmsBroadcast::factory()->sent()->create();

    $this->get(SmsBroadcastResource::getUrl('edit', ['record' => $broadcast]))->assertForbidden();

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->assertActionHidden('edit')
        ->assertActionHidden('sendNow')
        ->assertActionHidden('schedule')
        ->assertActionHidden('delete');
});

it('shows how the texts went and who got them', function () {
    $broadcast = SmsBroadcast::factory()->sent()->create();
    $delivered = SmsMessage::factory()->count(2)->for($broadcast, 'broadcast')->create(['status' => SmsStatus::Delivered]);
    $failed = SmsMessage::factory()->for($broadcast, 'broadcast')->create(['status' => SmsStatus::Failed, 'error_code' => 21211]);

    livewire(ViewSmsBroadcast::class, ['record' => $broadcast->id])
        ->assertSee('2 delivered')
        ->assertSee('1 failed');

    livewire(RecipientsRelationManager::class, ['ownerRecord' => $broadcast, 'pageClass' => ViewSmsBroadcast::class])
        ->assertCanSeeTableRecords([...$delivered, $failed]);
});

it('drafts an announcement for a drop, with its opening time and the order link', function () {
    $drop = Drop::factory()->create(['opens_at' => CarbonImmutable::parse('2026-10-10 09:00', 'Australia/Melbourne')->utc()]);

    livewire(EditDrop::class, ['record' => $drop->id])
        ->callAction('announce');

    $broadcast = SmsBroadcast::sole();
    expect($broadcast->body)->toBe('Our next beef drop opens Saturday 10 October at 9am. Order at fergusonlivestock.com.au/order')
        ->and($broadcast->drop_id)->toBe($drop->id)
        ->and($broadcast->status())->toBe(BroadcastStatus::Draft);
});
