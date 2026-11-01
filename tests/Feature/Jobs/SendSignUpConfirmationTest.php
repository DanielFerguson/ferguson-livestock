<?php

declare(strict_types=1);

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Jobs\SendSignUpConfirmation;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeSmsGateway;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Australia/Melbourne'));
});

function runSignUpConfirmation(int $subscriberId): void
{
    app()->call([new SendSignUpConfirmation($subscriberId), 'handle']);
}

it('texts someone new the fixed wording, with nothing typed on the form in it', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create(['first_name' => 'Visit evil.example', 'phone' => '+61412345678']);

    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toBe([[
        'to' => '+61412345678',
        'body' => "Ferguson Livestock: You're on the wait list. We'll text you from this number when the next drop opens.\nReply STOP to opt out",
    ]]);
});

it('keeps the text with the subscriber’s messages, as one text from the shop’s number', function (): void {
    FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);

    runSignUpConfirmation($subscriber->id);

    expect($subscriber->messages()->sole())
        ->direction->toBe(SmsDirection::Outbound)
        ->status->toBe(SmsStatus::Sent)
        ->to->toBe('+61412345678')
        ->from->toBe('+61400000000')
        ->segments->toBe(1)
        ->sms_broadcast_id->toBeNull();
});

it('puts the text off by a quarter of an hour at a time during quiet hours', function (): void {
    $sms = FakeSmsGateway::swap();
    Queue::fake([SendSignUpConfirmation::class]);
    $subscriber = Subscriber::factory()->create();
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
    $this->travelTo(CarbonImmutable::parse('2026-11-03 07:50', 'Australia/Melbourne'));

    runSignUpConfirmation($subscriber->id);

    Queue::assertPushed(SendSignUpConfirmation::class, fn (SendSignUpConfirmation $job): bool => $job->delay instanceof CarbonInterface
        && $job->delay->equalTo(CarbonImmutable::parse('2026-11-03 08:00', 'Australia/Melbourne')));
});

it('sends the text once quiet hours end', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00', 'Australia/Melbourne'));

    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toHaveCount(1);
});

it('sends nothing from a machine without a queue worker', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();

    SendSignUpConfirmation::dispatch($subscriber->id);

    expect($sms->sent)->toBeEmpty();
});

it('never texts a number twice when the job runs again', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();

    runSignUpConfirmation($subscriber->id);
    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toHaveCount(1);
});

it('records a text Twilio refuses and doesn’t try again', function (): void {
    $sms = FakeSmsGateway::swap()->refuse('+61412345678', 30005);
    $subscriber = Subscriber::factory()->create(['phone' => '+61412345678']);

    runSignUpConfirmation($subscriber->id);
    runSignUpConfirmation($subscriber->id);

    expect($subscriber->messages()->sole())
        ->status->toBe(SmsStatus::Failed)
        ->error_code->toBe(30005);
});

it('sends nothing to someone who opted out while the text was waiting', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->unsubscribed()->create();

    runSignUpConfirmation($subscriber->id);

    expect($sms->sent)->toBeEmpty();
    expect($subscriber->messages()->exists())->toBeFalse();
});

it('sends nothing to someone removed while the text was waiting', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    $subscriberId = $subscriber->id;
    $subscriber->delete();

    runSignUpConfirmation($subscriberId);

    expect($sms->sent)->toBeEmpty();
});

it('stops sending at the daily limit, without touching the sign-ups', function (): void {
    config(['services.twilio.confirmations_per_day' => 2]);
    $sms = FakeSmsGateway::swap();
    $subscribers = Subscriber::factory()->count(3)->create();

    $subscribers->each(fn (Subscriber $subscriber) => runSignUpConfirmation($subscriber->id));

    expect($sms->sent)->toHaveCount(2);
    expect(Subscriber::query()->subscribed()->count())->toBe(3);
});

it('starts sending again the day after reaching the limit', function (): void {
    config(['services.twilio.confirmations_per_day' => 1]);
    $sms = FakeSmsGateway::swap();
    [$first, $second] = Subscriber::factory()->count(2)->create()->all();

    runSignUpConfirmation($first->id);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:01', 'Australia/Melbourne'));
    runSignUpConfirmation($second->id);

    expect($sms->sent)->toHaveCount(2);
});
