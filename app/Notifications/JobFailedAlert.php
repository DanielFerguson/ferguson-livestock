<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the admin a background job has failed for good: an order email that didn't send, a Stripe event that
 * didn't process. It's sent straight away, not queued, because the queue is what has just failed.
 */
class JobFailedAlert extends Notification
{
    public function __construct(public string $jobName, public string $reason) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A background job failed on the Ferguson Livestock site')
            ->greeting('Something needs a look.')
            ->line("The job {$this->jobName} failed for good after its retries.")
            ->line("The reason: {$this->reason}")
            ->line('This could be an order email that didn’t send or a Stripe payment that wasn’t processed. Check Stripe Events and the failed jobs in Laravel Cloud.')
            ->line('You get one of these emails every 15 minutes at most, however many jobs fail.');
    }
}
