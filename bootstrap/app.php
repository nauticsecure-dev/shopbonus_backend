<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuditAccessDenials;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsureWorkspaceCommerciallyActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RejectAuthenticatedJsonRequests;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\RequireWorkspacePermission;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\ThrottleIdentityEndpoints;
use App\Http\Middleware\VerifyIntegrationSignature;
use App\Services\Errors\ErrorRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channel auth for SPA and token clients; account status is rechecked on every subscription.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'account.active']])
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the stack so even routing/auth failures carry a request ID.
        $middleware->prepend(AssignRequestId::class);
        $middleware->prependToGroup('api', [ForceJsonResponse::class, AuditAccessDenials::class]);

        // Sanctum SPA cookie auth for first-party frontends (SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();

        // Must wrap Fortify's route-level throttle:login to see its 429s.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ThrottleIdentityEndpoints::class);

        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            // Overrides Laravel's signed-link `verified`: Shopbonus verifies by emailed code.
            'verified' => EnsureEmailIsVerified::class,
            'guest' => RejectAuthenticatedJsonRequests::class,
            'workspace' => ResolveWorkspace::class,
            'workspace.can' => RequireWorkspacePermission::class,
            'workspace.commercial' => EnsureWorkspaceCommerciallyActive::class,
            'platform' => EnsurePlatformRole::class,
            'password.recent' => RequireRecentPassword::class,
            'integration.signed' => VerifyIntegrationSignature::class,
        ]);

        // API clients get a JSON 401 rather than a redirect to a login page.
        $middleware->redirectGuestsTo(fn (Request $request) => ApiExceptionRenderer::appliesTo($request) ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Intentional client-facing domain errors are not application faults.
        $exceptions->dontReport([ApiException::class, OriginMismatchException::class]);

        // Unexpected faults during an HTTP request go to application_errors.
        // Queue failures are recorded once, on final failure, by
        // App\Listeners\RecordFailedJob rather than on every attempt here.
        $exceptions->report(function (Throwable $e) {
            $request = app()->bound('request') ? app('request') : null;

            if ($request instanceof Request && $request->route() instanceof Route) {
                app(ErrorRecorder::class)->recordHttp($e, $request);
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::appliesTo($request),
        );

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
