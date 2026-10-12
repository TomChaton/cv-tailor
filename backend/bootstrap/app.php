<?php

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
        // Trusted proxies are configured in AppServiceProvider, where the
        // config repository is certain to have been loaded. This callback runs
        // when the HTTP kernel is resolved, which is earlier than that.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A reported exception is a log line like any other, and every line
        // names an event.
        $exceptions->context(fn (): array => ['event' => 'exception.reported']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
