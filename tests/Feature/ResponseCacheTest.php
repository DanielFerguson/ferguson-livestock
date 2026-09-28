<?php

use App\Http\ResponseCache\StripCookies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\ResponseCache\Facades\ResponseCache;
use Symfony\Component\HttpFoundation\Cookie;

it('serves the marketing pages from the cache', function (string $path) {
    $this->get($path)->assertOk();

    expect(ResponseCache::hasBeenCached(Request::create($path)))->toBeTrue();
})->with(['/', '/beef-boxes', '/our-story', '/delivery', '/faq', '/contact', '/privacy']);

it('never caches personal or transactional pages', function (string $path) {
    $this->get($path);

    expect(ResponseCache::hasBeenCached(Request::create($path)))->toBeFalse();
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

    expect(ResponseCache::hasBeenCached(Request::create('/faq')))->toBeTrue()
        ->and($second->getCookie(config()->string('session.cookie'), false)?->getValue())
        ->not->toBeNull()
        ->not->toBe($firstSession);
});

it('removes cookies before a response is stored', function () {
    $response = new Response('page');
    $response->headers->setCookie(Cookie::create('laravel_session', 'visitor-a'));
    $response->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token-a'));

    (new StripCookies)->prepareResponseToCache($response);

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});
