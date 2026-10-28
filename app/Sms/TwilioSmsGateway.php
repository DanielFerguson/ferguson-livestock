<?php

declare(strict_types=1);

namespace App\Sms;

use App\Exceptions\SmsNotSent;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\CurlClient;
use Twilio\Rest\Client;

/**
 * Sends texts from the shop's Australian mobile number through Twilio.
 */
final readonly class TwilioSmsGateway implements SmsGateway
{
    public function __construct(
        private Client $client,
        private string $from,
        private string $statusCallbackUrl,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            // Fail fast rather than waiting out Twilio's defaults: a broadcast job has 90 seconds to finish.
            new Client(
                config()->string('services.twilio.sid'),
                config()->string('services.twilio.token'),
                httpClient: new CurlClient([CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]),
            ),
            config()->string('services.twilio.from'),
            route('webhooks.twilio.status'),
        );
    }

    public function send(string $to, string $body): SentSms
    {
        try {
            $message = $this->client->messages->create($to, [
                'from' => $this->from,
                'body' => $body,
                'statusCallback' => $this->statusCallbackUrl,
            ]);
        } catch (RestException $exception) {
            throw new SmsNotSent($exception->getMessage(), $exception->getCode());
        } catch (TwilioException $exception) {
            throw new SmsNotSent('Couldn’t reach Twilio: '.$exception->getMessage());
        }

        return new SentSms((string) $message->sid, (int) $message->numSegments);
    }
}
