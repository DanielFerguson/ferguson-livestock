<?php

use App\Models\User;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com', 'shop.name' => 'Ferguson Livestock']));

it('brands the sign-in page with the shop name beside the logo and sets the site fonts', function () {
    $response = $this->get('/admin/login');

    $response->assertSee('<span class="fl-brand-name">Ferguson Livestock</span>', false);
    $response->assertSee("--font-family: 'Source Sans 3'", false);
    $response->assertSee("--serif-font-family: 'Cormorant Garamond'", false);
});

it('brands the dashboard and leaves sign-out to the user menu instead of a welcome card', function () {
    $admin = User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']);

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertSee('<span class="fl-brand-name">Ferguson Livestock</span>', false);
    $response->assertDontSee('fi-account-widget', false);
});
