<?php

use App\Actions\StartBroadcast;
use App\Enums\BroadcastStatus;
use App\Enums\SmsStatus;
use App\Exceptions\SmsNotSent;
use App\Jobs\SendBroadcastMessages;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeSmsGateway;

it('texts everyone subscribed once, from the shop’s number', function () {
    $sms = FakeSmsGateway::swap();
    $ballarat = Subscriber::factory()->create(['postcode' => '3350']);
    $creswick = Subscriber::factory()->create(['postcode' => '3363']);
    Subscriber::factory()->unsubscribed()->create();
    $broadcast = SmsBroadcast::factory()->create(['body' => 'The next drop opens Saturday.']);

    expect(app(StartBroadcast::class)($broadcast))->toBe(2);

    expect($sms->recipients())->toEqualCanonicalizing([$ballarat->phone, $creswick->phone])
        ->and($sms->sent[0]['body'])->toBe("Ferguson Livestock: The next drop opens Saturday.\nReply STOP to opt out")
        ->and($broadcast->refresh()->status())->toBe(BroadcastStatus::Sent)
        ->and($broadcast->messages()->pluck('status')->unique()->all())->toBe([SmsStatus::Sent])
        ->and($broadcast->messages()->pluck('from')->unique()->all())->toBe(['+61400000000']);
});

it('only texts the chosen postcodes', function () {
    $sms = FakeSmsGateway::swap();
    $ballarat = Subscriber::factory()->create(['postcode' => '3350']);
    Subscriber::factory()->create(['postcode' => '3363']);

    app(StartBroadcast::class)(SmsBroadcast::factory()->forPostcodes(['3350'])->create());

    expect($sms->recipients())->toBe([$ballarat->phone]);
});

it('never texts anyone twice when a broadcast is started again or its job runs twice', function () {
    $sms = FakeSmsGateway::swap();
    Subscriber::factory()->count(2)->create();
    $broadcast = SmsBroadcast::factory()->create();

    app(StartBroadcast::class)($broadcast);
    expect(app(StartBroadcast::class)($broadcast))->toBeNull();
    (new SendBroadcastMessages($broadcast->id))->handle($sms);

    expect($sms->sent)->toHaveCount(2);
});

it('skips someone who opts out after the broadcast starts', function () {
    $sms = FakeSmsGateway::swap();
    Queue::fake([SendBroadcastMessages::class]);
    $leaving = Subscriber::factory()->create();
    $staying = Subscriber::factory()->create();
    $broadcast = SmsBroadcast::factory()->create();

    app(StartBroadcast::class)($broadcast);
    $leaving->optOut();
    (new SendBroadcastMessages($broadcast->id))->handle($sms);

    expect($sms->recipients())->toBe([$staying->phone])
        ->and($broadcast->messages()->where('subscriber_id', $leaving->id)->value('status'))->toBe(SmsStatus::Skipped);
});

it('opts someone out when Twilio says they replied STOP to it', function () {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    $sms->refuse($subscriber->phone, SmsNotSent::RECIPIENT_OPTED_OUT);

    app(StartBroadcast::class)(SmsBroadcast::factory()->create());

    $message = SmsMessage::sole();
    expect($message->status)->toBe(SmsStatus::Failed)
        ->and($message->error_code)->toBe(SmsNotSent::RECIPIENT_OPTED_OUT)
        ->and($subscriber->refresh()->unsubscribed_at)->not->toBeNull();
});

it('records a refused text and carries on with the rest', function () {
    $sms = FakeSmsGateway::swap();
    $wrongNumber = Subscriber::factory()->create();
    $fine = Subscriber::factory()->create();
    $sms->refuse($wrongNumber->phone, 21211);
    $broadcast = SmsBroadcast::factory()->create();

    app(StartBroadcast::class)($broadcast);

    expect($sms->recipients())->toBe([$fine->phone])
        ->and($broadcast->messages()->where('subscriber_id', $wrongNumber->id)->value('error_code'))->toBe(21211)
        ->and($wrongNumber->refresh()->unsubscribed_at)->toBeNull()
        ->and($broadcast->refresh()->status())->toBe(BroadcastStatus::Sent);
});

it('hands over to a new job before the queue’s time limit', function () {
    $sms = FakeSmsGateway::swap();
    Queue::fake([SendBroadcastMessages::class]);
    Subscriber::factory()->count(3)->create();
    $broadcast = SmsBroadcast::factory()->create();
    app(StartBroadcast::class)($broadcast);
    $sms->whileSending(fn () => $this->travel(50)->seconds());

    (new SendBroadcastMessages($broadcast->id))->handle($sms);

    expect($sms->sent)->toHaveCount(1)
        ->and($broadcast->refresh()->status())->toBe(BroadcastStatus::Sending);
    Queue::assertPushed(SendBroadcastMessages::class, 2);
});
