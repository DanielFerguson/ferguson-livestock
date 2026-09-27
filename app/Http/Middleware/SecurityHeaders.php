<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers previously set by vercel.json, plus the HSTS header Vercel added itself.
 */
class SecurityHeaders
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Frame-Options' => 'DENY',
        'Strict-Transport-Security' => 'max-age=63072000',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add(self::HEADERS);

        return $response;
    }
}
