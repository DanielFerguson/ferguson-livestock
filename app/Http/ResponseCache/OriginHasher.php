<?php

namespace App\Http\ResponseCache;

use Illuminate\Http\Request;
use Spatie\ResponseCache\Hasher\DefaultHasher;

/**
 * Spatie's cache key, but built from the scheme, host and port instead of the host alone.
 *
 * Cached pages contain absolute links built from all three, and a client can claim a different port or scheme
 * through X-Forwarded-Port or X-Forwarded-Proto. Keyed on the host alone, one such request could store a page whose
 * links point at a dead port and have it served to every visitor.
 */
final class OriginHasher extends DefaultHasher
{
    public function getHashFor(Request $request): string
    {
        return hash('xxh128', implode('-', [
            'responsecache',
            $request->getSchemeAndHttpHost(),
            $this->getNormalizedRequestUri($request),
            $request->getMethod(),
            $this->getCacheNameSuffix($request),
        ]));
    }
}
