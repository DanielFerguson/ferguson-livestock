<?php

use Illuminate\Support\Facades\Route;

it('sends the security headers on every response', function (string $uri) {
    $this->get($uri)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('X-Frame-Options', 'DENY');
})->with([
    'a page' => '/',
    'a missing page' => '/not-a-real-page',
    'the health check' => '/up',
]);

it('tells browsers to use https for the next two years', function () {
    $this->get('/')->assertHeader('Strict-Transport-Security', 'max-age=63072000');
});

it('adds security headers to API responses', function () {
    Route::get('/api/example', fn () => ['ok' => true]);

    $this->getJson('/api/example')->assertHeader('X-Frame-Options', 'DENY');
});
