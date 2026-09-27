<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Laravel's test client trims trailing slashes, so send raw requests through the HTTP kernel.
 *
 * @return TestResponse<Response>
 */
function sendRaw(string $method, string $uri): TestResponse
{
    return TestResponse::fromBaseResponse(
        app(Kernel::class)->handle(Request::create($uri, $method))
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

it('redirects HEAD requests too', function () {
    sendRaw('HEAD', '/contact/')->assertStatus(301);
});

it('leaves the homepage alone', function () {
    sendRaw('GET', '/')->assertOk();
});

it('does not redirect non-GET requests', function () {
    expect(sendRaw('POST', '/our-story/')->isRedirect())->toBeFalse();
});
