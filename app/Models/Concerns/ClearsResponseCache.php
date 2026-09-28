<?php

namespace App\Models\Concerns;

use Spatie\ResponseCache\Facades\ResponseCache;

/**
 * Admin edits to products and drops change the marketing pages, so clear the cached copies.
 *
 * Stock movements use direct conditional updates, which don't fire model events, so a busy drop
 * doesn't keep emptying the cache.
 */
trait ClearsResponseCache
{
    public static function bootClearsResponseCache(): void
    {
        static::saved(fn () => ResponseCache::clear());
        static::deleted(fn () => ResponseCache::clear());
    }
}
