<?php

declare(strict_types=1);

use App\Support\PublicPages;

it('shows a friendly page, not an error, for an address that doesn’t exist', function (): void {
    visit('/no-such-paddock')
        ->assertSee('Ferguson Livestock')
        ->assertNoJavaScriptErrors();
});

it('keeps the old addresses working', function (string $from, string $to): void {
    visit($from)->assertPathIs($to)->assertNoJavaScriptErrors();
})->with([
    'a trailing slash' => ['/our-story/', '/our-story'],
    'the old refunds page' => ['/delivery-and-refunds', '/delivery'],
]);

it('opens the menu on a phone and reaches every page from it', function (): void {
    visit('/')->on()->mobile()
        ->assertNoSmoke()
        ->click('summary:has-text("Menu")')
        ->assertSee('Our story')
        ->assertNoJavaScriptErrors();
});

it('renders every public page at phone width without errors', function (string $path): void {
    visit($path)->on()->mobile()->assertSee('Ferguson Livestock')->assertNoSmoke();
})->with(fn (): array => [...PublicPages::indexable(), ...PublicPages::noindex()]);

it('passes an accessibility check on the pages people use most', function (string $path): void {
    visit($path)->assertNoAccessibilityIssues();
})->with(['/', '/beef-boxes', '/order', '/thank-you', '/delivery', '/faq']);
