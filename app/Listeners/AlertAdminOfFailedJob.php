<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notifications\JobFailedAlert;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails the admin when any background job fails for good. A failed order email or Stripe event is otherwise
 * silent: the customer gets nothing, and nobody is told. At most one email goes out every 15 minutes, so a
 * wave of failures doesn't flood the inbox.
 */
class AlertAdminOfFailedJob
{
    private const int QUIET_MINUTES = 15;

    public function handle(JobFailed $event): void
    {
        $email = config('shop.admin_email');

        if (! is_string($email) || $email === '') {
            return;
        }

        Log::error('A background job failed.', ['job' => $event->job->resolveName(), 'reason' => $event->exception->getMessage()]);

        if (! Cache::add('failed-job-alert-sent', true, now()->addMinutes(self::QUIET_MINUTES))) {
            return;
        }

        try {
            Notification::route('mail', $email)->notifyNow(new JobFailedAlert($event->job->resolveName(), $event->exception->getMessage()));
        } catch (Throwable $exception) {
            // The alert is a courtesy: it must never become a second failure.
            Log::error('Couldn’t email the admin about a failed job.', ['error' => $exception->getMessage()]);
        }
    }
}
