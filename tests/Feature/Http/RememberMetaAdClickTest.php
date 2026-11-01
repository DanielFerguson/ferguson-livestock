<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  TestResponse<Response>  $response
 */
function adClickCookie(TestResponse $response): ?Cookie
{
    return collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => $cookie->getName() === '_fbc');
}

it('keeps the click ID from a Meta ad link in Meta’s cookie, for every visitor to a cached page', function (): void {
    $this->freezeSecond();
    enableMetaAds();

    $this->get('https://fergusonlivestock.com.au/?fbclid=first-click');
    $cached = $this->get('https://fergusonlivestock.com.au/?fbclid=second-click');

    $cookie = adClickCookie($cached);
    expect($cookie?->getValue())->toBe('fb.1.'.now()->getTimestampMs().'.second-click')
        ->and($cookie?->getDomain())->toBe('fergusonlivestock.com.au')
        ->and($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->isHttpOnly())->toBeFalse()
        ->and($cookie?->getExpiresTime())->toBe(now()->addDays(90)->getTimestamp());
});

it('leaves the cookie alone when it already holds the same click', function (): void {
    enableMetaAds();

    $response = $this->withUnencryptedCookies(['_fbc' => 'fb.1.1700000000000.same-click'])->get('/?fbclid=same-click');

    expect(adClickCookie($response))->toBeNull();
});

it('sets no cookie without a usable click ID or a pixel', function (string $url, bool $pixel): void {
    if ($pixel) {
        enableMetaAds();
    }

    expect(adClickCookie($this->get($url)))->toBeNull();
})->with([
    'no click ID' => ['/', true],
    'malformed click ID' => ['/?fbclid=%3Cscript%3E', true],
    'no pixel' => ['/?fbclid=a-click', false],
]);
