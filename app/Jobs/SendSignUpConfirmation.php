<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Jobs\Concerns\WaitsUntilDue;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\QuietHours;
use App\Sms\SmsGateway;
use App\Sms\SmsText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one text someone gets on joining the wait list. It introduces the shop's number before the first drop text,
 * and lets a stranger whose number was typed in reply STOP.
 *
 * The form is public and takes any Australian mobile, so nothing typed on it goes in the text, a number is only
 * ever texted once, and a daily limit caps what a flood of fake sign-ups could cost. The text is stored as soon as
 * it's queued, so the admin can see it waiting, and any queued in quiet hours wait until 8am. The sync queue never
 * sends it (see WaitsUntilDue), so a machine without a worker can't text anyone.
 */
class SendSignUpConfirmation implements ShouldQueue
{
    use Queueable, WaitsUntilDue;

    public const string BODY = "You're on the wait list. We'll text you from this number when the next drop opens.";

    private const string DAILY_LIMIT_KEY = 'sign-up-confirmations';

    private const int SECONDS_PER_DAY = 86400;

    /**
     * An unexpected error, like the database dropping out, is retried: a text already claimed isn't sent again.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $subscriberId) {}

    /**
     * Queue the text for someone new to the list. Someone already on it has no need of one, and a number that opted
     * out comes back here as an existing subscriber. The daily limit is checked here, not when the text is sent, so
     * nobody is promised a text it would stop.
     *
     * @return bool whether a text is on its way
     */
    public static function queueFor(Subscriber $subscriber): bool
    {
        if (! $subscriber->wasRecentlyCreated || blank(config('services.twilio.sid'))) {
            return false;
        }

        if (RateLimiter::attempt(self::DAILY_LIMIT_KEY, config()->integer('services.twilio.confirmations_per_day'), fn (): bool => true, self::SECONDS_PER_DAY) === false) {
            Log::warning('The daily limit on sign-up confirmation texts was reached, so one was skipped.', ['subscriber_id' => $subscriber->id]);

            return false;
        }

        return self::queueIfNeeded($subscriber);
    }

    /**
     * Queue the text unless they've opted out or already have one, sent or waiting. The admin calls this directly:
     * the daily limit is there for the public form, not for a text they chose to send.
     *
     * @return bool whether a text was queued
     */
    public static function queueIfNeeded(Subscriber $subscriber): bool
    {
        if (! $subscriber->isSubscribed()) {
            return false;
        }

        // The database holds each subscriber to one confirmation, so two requests at once can't both queue a text.
        $message = SmsMessage::query()->createOrFirst(['subscriber_id' => $subscriber->id, 'is_sign_up_confirmation' => true], [
            'direction' => SmsDirection::Outbound,
            'to' => $subscriber->phone,
            'from' => config()->string('services.twilio.from'),
            'body' => SmsText::forBroadcast(self::BODY)->text,
            'status' => SmsStatus::Queued,
        ]);

        if (! $message->wasRecentlyCreated) {
            return false;
        }

        self::dispatch($subscriber->id);

        return true;
    }

    public function handle(SmsGateway $sms): void
    {
        if ($this->waitingUntil(QuietHours::nextSendingTime(now()))) {
            return;
        }

        // Marked as sending in one transaction, so a retry or a second copy of this job finds nothing to send.
        DB::transaction(function (): ?SmsMessage {
            $message = $this->waitingText()->with('subscriber')->lock('for update skip locked')->first();

            $message?->update(['status' => SmsStatus::Sending]);

            return $message;
        })?->sendWith($sms);
    }

    /**
     * When the retries run out, show the text as failed rather than leaving it waiting for ever.
     */
    public function failed(): void
    {
        $this->waitingText()->update(['status' => SmsStatus::Failed]);
    }

    /**
     * @return Builder<SmsMessage>
     */
    private function waitingText(): Builder
    {
        return SmsMessage::query()
            ->where('subscriber_id', $this->subscriberId)
            ->where('is_sign_up_confirmation', true)
            ->where('status', SmsStatus::Queued);
    }
}
