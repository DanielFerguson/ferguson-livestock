<?php

declare(strict_types=1);

use App\Actions\SendReply;
use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Exceptions\CannotReply;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Tests\Fakes\FakeSmsGateway;

it('answers a subscriber who texted in, as typed, from the shop’s number', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SmsMessage::factory()->reply('Is Saturday still on?')->for($subscriber)->create();

    $reply = app(SendReply::class)($subscriber->phone, '  Yes, 9am sharp.  ');

    expect($sms->sent)->toBe([['to' => $subscriber->phone, 'body' => 'Yes, 9am sharp.']])
        ->and($reply->direction)->toBe(SmsDirection::Outbound)
        ->and($reply->status)->toBe(SmsStatus::Sent)
        ->and($reply->subscriber_id)->toBe($subscriber->id)
        ->and($reply->sms_broadcast_id)->toBeNull()
        ->and($reply->contact_phone)->toBe($subscriber->phone)
        ->and($reply->from)->toBe('+61400000000')
        ->and($reply->body)->toBe('Yes, 9am sharp.')
        ->and($reply->twilio_sid)->not->toBeNull()
        ->and($reply->segments)->toBe(1)
        ->and($reply->sent_at)->not->toBeNull();
});

it('answers someone who texted in without being on the wait list', function (): void {
    $sms = FakeSmsGateway::swap();
    SmsMessage::factory()->reply('Do you deliver to Creswick?')->state(['subscriber_id' => null, 'from' => '+61412000111'])->create();

    $reply = app(SendReply::class)('+61412000111', 'We do, on Fridays.');

    expect($sms->recipients())->toBe(['+61412000111'])
        ->and($reply->subscriber_id)->toBeNull()
        ->and($reply->contact_phone)->toBe('+61412000111');
});

it('won’t text someone who has opted out, even though they texted in', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->unsubscribed()->create();
    SmsMessage::factory()->reply('Stop')->for($subscriber)->create();

    expect(fn () => app(SendReply::class)($subscriber->phone, 'Sorry to see you go.'))
        ->toThrow(CannotReply::class, 'They’ve opted out of texts, so you can’t text them.');

    expect($sms->sent)->toBe([])
        ->and(SmsMessage::query()->where('direction', SmsDirection::Outbound)->count())->toBe(0);
});

it('only answers numbers that have texted in', function (): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();

    expect(fn () => app(SendReply::class)($subscriber->phone, 'Hello!'))
        ->toThrow(CannotReply::class, 'They haven’t texted you, so you can only reach them with a broadcast.');

    expect($sms->sent)->toBe([]);
});

it('refuses an empty answer and one too long to send', function (string $body, string $message): void {
    FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SmsMessage::factory()->reply()->for($subscriber)->create();

    expect(fn () => app(SendReply::class)($subscriber->phone, $body))->toThrow(CannotReply::class, $message);
})->with([
    'empty' => ['   ', 'Write something to send.'],
    'too long' => [str_repeat('a', 641), 'That’s too long: keep it to 640 characters.'],
]);

it('records an answer Twilio turned down, and opts the person out if they had told Twilio to stop', function (int $code, bool $optedOut): void {
    $sms = FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SmsMessage::factory()->reply()->for($subscriber)->create();
    $sms->refuse($subscriber->phone, $code);

    $reply = app(SendReply::class)($subscriber->phone, 'Hi again');

    expect($reply->status)->toBe(SmsStatus::Failed)
        ->and($reply->error_code)->toBe($code)
        ->and($reply->twilio_sid)->toBeNull()
        ->and($subscriber->refresh()->isSubscribed())->toBe(! $optedOut);
})->with([
    'opted out with Twilio' => [21610, true],
    'any other refusal' => [21211, false],
]);

it('lets Twilio’s delivery report update an answer', function (): void {
    FakeSmsGateway::swap();
    $subscriber = Subscriber::factory()->create();
    SmsMessage::factory()->reply()->for($subscriber)->create();
    $reply = app(SendReply::class)($subscriber->phone, 'On its way');

    $reply->recordStatus(SmsStatus::Delivered);

    expect($reply->refresh()->status)->toBe(SmsStatus::Delivered)
        ->and($reply->delivered_at)->not->toBeNull();
});
