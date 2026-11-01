<?php

declare(strict_types=1);

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Jobs\SendSignUpConfirmation;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\SmsText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeSmsGateway;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Australia/Melbourne'));
});

function runSignUpConfirmation(int $subscriberId): void
{
    app()->call([new SendSignUpConfirmation($subscriberId), 'handle']);
}

function queueAndRunSignUpConfirmation(Subscriber $subscriber): void
{
    SendSignUpConfirmation::queueIfNeeded($subscriber);
    runSignUpConfirmation($subscriber->id);
}

it('texts someone the fixed wording, with nothing typed on the form in it', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create(['first_name' => 'Visit evil.example', 'phone' => '+61412345678']);

    queueAndRunSignUpConfirmation($subscriber);

    expect($sms->sent)->toBe([[
        'to' => '+61412345678',
        'body' => "Ferguson Livestock: You're on the wait list. We'll text you from this number when the next drop opens.\nReply STOP to opt out",
    ]]);
});

it('keeps the text with the subscriber’s messages, as one text from the shop’s number', function (): void {
    FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);

    queueAndRunSignUpConfirmation($subscriber);

    expect($subscriber->messages()->sole())
        ->direction->toBe(SmsDirection::Outbound)
        ->status->toBe(SmsStatus::Sent)
        ->to->toBe('+61412345678')
        ->from->toBe('+61400000000')
        ->segments->toBe(1)
        ->sms_broadcast_id->toBeNull();
});

it('shows the text as waiting from the moment it’s queued', function (): void {
    $sms = FakeSmsGateway::swap();
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();

    $queued = SendSignUpConfirmation::queueIfNeeded($subscriber);

    expect($queued)->toBeTrue();
    expect($subscriber->signUpConfirmation?->status)->toBe(SmsStatus::Queued);
    expect($sms->sent)->toBeEmpty();
    Queue::assertPushed(SendSignUpConfirmation::class, fn (SendSignUpConfirmation $job): bool => $job->subscriberId === $subscriber->id);
});

it('queues only one text for someone, however many times it’s asked for', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();

    SendSignUpConfirmation::queueIfNeeded($subscriber);
    $second = SendSignUpConfirmation::queueIfNeeded($subscriber);

    expect($second)->toBeFalse();
    expect($subscriber->messages()->count())->toBe(1);
    Queue::assertPushedTimes(SendSignUpConfirmation::class, 1);
});

it('queues nothing for someone who has opted out', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->unsubscribed()->create();

    $queued = SendSignUpConfirmation::queueIfNeeded($subscriber);

    expect($queued)->toBeFalse();
    expect($subscriber->messages()->exists())->toBeFalse();
    Queue::assertNotPushed(SendSignUpConfirmation::class);
});

it('puts the text off by a quarter of an hour at a time during quiet hours', function (): void {
    $sms = FakeSmsGateway::swap();
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();
    SendSignUpConfirmation::queueIfNeeded($subscriber);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 22:00', 'Australia/Melbourne'));

    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toBeEmpty();
    Queue::assertPushed(SendSignUpConfirmation::class, fn (SendSignUpConfirmation $job): bool => $job->subscriberId === $subscriber->id
        && $job->delay instanceof CarbonInterface
        && $job->delay->equalTo(CarbonImmutable::parse('2026-11-02 22:15', 'Australia/Melbourne')));
});

it('times the last wait to end at 8am', function (): void {
    FakeSmsGateway::swap();
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();
    SendSignUpConfirmation::queueIfNeeded($subscriber);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 07:50', 'Australia/Melbourne'));

    runSignUpConfirmation($subscriber->id);

    Queue::assertPushed(SendSignUpConfirmation::class, fn (SendSignUpConfirmation $job): bool => $job->delay instanceof CarbonInterface
        && $job->delay->equalTo(CarbonImmutable::parse('2026-11-03 08:00', 'Australia/Melbourne')));
});

it('sends the text once quiet hours end', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SendSignUpConfirmation::queueIfNeeded($subscriber);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00', 'Australia/Melbourne'));

    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toHaveCount(1);
});

it('sends nothing from a machine without a queue worker', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();

    SendSignUpConfirmation::queueIfNeeded($subscriber);

    expect($sms->sent)->toBeEmpty();
});

it('never texts a number twice when the job runs again', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();

    queueAndRunSignUpConfirmation($subscriber);
    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toHaveCount(1);
});

