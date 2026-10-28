<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only when it carries a live MCP token that belongs to the admin.
 */
final class AuthenticateMcpToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();

        $token = is_string($plainText) ? McpToken::findUsable($plainText) : null;

        $panel = Filament::getPanel('admin');

        if (! $token instanceof McpToken || ! $token->user->canAccessPanel($panel)) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // Once a minute is plenty to see when a token was last used, without a write on every call.
        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now(), absolute: true) >= 60) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set(McpToken::REQUEST_ATTRIBUTE, $token);

        return $next($request);
    }
}
