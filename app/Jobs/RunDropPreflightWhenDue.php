<?php

namespace App\Jobs;

use App\Actions\CheckDropBeforeItOpens;
use App\Jobs\Concerns\WaitsUntilDue;
use App\Models\Drop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks a drop ten minutes before it opens. Queued when a drop is published to open within the hour, and by the
 * hourly drops:preflight for drops coming up, which also checks any whose timed check went missing.
 */
class RunDropPreflightWhenDue implements ShouldQueue
{
    use Queueable, WaitsUntilDue;

    public function __construct(public int $dropId) {}

    /**
     * Time a check for the drop if it's published, not yet checked, and opens within the hour.
     */
    public static function timeFor(Drop $drop): void
    {
        if ($drop->published_at === null || $drop->preflight_ran_at !== null || ! $drop->opens_at->between(now(), now()->addMinutes(self::LOOKAHEAD_MINUTES))) {
            return;
        }

        self::dispatch($drop->id);
    }

    public function handle(CheckDropBeforeItOpens $check): void
    {
        $drop = Drop::query()->published()->whereNull('preflight_ran_at')->where('opens_at', '>', now())->find($this->dropId);

        if ($drop === null || $this->waitingUntil($drop->opens_at->subMinutes(CheckDropBeforeItOpens::MINUTES_BEFORE_OPENING))) {
            return;
        }

        $check($drop);
    }
}
