<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\PushesStopped;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushMessage;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com']));

/**
 * Apple answers a push to this phone with this status, and the push package handles the answer as it would a real one.
 */
function failedPush(PushSubscription $subscription, int $status): void
{
    $report = new MessageSentReport(new Request('POST', $subscription->endpoint), new Response($status), false, 'Apple said no');

    app(ReportHandler::class)->handleReport($report, $subscription, new WebPushMessage);
}

it('emails the admin when the last phone signed up for pushes isn’t any more', function (int $status): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    failedPush($phone, $status);

    Notification::assertSentTo($admin, PushesStopped::class);
})->with([404, 410]);

it('stays quiet while another phone still gets pushes', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $oldPhone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');
    $admin->updatePushSubscription('https://web.push.apple.com/new-phone', 'BKey', 'authSecret', 'aes128gcm');

    failedPush($oldPhone, 410);

    Notification::assertNothingSent();
});

it('emails once a day however often the last phone lapses', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);

    failedPush($admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm'), 410);
    failedPush($admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm'), 410);

    Notification::assertSentToTimes($admin, PushesStopped::class, 1);
});

it('emails again when the last phone lapses the next day', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);

    failedPush($admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm'), 410);
    $this->travel(25)->hours();
    failedPush($admin->updatePushSubscription('https://web.push.apple.com/new-phone', 'BKey', 'authSecret', 'aes128gcm'), 410);

    Notification::assertSentToTimes($admin, PushesStopped::class, 2);
});

it('reports an email that won’t send, rather than failing the push', function (): void {
    Exceptions::fake();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Resend is down'));
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    failedPush($phone, 410);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Resend is down');
});

it('reports a push that fails for another reason as an error, without the lapsed-phone email', function (): void {
    Notification::fake();
    Exceptions::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    failedPush($phone, 403);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), '403') && str_contains($exception->getMessage(), 'Apple said no'));
    Notification::assertNothingSent();
});
