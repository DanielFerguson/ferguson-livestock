<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Order;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The short domain in order texts (`shop.short_domain`, e.g. fergs.au) answers nothing itself. Its links go on to the
 * order page on the main site, and anything else goes to the main site's home page.
 *
 * Global middleware rather than a domain route group, so it catches every request on the short domain before routing:
 * any method and any route (the health check, the API, the admin panel and packages' routes too), whatever order
 * they're registered in. Config is cached at deploy, so setting or changing SHOP_SHORT_DOMAIN takes effect on the next
 * deploy. The redirect looks nothing up, so it isn't throttled; the order page is.
 */
class RedirectShortDomain
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $mainSite = rtrim(config()->string('shop.url'), '/');

        if (! $this->isOnShortDomain($request, $mainSite)) {
            return $next($request);
        }

        $token = trim($request->getPathInfo(), '/');

        if (preg_match('/^'.Order::ACCESS_TOKEN_PATTERN.'$/', $token) === 1) {
            return redirect()->away($mainSite.route('orders.show', ['customerOrder' => $token], absolute: false));
        }

        return redirect()->away($mainSite);
    }

    /**
     * Whether the request came in on the short domain. Hosts arrive lowercased, so a setting typed as "Fergs.au" still
     * matches. A setting that names the main site's own host by mistake is ignored, or every page of the site would
     * send people round in a loop back to its home page.
     */
    private function isOnShortDomain(Request $request, string $mainSite): bool
    {
        $shortDomain = config('shop.short_domain');

        return is_string($shortDomain)
            && $shortDomain !== ''
            && $request->getHost() === strtolower($shortDomain)
            && $request->getHost() !== strtolower((string) parse_url($mainSite, PHP_URL_HOST));
    }
}
