<?php

declare(strict_types=1);

use App\Models\SmsMessage;
use App\Models\User;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com', 'webpush.vapid.public_key' => 'BPublicKey', 'webpush.vapid.private_key' => 'PrivateKey']));

it('lets the admin be added to a phone’s home screen as its own app', function (): void {
    $this->get('/admin/login')
        ->assertSee('<link rel="manifest" href="/admin.webmanifest">', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Ferguson Livestock">', false)
        ->assertDontSee('admin-attention-count', false);
});

it('keeps the whole admin inside the installed app, the dashboard at /admin included', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('admin.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray([
        'name' => 'Ferguson Livestock',
        'short_name' => 'Ferguson Livestock',
        'scope' => '/admin',
        'start_url' => '/admin/conversations',
        'display' => 'standalone',
    ]);
});

it('gives the app icon the number of conversations that need a look', function (): void {
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);
    SmsMessage::factory()->count(2)->reply()->create(['subscriber_id' => null]);

    $this->actingAs($admin)->get('/admin')->assertSee('<meta name="admin-attention-count" content="2">', false);
});

it('warns on every admin page while no phone gets pushes for new texts', function (): void {
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $this->actingAs($admin)->get('/admin')->assertSee('No phone gets a notification for new texts');

    $admin->updatePushSubscription('https://web.push.apple.com/QGuHRmBXgM', 'BKey', 'authSecret', 'aes128gcm');

    $this->actingAs($admin)->get('/admin')->assertDontSee('No phone gets a notification for new texts');
});

it('doesn’t warn before the push keys are set', function (): void {
    config(['webpush.vapid.public_key' => null]);
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $this->actingAs($admin)->get('/admin')->assertDontSee('No phone gets a notification for new texts');
});
