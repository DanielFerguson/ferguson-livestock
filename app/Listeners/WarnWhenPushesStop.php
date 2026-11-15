<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\PushesStopped;
use Illuminate\Support\Facades\Cache;
use NotificationChannels\WebPush\Events\NotificationFailed;
use RuntimeException;

/**
 * Makes sure a push that doesn't arrive never goes unnoticed. The push package swallows every failure into this event.
 *
 * When Apple says the last phone signed up isn't any more, the admin is emailed. The package has already deleted the
 * sign-up, and turning notifications off on purpose never reaches Apple. It's once a day at most: two pushes to the
 * same dead phone, or a phone that keeps saving a dead address, would otherwise email twice, and the banner on every
 * admin page covers a second lapse. An email that won't send is reported rather than handed back to the package, where
 * it would fail the push. Any other failure (wrong push keys, Apple having trouble) is reported as an error, so it
 * shows up in Nightwatch.
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

        if ($owner instanceof User && $owner->pushSubscriptions()->doesntExist() && Cache::add('pushes-stopped-emailed', true, now()->addDay())) {
            rescue(fn () => $owner->notify(new PushesStopped));
        }
    }
}
