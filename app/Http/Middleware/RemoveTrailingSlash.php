<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps one URL per page: `/path/` permanently redirects to `/path`, as it did on Vercel.
 */
class RemoveTrailingSlash
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if ($path === '/' || ! str_ends_with($path, '/') || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $url = $request->getSchemeAndHttpHost().$request->getBaseUrl().rtrim($path, '/');
        $query = $request->getQueryString();

        return redirect()->to($query === null ? $url : "{$url}?{$query}", 301);
    }
}
