<?php

declare(strict_types=1);

use App\Http\ResponseCache\StripCookies;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Spatie\ResponseCache\Facades\ResponseCache;
use Symfony\Component\HttpFoundation\Cookie;

it('serves the marketing pages from the cache', function (string $path) {
    $this->get($path)->assertOk();

    expect(ResponseCache::hasBeenCached(Request::create(url($path))))->toBeTrue();
})->with(['/', '/beef-boxes', '/our-story', '/delivery', '/faq', '/contact', '/privacy']);

it('doesn’t serve other visitors a page rendered for a forwarded port or scheme', function (string $url, array $headers, string $leak) {
    $this->get($url, $headers)->assertOk()->assertSee($leak, false);

    $this->get($url)->assertOk()->assertDontSee($leak, false);
})->with([
    'forwarded port' => ['https://shop.test/faq', ['X-Forwarded-Port' => '8444'], 'https://shop.test:8444/'],
    'forwarded scheme' => ['http://shop.test/faq', ['X-Forwarded-Proto' => 'https'], 'https://shop.test/'],
]);

it('stores one copy of a page whatever query string it’s asked for with', function () {
    $this->get('/faq?anything=1')->assertOk();

    expect(ResponseCache::hasBeenCached(Request::create(url('/faq'))))->toBeTrue();
});

it('never caches personal or transactional pages', function (string $path) {
    $this->get($path);

    expect(ResponseCache::hasBeenCached(Request::create(url($path))))->toBeFalse();
})->with(['/thank-you', '/order']);

it('shows wait-list errors even after the page was cached', function () {
    $this->get('/')->assertDontSee('Please check the details below.');

    $this->from('/')->post('/waitlist', ['first_name' => '', 'phone' => '0412 345 678', 'postcode' => '3350', 'sms_consent' => '1']);

    $this->get('/')->assertSee('Please check the details below.');
    $this->get('/')->assertDontSee('Please check the details below.');
});

it('gives each visitor their own session cookie on a cached page', function () {
    $first = $this->get('/faq');
    $firstSession = $first->getCookie(config()->string('session.cookie'), false)?->getValue();

    $this->flushSession();
    $second = $this->get('/faq');

    expect(ResponseCache::hasBeenCached(Request::create(url('/faq'))))->toBeTrue()
        ->and($second->getCookie(config()->string('session.cookie'), false)?->getValue())
        ->not->toBeNull()
        ->not->toBe($firstSession);
});

it('clears only the cached pages when the admin edits a product, not the rest of the cache', function () {
    $this->seed(ProductSeeder::class);
    $this->get('/beef-boxes')->assertOk();
    Cache::put('sessions-and-queued-jobs', 'kept');

    Product::firstOrFail()->update(['name' => 'Renamed box']);

    expect(ResponseCache::hasBeenCached(Request::create(url('/beef-boxes'))))->toBeFalse()
        ->and(Cache::get('sessions-and-queued-jobs'))->toBe('kept');
});

it('removes cookies before a response is stored', function () {
    $response = new Response('page');
    $response->headers->setCookie(Cookie::create('laravel_session', 'visitor-a'));
    $response->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token-a'));

    (new StripCookies)->prepareResponseToCache($response);

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});
