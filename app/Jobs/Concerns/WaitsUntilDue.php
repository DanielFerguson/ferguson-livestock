<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Lets a queued job wait for its moment by putting a fresh copy of itself back on the queue.
 *
 * Laravel Cloud's managed queue delays a job by a quarter of an hour at most, so a job due later is looked at
 * again every quarter of an hour. Each look reads the record afresh, so a drop or broadcast that moves is followed.
 */
trait WaitsUntilDue
{
    /**
     * How far ahead the hourly commands time these jobs: a little over the hour between their runs.
     */
    public const int LOOKAHEAD_MINUTES = 70;

    private const int LONGEST_DELAY_MINUTES = 15;

    /**
     * @return bool whether the job is still waiting, in which case it must do nothing yet
     */
    protected function waitingUntil(CarbonInterface $dueAt): bool
    {
        // The sync queue (tests, or a machine without a worker) runs a job the moment it's dispatched, so waiting
        // would loop. The hourly commands pick the work up there instead.
        if ($this->job instanceof SyncJob) {
            return true;
        }

        if ($dueAt->lessThanOrEqualTo(now())) {
            return false;
        }

        $next = clone $this;
        $next->job = null;

        dispatch($next->delay($dueAt->min(now()->addMinutes(self::LONGEST_DELAY_MINUTES))));

        return true;
    }
}
