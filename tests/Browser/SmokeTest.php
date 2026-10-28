<?php

declare(strict_types=1);

use App\Support\PublicPages;

it('renders every public page in a real browser without errors', function (string $path): void {
    visit($path)
        ->assertSee('Ferguson Livestock')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with(fn (): array => [...PublicPages::indexable(), ...PublicPages::noindex()]);
