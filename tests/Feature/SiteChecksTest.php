<?php

use App\Support\PublicPages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Site checks
|--------------------------------------------------------------------------
|
| Ported from the Astro site's scripts/check-site.mjs: publishing rules that
| every rendered page must follow.
|
*/

/**
 * Retired copy and claims docs/content/business-facts.md does not allow.
 *
 * @return array<string, string>
 */
function forbiddenCopy(): array
{
    return [
        'retired April dates' => '/early April|Saturday 4th April|Sunday 5th April/i',
        'free delivery' => '/delivery is always free|\bfree delivery\b/i',
        'boxes-only claim' => '/We only sell beef in boxes/i',
        'unverified rating' => '/ratingCount/i',
        'retired prices' => '/lowPrice|highPrice/i',
        'unapproved farming claims' => '/hormone|grass-fed|grass-finished|\borganic\b|dry-aged/i',
        'unconfirmed promises' => '/provenance guide|first dibs|best pasture-raised|every few months/i',
        'retired analytics' => '/website analytics/i',
    ];
}

/**
 * @return array<string, string>
 */
function trackingScripts(): array
{
    return [
        'Google Tag Manager' => '/googletagmanager\.com|\bgtag\s*\(/i',
        'Meta pixel' => '/connect\.facebook\.net|facebook\.com\/tr|\bfbq\s*\(/i',
        'PostHog' => '/posthog/i',
        'Hotjar' => '/hotjar/i',
    ];
}

function renderedPage(TestCase $test, string $path): string
{
    return $test->get($path)->assertOk()->getContent() ?: '';
}

dataset('pages', fn () => [...PublicPages::indexable(), ...PublicPages::noindex()]);

it('declares Australian English once', function (string $path) {
    expect(substr_count(renderedPage($this, $path), '<html lang="en-AU">'))->toBe(1);
})->with('pages');

it('has exactly one title, description, canonical link and h1', function (string $path) {
    $html = renderedPage($this, $path);

    expect(preg_match_all('/<title(\s[^>]*)?>/', $html))->toBe(1)
        ->and(substr_count($html, '<meta name="description"'))->toBe(1)
        ->and(substr_count($html, '<link rel="canonical"'))->toBe(1)
        ->and(preg_match_all('/<h1(\s[^>]*)?>/', $html))->toBe(1);
})->with('pages');

it('canonicalises to the www host', function (string $path) {
    $expected = 'https://www.fergusonlivestock.com.au'.($path === '/' ? '/' : $path);

    expect(renderedPage($this, $path))->toContain('<link rel="canonical" href="'.$expected.'">');
})->with('pages');

it('lets search engines index the public pages', function (string $path) {
    expect(renderedPage($this, $path))->toContain('<meta name="robots" content="index, follow">');
})->with(fn () => PublicPages::indexable());

it('keeps transactional pages out of search results', function (string $path) {
    expect(renderedPage($this, $path))->toContain('<meta name="robots" content="noindex, follow">');
})->with(fn () => PublicPages::noindex());

it('has no duplicate element ids', function (string $path) {
    preg_match_all('/\sid="([^"]+)"/', renderedPage($this, $path), $matches);

    expect(array_diff_assoc($matches[1], array_unique($matches[1])))->toBeEmpty();
})->with('pages');

it('gives every image alt text', function (string $path) {
    preg_match_all('/<img\b[^>]*>/', renderedPage($this, $path), $images);

    foreach ($images[0] as $image) {
        expect($image)->toMatch('/\salt="/');
    }
})->with('pages');

it('only contains valid structured data', function (string $path) {
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', renderedPage($this, $path), $schemas);

    foreach ($schemas[1] as $schema) {
        expect(json_decode($schema, true))->toBeArray();
    }
})->with('pages');

it('marks up the homepage business once and the FAQ only on /faq', function () {
    $home = renderedPage($this, '/');

    expect(substr_count($home, '"@type":"LocalBusiness"'))->toBe(1)
        ->and($home)->not->toContain('"@type":"Organization"')
        ->not->toContain('FAQPage')
        ->and(renderedPage($this, '/faq'))->toContain('"@type":"FAQPage"');
});

it('loads no tracking scripts', function (string $path) {
    $html = renderedPage($this, $path);

    foreach (trackingScripts() as $name => $pattern) {
        expect(preg_match($pattern, $html))->toBe(0, "{$path} loads {$name}");
    }
})->with('pages');

it('makes none of the retired or unapproved claims on any page', function (string $path) {
    $html = renderedPage($this, $path);

    foreach (forbiddenCopy() as $claim => $pattern) {
        expect(preg_match($pattern, $html))->toBe(0, "{$path} contains {$claim}");
    }
})->with('pages');

it('keeps retired claims out of the source too', function () {
    $files = collect([resource_path('views'), config_path(), app_path()])
        ->flatMap(fn (string $directory) => File::allFiles($directory));

    foreach ($files as $file) {
        foreach (forbiddenCopy() as $claim => $pattern) {
            expect(preg_match($pattern, $file->getContents()))->toBe(0, "{$file->getRelativePathname()} contains {$claim}");
        }
    }
});

it('links only to pages and sections that exist', function (string $path) {
    preg_match_all('/\shref="([^"]+)"/', renderedPage($this, $path), $matches);

    $appUrl = url('/');

    // Static files (icons, the manifest, built assets) are served by the web server and checked in UrlParityTest.
    $internal = collect($matches[1])
        ->map(fn (string $href) => Str::startsWith($href, $appUrl) ? (Str::after($href, $appUrl) ?: '/') : $href)
        ->filter(fn (string $href) => Str::startsWith($href, ['/', '#']) && ! Str::startsWith($href, '//'))
        ->reject(fn (string $href) => (bool) pathinfo(Str::before(Str::before($href, '#'), '?'), PATHINFO_EXTENSION) && ! Str::endsWith(Str::before($href, '#'), '.xml'))
        ->unique();

    foreach ($internal as $href) {
        $target = Str::before(Str::before($href, '#'), '?') ?: $path;
        $fragment = Str::contains($href, '#') ? Str::after($href, '#') : null;
        $html = $this->get($target)->assertOk()->getContent() ?: '';

        if ($fragment !== null) {
            expect(str_contains($html, 'id="'.$fragment.'"'))->toBeTrue("{$path} links to missing #{$fragment} on {$target}");
        }
    }
})->with('pages');

it('shows a friendly 404 that search engines skip', function () {
    $html = $this->get('/no-such-paddock')->assertNotFound()->getContent() ?: '';

    expect($html)->toContain('<meta name="robots" content="noindex, follow">')
        ->toContain('This page has wandered off.')
        ->toContain('href="'.route('contact').'"');
});
