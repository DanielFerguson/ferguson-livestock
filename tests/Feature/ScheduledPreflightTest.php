<?php

use App\Actions\CheckDropBeforeItOpens;
use App\Jobs\RunDropPreflightWhenDue;
use App\Models\Drop;
use App\Models\User;
use App\Notifications\DropPreflightFinished;
use App\Payments\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

it('times a check for a drop published to open within the hour', function () {
    Queue::fake();

    $drop = Drop::factory()->create(['opens_at' => now()->addMinutes(45)]);

    Queue::assertPushed(RunDropPreflightWhenDue::class, fn (RunDropPreflightWhenDue $job): bool => $job->dropId === $drop->id);
});

it('times checks for drops opening within the next hour each time it runs', function () {
    $drop = Drop::factory()->create(['opens_at' => now()->addMinutes(50)]);
    Queue::fake();

    $this->artisan('drops:preflight');

    Queue::assertPushed(RunDropPreflightWhenDue::class, fn (RunDropPreflightWhenDue $job): bool => $job->dropId === $drop->id);
    expect($drop->refresh()->preflight_ran_at)->toBeNull();
});

it('waits until ten minutes before the drop opens', function () {
    $drop = Drop::factory()->create(['opens_at' => now()->addMinutes(20)]);
    Queue::fake();
    $this->freezeTime();

    app()->call([new RunDropPreflightWhenDue($drop->id), 'handle']);

    expect($drop->refresh()->preflight_ran_at)->toBeNull();
    Queue::assertPushed(RunDropPreflightWhenDue::class, fn (RunDropPreflightWhenDue $job): bool => $job->dropId === $drop->id
        && $job->delay instanceof CarbonInterface
        && $job->delay->eq($drop->opens_at->subMinutes(10)));
});

it('checks the drop when its timed check comes due, once', function () {
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $drop = Drop::factory()->create(['opens_at' => now()->addMinutes(45)]);
    $this->travelTo($drop->opens_at->subMinutes(10));

    app()->call([new RunDropPreflightWhenDue($drop->id), 'handle']);
    app()->call([new RunDropPreflightWhenDue($drop->id), 'handle']);

    expect($drop->refresh()->preflight_ran_at)->not->toBeNull();
    Notification::assertSentTimes(DropPreflightFinished::class, 1);
    Notification::assertSentTo($admin, DropPreflightFinished::class);
});

it('leaves a drop alone while another check of it is running', function () {
    User::factory()->create(['email' => 'daniel@example.com']);
    $drop = Drop::factory()->create(['opens_at' => now()->addMinutes(8)]);
    CheckDropBeforeItOpens::lockFor($drop)->get();

    app()->call([new RunDropPreflightWhenDue($drop->id), 'handle']);

    Notification::assertNothingSent();
});

it('runs every hour, as a safety net for timed checks that went missing', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'drops:preflight'));

    expect($event?->expression)->toBe('0 * * * *');
});