it('still sends the confirmation to someone who has had a different text', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SmsMessage::factory()->create(['subscriber_id' => $subscriber->id, 'to' => $subscriber->phone]);

    queueAndRunSignUpConfirmation($subscriber);

    expect($sms->sent)->toHaveCount(1);
});

it('recognises confirmations sent before they were marked as such', function (): void {
    $migration = ['--path' => 'database/migrations/2026_11_01_201811_add_sign_up_confirmation_to_sms_messages.php'];
    expect(Artisan::call('migrate:rollback', $migration))->toBe(0);
    $subscriber = Subscriber::factory()->create();
    $confirmation = SmsMessage::factory()->create(['subscriber_id' => $subscriber->id, 'sms_broadcast_id' => null, 'body' => SmsText::forBroadcast(SendSignUpConfirmation::BODY)->text]);
    $reply = SmsMessage::factory()->create(['subscriber_id' => $subscriber->id, 'sms_broadcast_id' => null, 'body' => 'Thanks for that']);

    expect(Artisan::call('migrate', $migration))->toBe(0);

    expect($subscriber->signUpConfirmation?->is($confirmation))->toBeTrue();
    expect($reply->refresh()->is_sign_up_confirmation)->toBeFalse();
});

it('records a text Twilio refuses and doesn’t try again', function (): void {
    FakeSmsGateway::swap()->refuse('+61412345678', 30005);
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);

    queueAndRunSignUpConfirmation($subscriber);
    runSignUpConfirmation($subscriber->id);

    expect($subscriber->messages()->sole())
        ->status->toBe(SmsStatus::Failed)
        ->error_code->toBe(30005);
});

it('shows a text whose retries ran out as failed, not waiting for ever', function (): void {
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();
    SendSignUpConfirmation::queueIfNeeded($subscriber);

    (new SendSignUpConfirmation($subscriber->id))->failed();

    expect($subscriber->signUpConfirmation?->status)->toBe(SmsStatus::Failed);
});

it('sends nothing to someone who opted out while the text was waiting', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SendSignUpConfirmation::queueIfNeeded($subscriber);
    $subscriber->optOut();

    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toBeEmpty();
    expect($subscriber->signUpConfirmation?->status)->toBe(SmsStatus::Skipped);
});

it('sends nothing to someone removed while the text was waiting', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SendSignUpConfirmation::queueIfNeeded($subscriber);
    $subscriberId = $subscriber->id;
    $subscriber->delete();

    runSignUpConfirmation($subscriberId);

    expect($sms->sent)->toBeEmpty();
});

it('stops queuing texts for new sign-ups at the daily limit, without touching the sign-ups', function (): void {
    config(['services.twilio.sid' => 'test-account', 'services.twilio.confirmations_per_day' => 2]);
    Queue::fake([SendSignUpConfirmation::class]);
    $subscribers = Subscriber::factory()->count(3)->create();

    $queued = $subscribers->map(fn (Subscriber $subscriber): bool => SendSignUpConfirmation::queueFor($subscriber));

    expect($queued->all())->toBe([true, true, false]);
    expect(Subscriber::query()->subscribed()->count())->toBe(3);
    expect($subscribers->last()?->signUpConfirmation)->toBeNull();
    Queue::assertPushedTimes(SendSignUpConfirmation::class, 2);
});

it('queues a text the admin asks for, whatever the daily limit', function (): void {
    config(['services.twilio.sid' => 'test-account', 'services.twilio.confirmations_per_day' => 1]);
    Queue::fake([SendSignUpConfirmation::class]);
    [$first, $second] = Subscriber::factory()->count(2)->create()->all();
    SendSignUpConfirmation::queueFor($first);

    $queued = SendSignUpConfirmation::queueIfNeeded($second);

    expect($queued)->toBeTrue();
});

it('starts queuing texts for new sign-ups again the day after reaching the limit', function (): void {
    config(['services.twilio.sid' => 'test-account', 'services.twilio.confirmations_per_day' => 1]);
    Queue::fake([SendSignUpConfirmation::class]);
    [$first, $second] = Subscriber::factory()->count(2)->create()->all();
    SendSignUpConfirmation::queueFor($first);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:01', 'Australia/Melbourne'));

    $queued = SendSignUpConfirmation::queueFor($second);

    expect($queued)->toBeTrue();
});
