<?php

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

use function Pest\Laravel\post;

beforeEach(fn () => config(['services.twilio.token' => 'twilio-auth-token']));

/**
 * POST to a Twilio webhook, signed the way Twilio signs it.
 *
 * @param  array<string, string>  $data
 * @return TestResponse<Response>
 */
function postFromTwilio(string $route, array $data): TestResponse
{
    $url = route($route);

    return post($url, $data, ['X-Twilio-Signature' => (new RequestValidator('twilio-auth-token'))->computeSignature($url, $data)]);
}

it('refuses requests Twilio didn’t sign', function (string $route, ?string $signature) {
    $message = SmsMessage::factory()->create();

    $headers = $signature === null ? [] : ['X-Twilio-Signature' => $signature];
    $this->post(route($route), ['MessageSid' => $message->twilio_sid, 'MessageStatus' => 'delivered', 'From' => $message->to, 'Body' => 'STOP'], $headers)
        ->assertForbidden();

    expect($message->refresh()->status)->toBe(SmsStatus::Sent)
        ->and(SmsMessage::count())->toBe(1);
})->with([
    'status, unsigned' => ['webhooks.twilio.status', null],
    'status, wrong signature' => ['webhooks.twilio.status', 'bm90IHRoZSByaWdodCBzaWduYXR1cmU='],
    'inbound, unsigned' => ['webhooks.twilio.inbound', null],
    'inbound, wrong signature' => ['webhooks.twilio.inbound', 'bm90IHRoZSByaWdodCBzaWduYXR1cmU='],
]);

it('refuses every request until the Twilio auth token is set', function () {
    config(['services.twilio.token' => null]);
    $message = SmsMessage::factory()->create();

    postFromTwilio('webhooks.twilio.status', ['MessageSid' => (string) $message->twilio_sid, 'MessageStatus' => 'delivered'])
        ->assertForbidden();
});

it('records when a text is delivered', function () {
    $this->freezeTime();
    $message = SmsMessage::factory()->create();

    postFromTwilio('webhooks.twilio.status', ['MessageSid' => (string) $message->twilio_sid, 'MessageStatus' => 'delivered'])
        ->assertNoContent();

    expect($message->refresh()->status)->toBe(SmsStatus::Delivered)
        ->and($message->delivered_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('keeps the latest status when Twilio’s updates arrive out of order', function () {
    $message = SmsMessage::factory()->create(['status' => SmsStatus::Delivered]);

    postFromTwilio('webhooks.twilio.status', ['MessageSid' => (string) $message->twilio_sid, 'MessageStatus' => 'sent'])
        ->assertNoContent();

    expect($message->refresh()->status)->toBe(SmsStatus::Delivered);
});

it('records why a text wasn’t delivered', function () {
    $message = SmsMessage::factory()->create();

    postFromTwilio('webhooks.twilio.status', ['MessageSid' => (string) $message->twilio_sid, 'MessageStatus' => 'undelivered', 'ErrorCode' => '30007']);

    expect($message->refresh()->status)->toBe(SmsStatus::Undelivered)
        ->and($message->error_code)->toBe(30007);
});

it('ignores status updates for texts it didn’t send', function () {
    postFromTwilio('webhooks.twilio.status', ['MessageSid' => 'SMunknown', 'MessageStatus' => 'delivered'])
        ->assertNoContent();
});

it('stores a reply against the subscriber who sent it', function () {
    $subscriber = Subscriber::factory()->create();

    postFromTwilio('webhooks.twilio.inbound', ['MessageSid' => 'SMreply1', 'From' => $subscriber->phone, 'To' => '+61400000000', 'Body' => 'What time Saturday?', 'NumSegments' => '1'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/xml; charset=UTF-8')
        ->assertSee('<Response/>', escape: false);

    $reply = SmsMessage::sole();
    expect($reply->direction)->toBe(SmsDirection::Inbound)
        ->and($reply->status)->toBe(SmsStatus::Received)
        ->and($reply->subscriber_id)->toBe($subscriber->id)
        ->and($reply->body)->toBe('What time Saturday?')
        ->and($reply->read_at)->toBeNull()
        ->and($subscriber->refresh()->isSubscribed())->toBeTrue();
});

it('opts someone out when they reply asking to stop', function () {
    $subscriber = Subscriber::factory()->create();

    postFromTwilio('webhooks.twilio.inbound', ['MessageSid' => 'SMreply1', 'From' => $subscriber->phone, 'To' => '+61400000000', 'Body' => 'Stop please']);

    expect($subscriber->refresh()->isSubscribed())->toBeFalse();
});

it('keeps replies from numbers that aren’t subscribers', function () {
    postFromTwilio('webhooks.twilio.inbound', ['MessageSid' => 'SMreply1', 'From' => '+61499999999', 'To' => '+61400000000', 'Body' => 'Who is this?']);

    expect(SmsMessage::sole()->subscriber_id)->toBeNull();
});

it('stores a reply once when Twilio sends it again', function () {
    $data = ['MessageSid' => 'SMreply1', 'From' => '+61499999999', 'To' => '+61400000000', 'Body' => 'Hello'];

    postFromTwilio('webhooks.twilio.inbound', $data)->assertOk();
    postFromTwilio('webhooks.twilio.inbound', $data)->assertOk();

    expect(SmsMessage::count())->toBe(1);
});
