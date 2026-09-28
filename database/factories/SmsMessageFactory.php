<?php

namespace Database\Factories;

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsMessage>
 */
class SmsMessageFactory extends Factory
{
    /**
     * A broadcast text Twilio has accepted by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscriber_id' => Subscriber::factory(),
            'sms_broadcast_id' => SmsBroadcast::factory()->sent(),
            'direction' => SmsDirection::Outbound,
            'to' => '+614'.fake()->unique()->numerify('########'),
            'from' => '+61400000000',
            'body' => "Ferguson Livestock: Our next beef drop opens Saturday at 9am.\nReply STOP to opt out",
            'status' => SmsStatus::Sent,
            'twilio_sid' => 'SM'.fake()->unique()->regexify('[a-f0-9]{32}'),
            'segments' => 1,
            'sent_at' => now(),
        ];
    }

    /**
     * A reply from a subscriber's phone.
     */
    public function reply(string $body = 'Thanks!'): static
    {
        return $this->state(fn () => [
            'sms_broadcast_id' => null,
            'direction' => SmsDirection::Inbound,
            'to' => '+61400000000',
            'from' => fn (array $attributes): string => (is_int($attributes['subscriber_id']) ? Subscriber::find($attributes['subscriber_id'])?->phone : null) ?? '+614'.fake()->numerify('########'),
            'body' => $body,
            'status' => SmsStatus::Received,
            'segments' => 1,
            'sent_at' => null,
        ]);
    }
}
