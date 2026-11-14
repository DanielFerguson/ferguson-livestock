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
| up and settles, and the service worker registers for the whole admin. The
| public key is a real one so the browser would accept it; the private key is
| a placeholder, since only the server signs with it.
|
*/

beforeEach(function (): void {
    config([
        'shop.admin_email' => 'daniel@example.com',
        'webpush.vapid.public_key' => 'BPl86rmiY95h4CUWHMF0H94xRJlYedfIgmIQeshT_HUUNbsA2ZSsOc7RRiJLGDglJCXl-ZL1hwvmZuXTUj-Yyv8',
        'webpush.vapid.private_key' => 'PrivateKey',
    ]);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('starts the notifications control and registers the service worker for the whole admin', function (): void {
    $page = visit(Conversations::getUrl());

    $state = $page->script(<<<'JS'
        async () => {
            const control = document.querySelector('[x-data^="pushNotifications"]');

            if (control === null || window.Alpine === undefined) {
                return null;
            }

            const state = () => window.Alpine.$data(control).state;

            for (let attempt = 0; attempt < 100 && state() === 'checking'; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return state();
        }
    JS);

    $scope = $page->script(<<<'JS'
        async () => {
            await Promise.race([navigator.serviceWorker.ready, new Promise((resolve) => setTimeout(resolve, 5000))]);

            return (await navigator.serviceWorker.getRegistration('/admin'))?.scope ?? null;
        }
    JS);

    // A fresh browser has no sign-up yet. Headless Chromium settles on denied; a headed one would ask, so it's off.
    expect($state)->toBeIn(['denied', 'off'])
        ->and($scope)->toBeString()->toEndWith('/admin');

    $page->assertDontSee('One moment…')->assertNoJavaScriptErrors();
});

it('takes the warning banner down when the push script says this phone is signed up', function (): void {
    $page = visit(Conversations::getUrl())->assertVisible('.fl-push-warning');

    $page->script("() => window.dispatchEvent(new CustomEvent('push-subscribed'))");

    $page->assertMissing('.fl-push-warning')->assertNoJavaScriptErrors();
});
