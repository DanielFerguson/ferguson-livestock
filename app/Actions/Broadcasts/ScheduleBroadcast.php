<?php

declare(strict_types=1);

namespace App\Actions\Broadcasts;

use App\Exceptions\CannotChangeBroadcast;
use App\Models\SmsBroadcast;
use App\Sms\QuietHours;
use Carbon\CarbonImmutable;

/**
 * Sets when a broadcast goes out. The hourly scheduler starts it once the time comes (and a send within the next
 * hour is started by a timed job), so this is a commitment to text people.
 */
final class ScheduleBroadcast
{
    /**
     * Why it can't be scheduled for that time, or null when it can.
     */
    public function blocker(SmsBroadcast $broadcast, CarbonImmutable $at, bool $acknowledgeQuietHours): ?string
    {
        if (! $broadcast->status()->isEditable()) {
            return "This broadcast is {$broadcast->status()->getLabel()} and can no longer be scheduled.";
        }

        if (! $at->isFuture()) {
            return 'Pick a time in the future.';
        }

        if (QuietHours::contains($at) && ! $acknowledgeQuietHours) {
            return 'That time is between 8pm and 8am in Melbourne, when people may be asleep. Pick another time, or confirm the owner wants it.';
        }

        return null;
    }

    /**
     * @throws CannotChangeBroadcast
     */
    public function __invoke(SmsBroadcast $broadcast, CarbonImmutable $at, bool $acknowledgeQuietHours = false): SmsBroadcast
    {
        $blocker = $this->blocker($broadcast, $at, $acknowledgeQuietHours);

        if ($blocker !== null) {
            throw new CannotChangeBroadcast($blocker, 'Can’t schedule it');
        }

        $broadcast->update(['scheduled_for' => $at]);

        return $broadcast->refresh();
    }
}
