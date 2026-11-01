<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MetaVisitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the click ID Meta adds to its ad links (?fbclid=…) in Meta's own _fbc cookie, so a later wait-list sign-up
 * or purchase can be matched to the ad. The pixel does the same, but an ad blocker can stop the pixel and not this.
 *
 * It runs outside the response cache, so a cached page still sets the cookie for each visitor.
 */
class RememberMetaAdClick
{
    private const int DAYS = 90;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $clickId = $request->query('fbclid');

        if (! $request->isMethod('GET') || ! is_string($clickId) || preg_match('/^[A-Za-z0-9_-]{1,500}$/', $clickId) !== 1 || blank(config('services.meta.pixel_id'))) {
            return $response;
        }

        $current = $request->cookie(MetaVisitor::CLICK_COOKIE);

        if (is_string($current) && str_ends_with($current, ".{$clickId}")) {
            return $response;
        }

        // Meta's format: fb.{domain level}.{when, in milliseconds}.{click ID}. Set on the same domain as the pixel's
        // copy so the two replace each other rather than both being sent.
        $response->headers->setCookie(Cookie::create(
            name: MetaVisitor::CLICK_COOKIE,
            value: sprintf('fb.1.%d.%s', now()->getTimestampMs(), $clickId),
            expire: now()->addDays(self::DAYS),
            domain: $request->getHost(),
            secure: $request->isSecure(),
            httpOnly: false,
            raw: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
