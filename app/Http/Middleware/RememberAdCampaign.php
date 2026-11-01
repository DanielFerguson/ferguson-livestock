<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdCampaign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the UTM tags of the last tagged link a visitor arrived through, so a later wait-list sign-up or order
 * records which ad brought them. A visit without tags leaves the remembered ones alone.
 *
 * It runs outside the response cache, so a cached page still sets the cookie for each visitor.
 */
class RememberAdCampaign
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $campaign = $request->isMethod('GET') ? AdCampaign::fromQuery($request) : null;

        if ($campaign instanceof AdCampaign) {
            $response->headers->setCookie(Cookie::create(
                name: AdCampaign::COOKIE,
                value: $campaign->cookieValue(),
                expire: now()->addDays(AdCampaign::DAYS),
                secure: $request->isSecure(),
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
