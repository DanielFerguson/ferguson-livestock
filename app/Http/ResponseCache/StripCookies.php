<?php

namespace App\Http\ResponseCache;

use Spatie\ResponseCache\Replacers\Replacer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps one visitor's session and CSRF cookies out of a response that is served to everyone.
 *
 * The response is cached after the session middleware has added its cookies, so they are stripped
 * here; each cache hit then gets the current visitor's own cookies from that same middleware.
 */
class StripCookies implements Replacer
{
    public function prepareResponseToCache(Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        $response->headers->remove('Set-Cookie');
    }

    public function replaceInCachedResponse(Response $response): void {}
}
