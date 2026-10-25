<?php

namespace App\Jobs;

use App\Actions\StartBroadcast;
use App\Jobs\Concerns\WaitsUntilDue;
use App\Models\SmsBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts a scheduled broadcast at its time. Queued when a broadcast is scheduled within the hour, and by the hourly
 * sms:send-scheduled for broadcasts coming up, which also starts any whose timed start went missing.
 */
class StartBroadcastWhenDue implements ShouldQueue
{
    use Queueable, WaitsUntilDue;

    public function __construct(public int $broadcastId) {}

    /**
     * Time the broadcast's start if it hasn't started and is scheduled within the hour.
     */
    public static function timeFor(SmsBroadcast $broadcast): void
    {
        if ($broadcast->started_at !== null || $broadcast->scheduled_for === null || ! $broadcast->scheduled_for->between(now(), now()->addMinutes(self::LOOKAHEAD_MINUTES))) {
            return;
        }

        self::dispatch($broadcast->id);
    }

    public function handle(StartBroadcast $start): void
    {
        $broadcast = SmsBroadcast::query()->whereNull('started_at')->find($this->broadcastId);

        if ($broadcast?->scheduled_for === null || $this->waitingUntil($broadcast->scheduled_for)) {
            return;
        }

        $start($broadcast);
    }
}
