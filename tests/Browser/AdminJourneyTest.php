<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Pages\Conversations;
use App\Filament\Resources\Drops\DropResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\ConversationState;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fakes\FakeSmsGateway;

/*
|--------------------------------------------------------------------------
| What the owner does in the admin, driven in a real browser
|--------------------------------------------------------------------------
|
| The sign-in and authenticator steps are covered in the feature tests; these
| tests start signed in and click through the jobs that matter on a drop day.
|
*/

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('sends someone who isn’t signed in to the sign-in page', function (): void {
    auth()->logout();

    visit('/admin')->assertPathIs('/admin/login')->assertSee('Sign in')->assertNoJavaScriptErrors();
});

it('answers a text, flags it to come back to, and ticks it off', function (): void {
    $sms = FakeSmsGateway::swap();
    $jane = Subscriber::factory()->create(['first_name' => 'Jane']);
    SmsMessage::factory()->for($jane)->reply('What time on Saturday?')->create();

    visit(Conversations::getUrl(['phone' => $jane->phone]))
        ->assertSee('What time on Saturday?')
        ->type('.chat-compose textarea', 'Nine o’clock.')
        ->press('Send')
        ->assertSee('Sent')
        ->assertSee('Nine o\'clock.')
        ->press('Come back to')
        ->assertSee('Clear flag')
        ->press('Done')
        ->wait(1)
        ->assertDontSee('Clear flag')
        ->assertNoJavaScriptErrors();

    $state = ConversationState::query()->sole();

    expect($sms->sent)->toBe([['to' => $jane->phone, 'body' => 'Nine o\'clock.']])
        ->and($state->follow_up_at)->toBeNull()
        ->and($state->done_at)->not->toBeNull();
});

it('filters conversations to the ones still waiting for an answer', function (): void {
    $waiting = Subscriber::factory()->create(['first_name' => 'Waiting']);
    $answered = Subscriber::factory()->create(['first_name' => 'Answered']);
    SmsMessage::factory()->for($waiting)->reply('Is there any mince?')->create(['read_at' => now()]);
    SmsMessage::factory()->for($answered)->reply('Thanks for the box')->create(['read_at' => now(), 'created_at' => now()->subHour()]);
    SmsMessage::factory()->for($answered)->create(['sms_broadcast_id' => null, 'body' => 'You’re welcome', 'created_at' => now()->subMinutes(30)]);

    visit(Conversations::getUrl())
        ->assertSee('Is there any mince?')
        ->assertSee('You’re welcome')
        ->select('.chat-filter-box select', 'needs_reply')
        ->wait(1)
        ->assertSee('Is there any mince?')
        ->assertDontSee('You’re welcome')
        ->assertNoJavaScriptErrors();
});

it('runs a drop’s pre-flight check and reports what needs fixing', function (): void {
    FakePaymentGateway::swap();
    $drop = Drop::factory()->create(['opens_at' => now()->addDay()]);

    visit(DropResource::getUrl('edit', ['record' => $drop]))
        ->press('Run pre-flight check')
        ->assertSee('Fix before opening')
        ->assertSee('Add at least one product to this drop.')
        ->assertNoJavaScriptErrors();

    expect($drop->refresh()->preflight_ran_at)->not->toBeNull();
});

it('marks a paid order fulfilled', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    OrderItem::factory()->for($order)->create();

    visit(OrderResource::getUrl('view', ['record' => $order]))
        ->press('Mark fulfilled')
        ->assertSee('Marked fulfilled')
        ->assertNoJavaScriptErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Fulfilled);
});
