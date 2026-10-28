<?php

declare(strict_types=1);

use App\Enums\BroadcastStatus;
use App\Jobs\StartBroadcastWhenDue;
use App\Models\SmsBroadcast;
use App\Models\Subscriber;
use App\Sms\SmsGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeSmsGateway;

it('starts scheduled broadcasts once their time comes', function (): void {
    app()->instance(SmsGateway::class, $sms = new FakeSmsGateway);
    Subscriber::factory()->create();
    $due = SmsBroadcast::factory()->scheduledFor(now()->subMinute())->create();
    $later = SmsBroadcast::factory()->scheduledFor(now()->addMinute())->create();
    $draft = SmsBroadcast::factory()->create();

    expect(Artisan::call('sms:send-scheduled'))->toBe(0);

    expect($due->refresh()->status())->toBe(BroadcastStatus::Sent)
        ->and($later->refresh()->status())->toBe(BroadcastStatus::Scheduled)
        ->and($draft->refresh()->status())->toBe(BroadcastStatus::Draft)
        ->and($sms->sent)->toHaveCount(1);
});

it('times the start of a broadcast scheduled within the hour', function (): void {
    Queue::fake();

    $broadcast = SmsBroadcast::factory()->scheduledFor(now()->addMinutes(30))->create();

    Queue::assertPushed(StartBroadcastWhenDue::class, fn (StartBroadcastWhenDue $job): bool => $job->broadcastId === $broadcast->id);
});

it('times the start of broadcasts scheduled within the next hour each time it runs', function (): void {
    $broadcast = SmsBroadcast::factory()->scheduledFor(now()->addMinutes(50))->create();
    Queue::fake();

    $this->artisan('sms:send-scheduled');

    Queue::assertPushed(StartBroadcastWhenDue::class, fn (StartBroadcastWhenDue $job): bool => $job->broadcastId === $broadcast->id);
});

it('starts a timed broadcast when its time comes', function (): void {
    app()->instance(SmsGateway::class, $sms = new FakeSmsGateway);
    Subscriber::factory()->create();
    $broadcast = SmsBroadcast::factory()->scheduledFor(now()->addMinutes(30))->create();
    $this->travelTo($broadcast->scheduled_for);

    app()->call([new StartBroadcastWhenDue($broadcast->id), 'handle']);

    expect($broadcast->refresh()->status())->toBe(BroadcastStatus::Sent)
        ->and($sms->sent)->toHaveCount(1);
});

it('checks for scheduled broadcasts every hour, as a safety net for timed starts that went missing', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'sms:send-scheduled'));

    expect($event?->expression)->toBe('0 * * * *');
});
