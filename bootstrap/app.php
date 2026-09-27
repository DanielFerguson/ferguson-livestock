<?php

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
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel Cloud terminates TLS at its edge and load balancer.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(RemoveTrailingSlash::class);
        $middleware->append([SecurityHeaders::class, PreventIndexingOutsideProduction::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
