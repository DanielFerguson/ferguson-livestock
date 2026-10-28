<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Exceptions\CannotReply;
use App\Exceptions\SmsNotSent;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\SmsGateway;
use App\Sms\SmsText;

/**
 * Answer someone who texted the shop's number, from the same number.
 *
 * The text goes out as typed: unlike a broadcast it carries no sender name or opt-out line, because it's one
 * person answering another. It can only go to a number that has texted in, and never to someone who has opted out.
 */
final readonly class SendReply
{
    public const int MAX_CHARACTERS = 640;

    public function __construct(private SmsGateway $sms) {}

    /**
     * @return SmsMessage the reply as stored: Sent, or Failed when Twilio refused it
     *
     * @throws CannotReply
     */
    public function __invoke(string $phone, string $body): SmsMessage
    {
        $text = SmsText::tidy($body);
        $subscriber = Subscriber::firstWhere('phone', $phone);

        $this->guard($phone, $text, $subscriber);

        $message = SmsMessage::create([
            'subscriber_id' => $subscriber?->id,
            'direction' => SmsDirection::Outbound,
            'to' => $phone,
            'from' => config()->string('services.twilio.from'),
            'body' => $text,
            'status' => SmsStatus::Sending,
        ]);

        try {
            $sent = $this->sms->send($phone, $text);
        } catch (SmsNotSent $exception) {
            $message->update(['status' => SmsStatus::Failed, 'error_code' => $exception->errorCode]);

            if ($exception->recipientOptedOut()) {
                $subscriber?->optOut();
            }

            return $message;
        }

        $message->update(['status' => SmsStatus::Sent, 'twilio_sid' => $sent->sid, 'segments' => $sent->segments, 'sent_at' => now()]);

        return $message;
    }

    /**
     * @throws CannotReply
     */
    private function guard(string $phone, string $text, ?Subscriber $subscriber): void
    {
        if ($text === '') {
            throw CannotReply::empty();
        }

        if (mb_strlen($text) > self::MAX_CHARACTERS) {
            throw CannotReply::tooLong(self::MAX_CHARACTERS);
        }

        if ($subscriber?->isSubscribed() === false) {
            throw CannotReply::optedOut();
        }

        if (! SmsMessage::query()->where('contact_phone', $phone)->where('direction', SmsDirection::Inbound)->exists()) {
            throw CannotReply::neverTextedIn();
        }
    }
}
