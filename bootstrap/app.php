<?php

declare(strict_types=1);

use App\Http\Middleware\PreventIndexingOutsideProduction;
use App\Http\Middleware\RemoveTrailingSlash;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel Cloud terminates TLS at its edge and load balancer. The edge replaces any X-Forwarded-For or
        // X-Forwarded-Host a visitor sends, so trusting every proxy still gives the visitor's own address.
        $middleware->trustProxies(at: '*');

        // Twilio signs its form fields exactly as it sends them, so its webhooks must see them untrimmed.
        $fromTwilio = fn (Request $request): bool => $request->is('api/webhooks/twilio/*');
        $middleware->trimStrings(except: [$fromTwilio]);
        $middleware->convertEmptyStringsToNull(except: [$fromTwilio]);

        // RemoveTrailingSlash comes after the proxy headers are read, so its redirect keeps the visitor on https.
        $middleware->append([SecurityHeaders::class, PreventIndexingOutsideProduction::class, RemoveTrailingSlash::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
