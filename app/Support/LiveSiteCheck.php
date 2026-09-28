<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Checks a deployed copy of the site the way search engines and customers see it: every page and its SEO
 * signals, the old site's redirects, the security headers, the sitemap, and the live stock feed.
 *
 * Staging deliberately asks search engines not to index anything, so it's checked for that instead.
 */
final class LiveSiteCheck
{
    private const array SECURITY_HEADERS = ['X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options', 'Strict-Transport-Security'];

    /** @var array<string, Response|null> */
    private array $responses = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly bool $staging = false,
    ) {}

    /**
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    public function run(): array
    {
        $checks = [];

        foreach (PublicPages::indexable() as $path) {
            array_push($checks, ...$this->page($path, indexable: true));
        }

        foreach (PublicPages::noindex() as $path) {
            array_push($checks, ...$this->page($path, indexable: false));
        }

        $checks[] = $this->redirect('/our-story/', '/our-story', 'Trailing slashes redirect to the page');
        $checks[] = $this->redirect('/delivery-and-refunds', '/delivery#refunds', 'The old refunds page redirects');

        if (! $this->staging) {
            $checks[] = $this->apexRedirect();
        }

        return [...$checks, $this->securityHeaders(), $this->sitemap(), $this->robots(), $this->liveFeed(), $this->health()];
    }

    /**
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    private function page(string $path, bool $indexable): array
    {
        $response = $this->get($path);

        if ($response?->status() !== 200) {
            return [self::result("{$path} loads", false, $this->describe($response))];
        }

        $html = $response->body();
        $canonical = self::match('/<link rel="canonical" href="([^"]+)"/', $html);
        $expected = rtrim(config()->string('shop.url'), '/').($path === '/' ? '/' : $path);
        $robotsMeta = (string) self::match('/<meta name="robots" content="([^"]+)"/', $html);
        $robotsHeader = strtolower($response->header('X-Robots-Tag'));

        $checks = [
            self::result("{$path} loads", true, '200'),
            self::result("{$path} points search engines at the www address", $canonical === $expected, "canonical {$canonical}"),
        ];

        if ($this->staging) {
            $checks[] = self::result("{$path} is hidden from search engines", str_contains($robotsHeader, 'noindex'), "X-Robots-Tag: {$robotsHeader}");
        } elseif ($indexable) {
            $checks[] = self::result("{$path} can be indexed", ! str_contains($robotsHeader, 'noindex') && ! str_contains($robotsMeta, 'noindex'), "X-Robots-Tag: {$robotsHeader}, robots: {$robotsMeta}");
        } else {
            $checks[] = self::result("{$path} is kept out of search results", str_contains($robotsMeta, 'noindex'), "robots: {$robotsMeta}");
        }

        return $checks;
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function redirect(string $from, string $to, string $check): array
    {
        $response = $this->get($from);
        $location = parse_url($response?->header('Location') ?? '');
        $target = ($location['path'] ?? '').(isset($location['fragment']) ? "#{$location['fragment']}" : '');

        return self::result($check, $response?->status() === 301 && $target === $to, "{$from} → {$this->describe($response)} {$target}");
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function apexRedirect(): array
    {
        $www = rtrim(config()->string('shop.url'), '/');
        $apex = str_replace('://www.', '://', $www);
        $response = $this->fetch("{$apex}/");
        $location = $response?->header('Location') ?? '';

        return self::result('The address without www redirects to www', in_array($response?->status(), [301, 308], true) && str_starts_with($location, $www), "{$apex} → {$this->describe($response)} {$location}");
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function securityHeaders(): array
    {
        $response = $this->get('/');
        $missing = array_values(array_filter(self::SECURITY_HEADERS, fn (string $header): bool => $response?->header($header) === '' || $response === null));

        return self::result('Security headers are sent', $missing === [], $missing === [] ? 'all present' : 'missing '.implode(', ', $missing));
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function sitemap(): array
    {
        $index = $this->get('/sitemap-index.xml');
        $pages = $this->get('/sitemap-0.xml');
        $missing = array_values(array_filter(
            PublicPages::indexable(),
            fn (string $path): bool => ! str_contains($pages?->body() ?? '', '<loc>'.PublicPages::sitemapUrl($path).'</loc>'),
        ));

        $passed = $index?->status() === 200 && str_contains($index->body(), 'sitemap-0.xml') && $pages?->status() === 200 && $missing === [];

        return self::result('The sitemap lists every page', $passed, $missing === [] ? "index {$this->describe($index)}, pages {$this->describe($pages)}" : 'missing '.implode(', ', $missing));
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function robots(): array
    {
        $body = $this->get('/robots.txt')?->body() ?? '';
        $sitemap = 'Sitemap: '.rtrim(config()->string('shop.url'), '/').'/sitemap-index.xml';

        return self::result('robots.txt points to the sitemap and keeps crawlers out of the admin', str_contains($body, $sitemap) && str_contains($body, 'Disallow: /admin'), $sitemap);
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function liveFeed(): array
    {
        $response = $this->get('/api/drop');

        return self::result('The live stock feed answers', $response?->status() === 200 && is_string($response->json('server_time')), $this->describe($response));
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function health(): array
    {
        $response = $this->get('/up');

        return self::result('The health check passes', $response?->status() === 200, $this->describe($response));
    }

    private function get(string $path): ?Response
    {
        return $this->fetch(rtrim($this->baseUrl, '/').$path);
    }

    private function fetch(string $url): ?Response
    {
        if (! array_key_exists($url, $this->responses)) {
            try {
                $this->responses[$url] = Http::withoutRedirecting()->timeout(15)->withUserAgent('FergusonLivestockSiteCheck/1.0')->get($url);
            } catch (ConnectionException) {
                $this->responses[$url] = null;
            }
        }

        return $this->responses[$url];
    }

    private function describe(?Response $response): string
    {
        return $response === null ? 'no answer' : (string) $response->status();
    }

    private static function match(string $pattern, string $html): ?string
    {
        return preg_match($pattern, $html, $matches) === 1 ? html_entity_decode($matches[1]) : null;
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private static function result(string $check, bool $passed, string $detail): array
    {
        return ['check' => $check, 'passed' => $passed, 'detail' => $detail];
    }
}
