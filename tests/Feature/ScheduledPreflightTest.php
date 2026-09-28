<?php

use App\Models\Drop;
use App\Models\User;
use App\Notifications\DropPreflightFinished;
use App\Payments\PaymentGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakePaymentGateway;

beforeEach(function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    app()->instance(PaymentGateway::class, new FakePaymentGateway);
    Notification::fake();
});

it('checks drops about to open and tells the admin', function () {
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $soon = Drop::factory()->create(['opens_at' => now()->addMinutes(8)]);

    expect(Artisan::call('drops:preflight'))->toBe(0);

    expect($soon->refresh()->preflight_ran_at)->not->toBeNull();
    Notification::assertSentTo($admin, DropPreflightFinished::class, fn (DropPreflightFinished $notification) => $notification->drop->is($soon));
});

it('checks each drop only once', function () {
    User::factory()->create(['email' => 'daniel@example.com']);
    Drop::factory()->create(['opens_at' => now()->addMinutes(8)]);

    $this->artisan('drops:preflight');
    $this->artisan('drops:preflight');

    Notification::assertSentTimes(DropPreflightFinished::class, 1);
});

it('leaves drafts, drops far off and drops already open alone', function () {
    User::factory()->create(['email' => 'daniel@example.com']);
    $draft = Drop::factory()->draft()->create(['opens_at' => now()->addMinutes(5)]);
    $later = Drop::factory()->create(['opens_at' => now()->addHour()]);
    $open = Drop::factory()->open()->create();

    $this->artisan('drops:preflight');

    expect([$draft->refresh()->preflight_ran_at, $later->refresh()->preflight_ran_at, $open->refresh()->preflight_ran_at])->each->toBeNull();
    Notification::assertNothingSent();
});

it('emails the admin address even before the admin account exists', function () {
    Drop::factory()->create(['opens_at' => now()->addMinutes(8)]);

    $this->artisan('drops:preflight');

    Notification::assertSentTo(new AnonymousNotifiable, DropPreflightFinished::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'daniel@example.com');
});

it('runs every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'drops:preflight'));

    expect($event?->expression)->toBe('* * * * *');
});
