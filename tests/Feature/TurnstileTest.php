<?php

use App\Support\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('asks Cloudflare whether the token is genuine', function () {
    fakeTurnstile(genuine: true);

    expect(app(Turnstile::class)->passes('token-from-widget', '203.0.113.7'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === Turnstile::VERIFY_URL
        && $request->data() === ['secret' => 'test-secret-key', 'response' => 'token-from-widget', 'remoteip' => '203.0.113.7']);
});

it('fails a token Cloudflare rejects', function () {
    fakeTurnstile(genuine: false);

    expect(app(Turnstile::class)->passes('used-token', '203.0.113.7'))->toBeFalse();
});

it('fails a missing token without asking Cloudflare', function (mixed $token) {
    fakeTurnstile(genuine: true);

    expect(app(Turnstile::class)->passes($token, '203.0.113.7'))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'empty' => [''],
    'not a string' => [['token']],
    'too long' => [str_repeat('a', 2049)],
]);

it('lets people through when Cloudflare is down', function (Closure $answer) {
    config(['services.turnstile.site_key' => 'test-site-key', 'services.turnstile.secret_key' => 'test-secret-key']);
    Http::preventStrayRequests();
    Http::fake([Turnstile::VERIFY_URL => $answer]);

    expect(app(Turnstile::class)->passes('token-from-widget', '203.0.113.7'))->toBeTrue();
})->with([
    'unreachable' => [fn () => fn () => throw new ConnectionException('Timed out')],
    'server error' => [fn () => fn () => Http::response('', 503)],
]);

it('passes everything without asking Cloudflare until both keys are set', function (?string $siteKey, ?string $secretKey) {
    config(['services.turnstile.site_key' => $siteKey, 'services.turnstile.secret_key' => $secretKey]);
    Http::preventStrayRequests();

    expect(app(Turnstile::class)->passes('', '203.0.113.7'))->toBeTrue();
})->with([
    'neither' => [null, null],
    'only the secret' => [null, 'test-secret-key'],
]);
