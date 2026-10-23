<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
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
        // First in the stack so even routing/auth failures carry a request ID.
        $middleware->prepend(AssignRequestId::class);
        $middleware->prependToGroup('api', ForceJsonResponse::class);

        // API clients get a JSON 401 rather than a redirect to a login page.
        $middleware->redirectGuestsTo(fn (Request $request) => ApiExceptionRenderer::appliesTo($request) ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Intentional client-facing domain errors are not application faults.
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::appliesTo($request),
        );

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
