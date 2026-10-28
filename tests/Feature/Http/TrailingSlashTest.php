<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Laravel's test client trims trailing slashes, so send raw requests through the HTTP kernel.
 *
 * @param  array<string, string>  $server
 * @return TestResponse<Response>
 */
function sendRaw(string $method, string $uri, array $server = []): TestResponse
{
    return TestResponse::fromBaseResponse(
        app(Kernel::class)->handle(Request::create($uri, $method, server: $server))
    );
}

it('permanently redirects a trailing slash to the path without it', function () {
    sendRaw('GET', '/our-story/')
        ->assertStatus(301)
        ->assertHeader('Location', 'http://localhost/our-story');
});

it('keeps the query string when removing a trailing slash', function () {
    sendRaw('GET', '/faq/?ref=newsletter')
        ->assertStatus(301)
        ->assertHeader('Location', 'http://localhost/faq?ref=newsletter');
});

it('collapses several trailing slashes', function () {
    sendRaw('GET', '/delivery///')
        ->assertStatus(301)
        ->assertHeader('Location', 'http://localhost/delivery');
});

it('stays on https behind Laravel Cloud’s proxy', function () {
    // Each real request starts with no trusted proxies; an earlier test's request mustn't have set them.
    Request::setTrustedProxies([], 0);

    sendRaw('GET', 'http://fergusonlivestock.com.au/our-story/', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'])
        ->assertStatus(301)
        ->assertHeader('Location', 'https://fergusonlivestock.com.au/our-story');
});

it('redirects HEAD requests too', function () {
    sendRaw('HEAD', '/contact/')->assertStatus(301);
});

it('leaves the homepage alone', function () {
    sendRaw('GET', '/')->assertOk();
});

it('does not redirect non-GET requests', function () {
    expect(sendRaw('POST', '/our-story/')->isRedirect())->toBeFalse();
});
