<?php

namespace App\Support;

/**
 * Every public URL, for the sitemap and the site checks. Paths match the Astro site exactly.
 */
final class PublicPages
{
    /**
     * Indexable pages, in the order the sitemap lists them.
     *
     * @return list<string>
     */
    public static function indexable(): array
    {
        return ['/', '/beef-boxes', '/contact', '/delivery', '/faq', '/our-story', '/privacy'];
    }

    /**
     * Pages that render but ask search engines not to index them.
     *
     * @return list<string>
     */
    public static function noindex(): array
    {
        return ['/order', '/thank-you'];
    }

    /**
     * The absolute URL the sitemap uses; the homepage has no trailing slash, as before.
     */
    public static function sitemapUrl(string $path): string
    {
        $base = rtrim(config()->string('shop.url'), '/');

        return $path === '/' ? $base : $base.$path;
    }
}
