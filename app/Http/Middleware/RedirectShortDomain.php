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
 * they're registered in. It reads the config on each request, so setting or changing SHOP_SHORT_DOMAIN doesn't depend
 * on rebuilding the route cache. The redirect looks nothing up, so it isn't throttled; the order page is.
 */
class RedirectShortDomain
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shortDomain = config('shop.short_domain');

        if (! is_string($shortDomain) || $shortDomain === '' || $request->getHost() !== $shortDomain) {
            return $next($request);
        }

        $token = trim($request->getPathInfo(), '/');

        if (preg_match('/^'.Order::ACCESS_TOKEN_PATTERN.'$/', $token) === 1) {
            return redirect()->away(config()->string('shop.url').route('orders.show', ['customerOrder' => $token], absolute: false));
        }

        return redirect()->away(config()->string('shop.url'));
    }
}
