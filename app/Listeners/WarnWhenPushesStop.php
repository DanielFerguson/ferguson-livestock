<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\PushesStopped;
use NotificationChannels\WebPush\Events\NotificationFailed;
use RuntimeException;

/**
 * Makes sure a push that doesn't arrive never goes unnoticed. The push package swallows every failure into this event.
 *
 * When Apple says the last phone signed up isn't any more, the admin is emailed. The package has already deleted the
 * sign-up, so that's once per lapse, and turning notifications off on purpose never reaches Apple. Any other failure
 * (wrong push keys, Apple having trouble) is reported as an error, so it shows up in Nightwatch.
 */
class WarnWhenPushesStop
{
    public function handle(NotificationFailed $event): void
    {
        if (! $event->report->isSubscriptionExpired()) {
            $status = $event->report->getResponse()?->getStatusCode() ?? 'no response';

            report(new RuntimeException("A push to the admin’s phone failed ({$status}): {$event->report->getReason()}"));

            return;
        }

        $owner = $event->subscription->subscribable;

        if ($owner instanceof User && $owner->pushSubscriptions()->doesntExist()) {
            $owner->notify(new PushesStopped);
        }
    }
}
