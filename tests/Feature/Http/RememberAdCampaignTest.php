<?php

declare(strict_types=1);

use App\Support\AdCampaign;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  TestResponse<Response>  $response
 */
function campaignCookie(TestResponse $response): ?Cookie
{
    return collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => $cookie->getName() === AdCampaign::COOKIE);
}

it('remembers an ad link’s UTM tags for 90 days, for every visitor to a cached page', function (): void {
    $this->freezeSecond();

    $this->get('/?utm_source=meta&utm_medium=paid&utm_campaign=First');
    $cached = $this->get('/?utm_source=Meta&utm_medium=Paid&utm_campaign=Spring+wait+list');

    $cookie = campaignCookie($cached);
    expect($cookie?->getValue())->toBe('source=meta&medium=paid&campaign=Spring+wait+list')
        ->and($cookie?->getExpiresTime())->toBe(now()->addDays(90)->getTimestamp())
        ->and($cookie?->isHttpOnly())->toBeTrue();
});

it('keeps the remembered tags through visits without any', function (): void {
    expect(campaignCookie($this->get('/beef-boxes?utm_medium=paid')))->toBeNull()
        ->and(campaignCookie($this->get('/beef-boxes')))->toBeNull();
});
