<?php

/*
|--------------------------------------------------------------------------
| URL parity
|--------------------------------------------------------------------------
|
| Every URL the Astro site served must keep working after the switch-over.
|
*/

it('keeps every page URL from the Astro site', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/', '/beef-boxes', '/contact', '/delivery', '/faq', '/our-story', '/privacy', '/thank-you', '/order']);

it('sends the retired refunds page to the refunds section of the delivery page', function () {
    $this->get('/delivery-and-refunds')->assertStatus(301)->assertRedirect('/delivery#refunds');
});

it('keeps the order confirmation URL')->todo('Arrives with checkout.');

it('keeps the files crawlers and browsers ask for by name', function (string $file) {
    expect(public_path($file))->toBeFile();
})->with([
    'robots.txt',
    'site.webmanifest',
    'og-image.jpg',
    'favicon.ico',
    'favicon-16x16.png',
    'favicon-32x32.png',
    'apple-touch-icon.png',
    'android-chrome-192x192.png',
    'android-chrome-512x512.png',
]);
