<?php

it('points the sitemap index at the page sitemap', function () {
    $this->get('/sitemap-index.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<sitemap><loc>https://www.fergusonlivestock.com.au/sitemap-0.xml</loc></sitemap>', false);
});

it('lists the same public pages as the Astro sitemap', function () {
    $xml = $this->get('/sitemap-0.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->getContent() ?: '';

    preg_match_all('#<loc>(.*?)</loc>#', $xml, $locations);

    expect($locations[1])->toBe([
        'https://www.fergusonlivestock.com.au',
        'https://www.fergusonlivestock.com.au/beef-boxes',
        'https://www.fergusonlivestock.com.au/contact',
        'https://www.fergusonlivestock.com.au/delivery',
        'https://www.fergusonlivestock.com.au/faq',
        'https://www.fergusonlivestock.com.au/our-story',
        'https://www.fergusonlivestock.com.au/privacy',
    ]);
});

it('is valid XML', function () {
    $xml = $this->get('/sitemap-0.xml')->getContent() ?: '';

    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
});

it('tells crawlers where the sitemap is and keeps them out of the API and admin', function () {
    $robots = (string) file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('Sitemap: https://www.fergusonlivestock.com.au/sitemap-index.xml')
        ->toContain('Disallow: /api/')
        ->toContain('Disallow: /admin');
});

it('keeps the web app manifest identity and brand colours', function () {
    /** @var array{name: string, short_name: string, background_color: string, theme_color: string} $manifest */
    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true);

    expect($manifest['name'])->toBe('Ferguson Livestock')
        ->and($manifest['short_name'])->not->toBeEmpty()
        ->and($manifest['background_color'])->toBe('#f5f2eb')
        ->and($manifest['theme_color'])->toBe('#1a2e1a');
});
