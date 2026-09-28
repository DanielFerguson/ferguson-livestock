<?php

use App\Exceptions\SmsNotSent;
use App\Sms\TwilioSmsGateway;
use Tests\Fakes\FakeTwilioHttpClient;
use Twilio\Rest\Client;

function twilioGateway(FakeTwilioHttpClient $http): TwilioSmsGateway
{
    return new TwilioSmsGateway(new Client('AC123', 'secret', httpClient: $http), '+61400000000', 'https://example.test/api/webhooks/twilio/status');
}

it('sends from the shop’s number and asks Twilio to report delivery', function () {
    $http = new FakeTwilioHttpClient(201, ['sid' => 'SM123', 'status' => 'queued', 'num_segments' => '2']);

    $sent = twilioGateway($http)->send('+61412345678', 'Hello');

    expect($sent->sid)->toBe('SM123')
        ->and($sent->segments)->toBe(2)
        ->and($http->requests[0]['url'])->toBe('https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json')
        ->and($http->requests[0]['data'])->toMatchArray([
            'To' => '+61412345678',
            'From' => '+61400000000',
            'Body' => 'Hello',
            'StatusCallback' => 'https://example.test/api/webhooks/twilio/status',
        ]);
});

it('reports Twilio’s error code when it refuses a text', function () {
    $http = new FakeTwilioHttpClient(400, ['code' => 21610, 'message' => 'Attempt to send to unsubscribed recipient', 'status' => 400]);
    $refusal = null;

    try {
        twilioGateway($http)->send('+61412345678', 'Hello');
    } catch (SmsNotSent $exception) {
        $refusal = $exception;
    }

    expect($refusal?->errorCode)->toBe(21610)
        ->and($refusal?->recipientOptedOut())->toBeTrue();
});
