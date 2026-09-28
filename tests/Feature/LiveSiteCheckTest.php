<?php

use App\Support\LiveSiteCheck;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Answer the checker's requests from this app, as if it were deployed at the www address. The apex answers
 * with the redirect Laravel Cloud's domain settings add.
 *
 * @param  array<string, int>  $broken  path => status to answer with instead
 */
function serveThisApp(array $broken = []): void
{
    Http::fake(function (Request $request) use ($broken) {
        $url = parse_url($request->url());
        $path = ($url['path'] ?? '/').(isset($url['query']) ? "?{$url['query']}" : '');

        if (($url['host'] ?? '') === 'fergusonlivestock.com.au') {
            return Http::response('', 301, ['Location' => 'https://www.fergusonlivestock.com.au/']);
        }

        if (isset($broken[$url['path'] ?? '/'])) {
            return Http::response('Broken', $broken[$url['path'] ?? '/']);
        }

        // The web server answers for files in public/ before Laravel sees the request.
        if (is_file(public_path($url['path'] ?? '/'))) {
            return Http::response((string) file_get_contents(public_path($url['path'] ?? '/')), 200);
        }

        // Through the HTTP kernel directly, because the test client trims trailing slashes.
        $response = app(Kernel::class)->handle(HttpRequest::create($path));

        return Http::response((string) $response->getContent(), $response->getStatusCode(), $response->headers->allPreserveCase());
    });
}

beforeEach(fn () => $this->withoutVite());

it('passes a production site that keeps every URL and SEO signal', function () {
    app()->instance('env', 'production');
    serveThisApp();

    $failures = array_filter((new LiveSiteCheck('https://www.fergusonlivestock.com.au'))->run(), fn (array $check): bool => ! $check['passed']);

    expect($failures)->toBe([]);
});

it('reports what’s wrong, page by page', function () {
    app()->instance('env', 'production');
    serveThisApp(['/faq' => 500]);

    $failures = collect((new LiveSiteCheck('https://www.fergusonlivestock.com.au'))->run())->reject(fn (array $check): bool => $check['passed']);

    expect($failures->pluck('check')->all())->toContain('/faq loads');
});

it('catches a production site still asking search engines not to index it', function () {
    serveThisApp();

    $failures = collect((new LiveSiteCheck('https://www.fergusonlivestock.com.au'))->run())->reject(fn (array $check): bool => $check['passed']);

    expect($failures->pluck('check')->all())->toContain('/ can be indexed');
});

it('expects staging to be hidden from search engines', function () {
    serveThisApp();

    $failures = array_filter((new LiveSiteCheck('https://www.fergusonlivestock.com.au', staging: true))->run(), fn (array $check): bool => ! $check['passed']);

    expect($failures)->toBe([]);
});

it('fails the command when anything fails', function () {
    app()->instance('env', 'production');
    serveThisApp(['/sitemap-0.xml' => 404]);

    expect(Artisan::call('site:check', ['url' => 'https://www.fergusonlivestock.com.au']))->toBe(1)
        ->and(Artisan::output())->toContain('sitemap');
});
