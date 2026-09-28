<?php

use App\Filament\Resources\SmsReplies\Pages\ListSmsReplies;
use App\Filament\Resources\SmsReplies\SmsReplyResource;
use App\Filament\Widgets\TextsOverview;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('lists replies newest first, without the texts that were sent', function () {
    $older = SmsMessage::factory()->reply('First')->create(['created_at' => now()->subHour()]);
    $newer = SmsMessage::factory()->reply('Second')->create();
    $sent = SmsMessage::factory()->create();

    livewire(ListSmsReplies::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$sent]);
});

it('names the subscriber who replied, or shows the number', function () {
    $jane = Subscriber::factory()->create(['first_name' => 'Jane']);
    SmsMessage::factory()->for($jane)->reply()->create();
    SmsMessage::factory()->reply()->create(['subscriber_id' => null, 'from' => '+61499999999']);

    livewire(ListSmsReplies::class)
        ->assertSee('Jane')
        ->assertSee('0499 999 999');
});

it('says when a reply opted someone out', function () {
    SmsMessage::factory()->reply('Stop please')->create();

    livewire(ListSmsReplies::class)
        ->assertSee('Asked to stop, so they were opted out.');
});

it('marks replies as read', function () {
    $reply = SmsMessage::factory()->reply()->create();

    livewire(ListSmsReplies::class)
        ->callAction(TestAction::make('markRead')->table($reply));

    expect($reply->refresh()->read_at)->not->toBeNull();
});

it('marks several replies as read at once', function () {
    $replies = SmsMessage::factory()->count(2)->reply()->create();

    livewire(ListSmsReplies::class)
        ->selectTableRecords($replies)
        ->callAction(TestAction::make('markRead')->table()->bulk());

    expect(SmsMessage::whereNull('read_at')->count())->toBe(0);
});

it('counts unread replies in the menu', function () {
    SmsMessage::factory()->count(2)->reply()->create();
    SmsMessage::factory()->reply()->create(['read_at' => now()]);
    SmsMessage::factory()->create();

    expect(SmsReplyResource::getNavigationBadge())->toBe('2');
});

it('shows the list size and unread replies on the dashboard', function () {
    Subscriber::factory()->count(3)->create();
    Subscriber::factory()->unsubscribed()->create();
    SmsMessage::factory()->reply()->create(['subscriber_id' => null]);

    livewire(TextsOverview::class)
        ->assertSeeInOrder(['Subscribed', '3', 'Unread replies', '1']);
});
