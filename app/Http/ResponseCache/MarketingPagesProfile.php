<?php

namespace App\Http\ResponseCache;

use Illuminate\Http\Request;
use Spatie\ResponseCache\CacheProfiles\CacheAllSuccessfulGetRequests;

/**
 * Caches successful GETs, except when the page has to show this visitor's form errors or typed input.
 */
class MarketingPagesProfile extends CacheAllSuccessfulGetRequests
{
    public function enabled(Request $request): bool
    {
        if (! parent::enabled($request)) {
            return false;
        }

        if (! $request->hasSession()) {
            return true;
        }

        $session = $request->session();

        return ! $session->has('errors') && ! $session->hasOldInput();
    }
}
