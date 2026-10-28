<?php

declare(strict_types=1);

use App\Jobs\ProcessStripeEvent;
use App\Mail\OrderConfirmation;
use App\Notifications\JobFailedAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Notification;

/**
 * Laravel's own event for a job that has used up its tries.
 */
function failedJob(string $name = ProcessStripeEvent::class, string $message = 'Stripe said no'): JobFailed
{
    $job = new SyncJob(app(), (string) json_encode(['job' => $name, 'data' => []]), 'cloud', 'default');

    return new JobFailed('cloud', $job, new RuntimeException($message));
}

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com']));

it('emails the admin when a background job fails for good, saying which one and why', function (): void {
    Notification::fake();

    event(failedJob());

    Notification::assertSentOnDemand(
        JobFailedAlert::class,
        fn (JobFailedAlert $alert, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'daniel@example.com'
            && $alert->jobName === ProcessStripeEvent::class
            && $alert->reason === 'Stripe said no',
    );
});

it('sends the alert straight away rather than through the queue it is reporting on', function (): void {
    Notification::fake();

    event(failedJob());

    Notification::assertSentOnDemand(JobFailedAlert::class, fn (JobFailedAlert $alert): bool => ! $alert instanceof ShouldQueue);
});

it('sends one alert for a burst of failures, not one for each', function (): void {
    Notification::fake();

    event(failedJob());
    event(failedJob(OrderConfirmation::class));
    event(failedJob());

    Notification::assertSentOnDemandTimes(JobFailedAlert::class, 1);
});

it('alerts again once the quiet period has passed', function (): void {
    Notification::fake();

    event(failedJob());
    $this->travel(16)->minutes();
    event(failedJob());

    Notification::assertSentOnDemandTimes(JobFailedAlert::class, 2);
});

it('carries on quietly when there’s no admin email to tell', function (): void {
    Notification::fake();
    config(['shop.admin_email' => '']);

    event(failedJob());

    Notification::assertNothingSent();
});

it('never lets a broken alert break the job that is failing', function (): void {
    Notification::shouldReceive('route')->andThrow(new RuntimeException('Resend is down'));

    expect(fn () => event(failedJob()))->not->toThrow(RuntimeException::class);
});
