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
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one text someone gets on joining the wait list. It introduces the shop's number before the first drop text,
 * and lets a stranger whose number was typed in reply STOP.
 *
 * The form is public and takes any Australian mobile, so nothing typed on it goes in the text, a number is only
 * ever texted once, and a daily limit caps what a flood of fake sign-ups could cost. Sign-ups in quiet hours wait
 * until 8am. The sync queue never sends it (see WaitsUntilDue), so a machine without a worker can't text anyone.
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

    /**
     * The daily limit is there for the public form, not for a text the admin chose to send.
     */
    public bool $ignoresDailyLimit = false;

    public function __construct(public int $subscriberId) {}

    /**
     * The text for someone who joined before these were sent, asked for from the subscribers page.
     */
    public static function requestedByAdmin(Subscriber $subscriber): self
    {
        $job = new self($subscriber->id);
        $job->ignoresDailyLimit = true;

        return $job;
    }

    /**
     * Queue the text for someone new to the list. Someone already on it has no need of one, and a number that opted
     * out comes back here as an existing subscriber.
     *
     * @return bool whether a text is on its way
     */
    public static function queueFor(Subscriber $subscriber): bool
    {
        if (! $subscriber->wasRecentlyCreated || blank(config('services.twilio.sid'))) {
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

        $this->claim()?->sendWith($sms);
    }

    /**
     * Store the text before it's sent, with the subscriber locked, so a retry or a second copy of this job finds it
     * and sends nothing.
     */
    private function claim(): ?SmsMessage
    {
        return DB::transaction(function (): ?SmsMessage {
            $subscriber = Subscriber::query()->lockForUpdate()->find($this->subscriberId);

            if ($subscriber?->isSubscribed() !== true || $subscriber->signUpConfirmation()->exists()) {
                return null;
            }

            if (! $this->ignoresDailyLimit && RateLimiter::attempt(self::DAILY_LIMIT_KEY, config()->integer('services.twilio.confirmations_per_day'), fn (): bool => true, self::SECONDS_PER_DAY) === false) {
                Log::warning('The daily limit on sign-up confirmation texts was reached, so one was skipped.', ['subscriber_id' => $subscriber->id]);

                return null;
            }

            return $subscriber->messages()->create([
                'direction' => SmsDirection::Outbound,
                'to' => $subscriber->phone,
                'from' => config()->string('services.twilio.from'),
                'body' => SmsText::forBroadcast(self::BODY)->text,
                'status' => SmsStatus::Sending,
                'is_sign_up_confirmation' => true,
            ]);
        });
    }
}
