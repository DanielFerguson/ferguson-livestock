<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\PushesStopped;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushMessage;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com']));

/**
 * What the push package reports when Apple answers a push with this status. Like the package, it deletes the
 * sign-up first when Apple says it's gone.
 */
function failedPush(PushSubscription $subscription, int $status): NotificationFailed
{
    $report = new MessageSentReport(new Request('POST', $subscription->endpoint), new Response($status), false, 'Apple said no');

    if ($report->isSubscriptionExpired()) {
        $subscription->delete();
    }

    return new NotificationFailed($report, $subscription, new WebPushMessage);
}

it('emails the admin when the last phone signed up for pushes isn’t any more', function (int $status): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    event(failedPush($phone, $status));

    Notification::assertSentTo($admin, PushesStopped::class);
})->with([404, 410]);

it('stays quiet while another phone still gets pushes', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $oldPhone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');
    $admin->updatePushSubscription('https://web.push.apple.com/new-phone', 'BKey', 'authSecret', 'aes128gcm');

    event(failedPush($oldPhone, 410));

    Notification::assertNothingSent();
});

it('reports a push that fails for another reason as an error, without the lapsed-phone email', function (): void {
    Notification::fake();
    Exceptions::fake();
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $phone = $admin->updatePushSubscription('https://web.push.apple.com/old-phone', 'BKey', 'authSecret', 'aes128gcm');

    event(failedPush($phone, 403));

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), '403') && str_contains($exception->getMessage(), 'Apple said no'));
    Notification::assertNothingSent();
});
