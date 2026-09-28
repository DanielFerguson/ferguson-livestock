<?php

use App\Enums\BroadcastStatus;
use App\Models\SmsBroadcast;
use App\Models\Subscriber;
use App\Sms\SmsGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeSmsGateway;

it('starts scheduled broadcasts once their time comes', function () {
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

it('checks for scheduled broadcasts every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'sms:send-scheduled'));

    expect($event?->expression)->toBe('* * * * *');
});
