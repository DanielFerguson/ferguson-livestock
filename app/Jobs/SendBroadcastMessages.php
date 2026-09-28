<?php

namespace App\Jobs;

use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Sms\SmsGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a broadcast's waiting texts one at a time, which also keeps within Twilio's sending rate.
 *
 * Laravel Cloud's managed queue stops a job after 90 seconds, so each job sends for up to 45 seconds and then
 * queues the next one. Running twice at once is safe: each text is claimed before it's sent.
 */
class SendBroadcastMessages implements ShouldQueue
{
    use Queueable;

    private const int SECONDS_PER_JOB = 45;

    /**
     * An unexpected error, like the database dropping out, is retried: texts already sent stay claimed.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $broadcastId) {}

    public function handle(SmsGateway $sms): void
    {
        $handOverAt = now()->addSeconds(self::SECONDS_PER_JOB);

        do {
            $message = SmsMessage::claimNext($this->broadcastId);
            $message?->sendWith($sms);
        } while ($message !== null && now()->lessThan($handOverAt));

        if ($message !== null) {
            self::dispatch($this->broadcastId);

            return;
        }

        SmsBroadcast::query()
            ->whereKey($this->broadcastId)
            ->whereNull('finished_at')
            ->update(['finished_at' => now(), 'updated_at' => now()]);
    }
}
