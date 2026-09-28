<?php

namespace App\Http\Controllers;

use App\Support\PublicPages;
use Illuminate\Http\Response;

/**
 * Serves the sitemap at the same URLs the Astro site used, which robots.txt and Search Console point to.
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        return response()
            ->view('sitemaps.index', ['sitemaps' => [PublicPages::sitemapUrl('/sitemap-0.xml')]])
            ->header('Content-Type', 'application/xml');
    }

    public function show(): Response
    {
        $urls = array_map(PublicPages::sitemapUrl(...), PublicPages::indexable());

        return response()
            ->view('sitemaps.pages', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }
}
