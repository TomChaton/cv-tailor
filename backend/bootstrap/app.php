<?php

use App\Http\RefusedCaptures;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The extension's contract. Versioned under `/api/v1`, so the prefix
        // here carries only `api` and the version lives in the route file.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Where the `auth` middleware sends a guest. The framework's default
        // is a route named `login`, which assumes an application with a web
        // login route; this one's login belongs to a panel, so that name is
        // nowhere in the route table and computing the redirect raises before
        // the authentication failure can be rendered at all.
        //
        // The API gets nothing, which is the whole point of configuring this:
        // `auth:sanctum` then raises an authentication failure that renders as
        // a 401, whatever headers the caller sent. An API that redirects a
        // rejected token answers a permanent refusal with something the caller
        // reads as the backend's own problem, and a client that retries the
        // backend's problems then retries a refusal for ever.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*')
                ? null
                : route('filament.review.auth.login'),
        );

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

        // A refusal is not a fault, so it is not reported — the framework
        // already keeps authentication failures out of the log, and a channel
        // that carries ordinary refusals is one the owner learns to skim. It
        // is still the one thing about a refused capture nobody can see from
        // anywhere else, so it is named as the warning it is. Returning null
        // leaves the framework to render the response it would have rendered.
        $exceptions->render(function (AuthenticationException $e, Request $request): ?Response {
            app(RefusedCaptures::class)->unauthenticated($request);

            return null;
        });
    })->create();
