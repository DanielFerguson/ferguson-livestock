<?php

namespace App\Http\ResponseCache;

use Illuminate\Http\Request;
use Spatie\ResponseCache\Hasher\DefaultHasher;

/**
 * Spatie's cache key, but built from the scheme, host, port and path, without the query string.
 *
 * Cached pages contain absolute links built from the scheme, host and port, and a client can claim a different port
 * or scheme through X-Forwarded-Port or X-Forwarded-Proto. Keyed on the host alone, one such request could store a
 * page whose links point at a dead port and have it served to every visitor.
 *
 * The cached marketing pages never read the query string, so it's left out: otherwise every new ?anything=… would
 * store another full copy of the page in the cache that also holds sessions and rate limits.
 */
final class OriginHasher extends DefaultHasher
{
    public function getHashFor(Request $request): string
    {
        return hash('xxh128', implode('-', [
            'responsecache',
            $request->getSchemeAndHttpHost(),
            $request->getBaseUrl().$request->getPathInfo(),
            $request->getMethod(),
            $this->getCacheNameSuffix($request),
        ]));
    }
}
