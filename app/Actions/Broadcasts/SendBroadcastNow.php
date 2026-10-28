<?php

declare(strict_types=1);

namespace App\Actions\Broadcasts;

use App\Actions\StartBroadcast;
use App\Exceptions\CannotChangeBroadcast;
use App\Models\SmsBroadcast;
use App\Sms\QuietHours;

/**
 * Starts texting a broadcast's audience right now.
 */
final readonly class SendBroadcastNow
{
    public function __construct(private StartBroadcast $start) {}

    /**
     * Why it can't be sent now, or null when it can.
     */
    public function blocker(SmsBroadcast $broadcast, bool $acknowledgeQuietHours): ?string
    {
        if (! $broadcast->status()->isEditable()) {
            return "This broadcast is {$broadcast->status()->getLabel()}, so it can't be sent again.";
        }

        if ($broadcast->recipients()->doesntExist()) {
            return 'No one subscribed matches this audience.';
        }

        if (QuietHours::contains(now()) && ! $acknowledgeQuietHours) {
            return 'It is between 8pm and 8am in Melbourne, when people may be asleep. Wait until morning, or confirm the owner wants it sent now.';
        }

        return null;
    }

    /**
     * @return int how many texts were queued
     *
     * @throws CannotChangeBroadcast
     */
    public function __invoke(SmsBroadcast $broadcast, bool $acknowledgeQuietHours = false): int
    {
        $blocker = $this->blocker($broadcast, $acknowledgeQuietHours);

        if ($blocker !== null) {
            throw new CannotChangeBroadcast($blocker, $broadcast->recipients()->doesntExist() ? 'Nobody to send to' : 'Can’t send it');
        }

        $queued = ($this->start)($broadcast);

        if ($queued === null) {
            throw new CannotChangeBroadcast('This broadcast had already started sending.', 'Already sending');
        }

        return $queued;
    }
}
