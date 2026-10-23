<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Every route lives under a version prefix (/api/v1). A future /api/v2 gets
| its own group; never change a released v1 contract in place.
|
| Access is default-deny: anything outside the explicit public block needs
| auth:sanctum + account.active, and everything except the email
| verification flow also needs `verified`. Every route's access level is
| declared as `x-access` in docs/api/openapi.yaml and checked by
| OpenApiCoverageTest.
|
| Session auth endpoints (/register, /login, /logout, /forgot-password,
| /reset-password, /user/password) are Fortify's native web routes.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    // Public
    Route::get('health', [HealthController::class, 'live'])->name('health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

    // Authenticated, verified or not (unverified users may only do this).
    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');

        Route::post('auth/email/verify-code', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:identity-verify-code')->name('auth.email.verify');
        Route::post('auth/email/resend-code', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:identity-resend-code')->name('auth.email.resend');

        // Authenticated + verified: all further private endpoints go here.
        Route::middleware('verified')->group(function () {
            // Workspaces. {workspace} is a webshop public ID or `current`; the
            // `workspace` middleware resolves it to the user's own membership.
            Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
            Route::post('workspaces', [WorkspaceController::class, 'store'])
                ->middleware('throttle:workspace-create')->name('workspaces.store');

            Route::prefix('workspaces/{workspace}')->middleware('workspace')->name('workspaces.')->group(function () {
                Route::get('/', [WorkspaceController::class, 'show'])->name('show');
                Route::patch('/', [WorkspaceController::class, 'update'])
                    ->middleware('workspace.can:settings.manage')->name('update');
                Route::post('select', [WorkspaceController::class, 'select'])->name('select');
            });
        });
    });
});
