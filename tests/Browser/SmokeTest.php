<?php

use App\Support\PublicPages;

it('renders every public page in a real browser without errors', function (string $path) {
    visit($path)
        ->assertSee('Ferguson Livestock')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with(fn () => [...PublicPages::indexable(), ...PublicPages::noindex()]);
