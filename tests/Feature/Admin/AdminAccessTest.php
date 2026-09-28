<?php

use App\Models\User;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com']));

it('sends visitors to the sign-in page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets the admin in once two-factor sign-in is set up', function () {
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('makes the admin set up two-factor sign-in first', function () {
    $admin = User::factory()->create(['email' => 'daniel@example.com']);

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('multi-factor');
});

it('refuses anyone else, even with an account', function () {
    $someone = User::factory()->withAppAuthentication()->create(['email' => 'someone@example.com']);

    $this->actingAs($someone)->get('/admin')->assertForbidden();
});

it('refuses everyone when no admin email is configured', function () {
    config(['shop.admin_email' => null]);
    $user = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('keeps the admin out of search results in every environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/admin/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});
