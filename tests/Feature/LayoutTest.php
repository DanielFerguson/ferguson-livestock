<?php

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $data
 */
function layoutPage(array $data = []): View
{
    return view()->file(base_path('tests/Fixtures/views/layout-page.blade.php'), $data);
}

beforeEach(function () {
    Route::get('/example-page', fn () => layoutPage(['description' => 'An example description.']));
});

it('declares Australian English', function () {
    $this->get('/example-page')->assertSee('<html lang="en-AU">', false);
});

it('renders exactly one title, description and canonical link', function () {
    $html = $this->get('/example-page')->getContent() ?: '';

    expect(substr_count($html, '<title>'))->toBe(1)
        ->and(substr_count($html, '<meta name="description"'))->toBe(1)
        ->and(substr_count($html, '<link rel="canonical"'))->toBe(1);
});

it('uses the page title and description', function () {
    $this->get('/example-page')
        ->assertSee('<title>Example page | Ferguson Livestock</title>', false)
        ->assertSee('<meta name="description" content="An example description.">', false);
});

it('falls back to the brand description', function () {
    Route::get('/no-description', fn () => layoutPage());

    $this->get('/no-description')
        ->assertSee('<meta name="description" content="'.e(config()->string('shop.brand.short_description')).'">', false);
});

it('points the canonical link at the www host without a trailing slash', function () {
    $this->get('/example-page')
        ->assertSee('<link rel="canonical" href="https://www.fergusonlivestock.com.au/example-page">', false);
});

it('keeps the homepage canonical with its trailing slash', function () {
    $this->get('/')
        ->assertSee('<link rel="canonical" href="https://www.fergusonlivestock.com.au/">', false);
});

it('is indexable by default', function () {
    $this->get('/example-page')->assertSee('<meta name="robots" content="index, follow">', false);
});

it('sends absolute social image URLs on the www host', function () {
    $this->get('/example-page')
        ->assertSee('<meta property="og:image" content="https://www.fergusonlivestock.com.au/og-image.jpg">', false)
        ->assertSee('<meta property="og:url" content="https://www.fergusonlivestock.com.au/example-page">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});

it('renders structured data as JSON that cannot break out of its script tag', function () {
    $name = '</script><script>alert(1)</script>';

    Route::get('/with-schema', fn () => layoutPage([
        'structuredData' => ['@context' => 'https://schema.org', 'name' => $name],
    ]));

    $html = $this->get('/with-schema')->getContent() ?: '';
    $json = Str::betweenFirst($html, '<script type="application/ld+json">', '</script>');

    expect($json)->not->toContain('</script>')
        ->and(json_decode($json, true))->toMatchArray(['name' => $name]);
});

it('renders one script tag per structured data object', function () {
    Route::get('/with-schemas', fn () => layoutPage([
        'structuredData' => [['@type' => 'WebPage'], ['@type' => 'Product']],
    ]));

    $html = $this->get('/with-schemas')->getContent() ?: '';

    expect(substr_count($html, '<script type="application/ld+json">'))->toBe(2);
});

it('links a skip link to the main content', function () {
    $this->get('/example-page')
        ->assertSee('href="#main-content"', false)
        ->assertSee('<main id="main-content">', false);
});
