<?php

declare(strict_types=1);

use App\Filament\Pages\Conversations;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The admin app's notifications, in a real browser
|--------------------------------------------------------------------------
|
| Headless Chromium calls notifications blocked even when the browser context
| grants them, so turning them on can't be clicked through here. These check
| what can break without a phone: the push script loads, the control starts
| up and settles, and the service worker registers for the whole admin.
|
*/

beforeEach(function (): void {
    config([
        'shop.admin_email' => 'daniel@example.com',
        'webpush.vapid.public_key' => 'BPl86rmiY95h4CUWHMF0H94xRJlYedfIgmIQeshT_HUUNbsA2ZSsOc7RRiJLGDglJCXl-ZL1hwvmZuXTUj-Yyv8',
        'webpush.vapid.private_key' => '_VLo6s04XBHK0x4j0-sG0l_GKK9cAsSrvzAoXMYVTJ4',
    ]);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('starts the notifications control and registers the service worker for the whole admin', function (): void {
    $page = visit(Conversations::getUrl());

    $message = $page->script(<<<'JS'
        async () => {
            const control = document.querySelector('[x-data^="pushNotifications"]');

            if (control === null) {
                return null;
            }

            const shown = () => [...control.querySelectorAll(':scope > span')].filter((span) => span.offsetParent !== null).map((span) => span.textContent.trim()).join(' ');

            for (let attempt = 0; attempt < 100 && shown().includes('One moment…'); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return shown();
        }
    JS);

    $scope = $page->script(<<<'JS'
        async () => {
            await Promise.race([navigator.serviceWorker.ready, new Promise((resolve) => setTimeout(resolve, 5000))]);

            return (await navigator.serviceWorker.getRegistration('/admin'))?.scope ?? null;
        }
    JS);

    // A fresh browser has no sign-up yet, so it can only settle on one of these.
    expect($message)->toBeIn([
        'To get a notification for each new text, open this page in Safari on your iPhone, tap Share, then Add to Home Screen, and use Ferguson Livestock from your home screen.',
        'Notifications are blocked on this phone. Turn them on in Settings → Notifications → Ferguson Livestock.',
        'This phone doesn’t get notifications for new texts.',
    ])->and($scope)->toBeString()->toEndWith('/admin');

    $page->assertDontSee('One moment…')->assertNoJavaScriptErrors();
});
