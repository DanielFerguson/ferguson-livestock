<?php

namespace App\Models\Concerns;

use App\Stock\DropSnapshot;
use Spatie\ResponseCache\Facades\ResponseCache;

/**
 * Admin edits to products and drops change the marketing pages and the live stock feed, so clear the cached
 * copies of both.
 *
 * Stock movements use direct conditional updates, which don't fire model events, so a busy drop
 * doesn't keep emptying the page cache. The stock ledger clears the feed itself.
 */
trait ClearsResponseCache
{
    public static function bootClearsResponseCache(): void
    {
        $clear = function (): void {
            ResponseCache::clear();
            DropSnapshot::forget();
        };

        static::saved($clear);
        static::deleted($clear);
    }
}
