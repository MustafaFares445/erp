<?php

declare(strict_types=1);

use DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API authentication is token-based through Sanctum. No stateful
        // SPA middleware is enabled: customer/employee mobile channels must
        // never inherit the administrator's session or CSRF trust boundary.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*'),
        );

        // Existing domain services deliberately throw DomainException for
        // rejected lifecycle/business transitions. Preserve that same domain
        // rule across channels while translating it into an API-safe 422
        // instead of leaking it as an internal-server error.
        $exceptions->render(function (DomainException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        });
    })->create();
