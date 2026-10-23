<?php

use App\Http\Controllers\Api\V1\Admin\AgreementTemplateController as AdminAgreementTemplateController;
use App\Http\Controllers\Api\V1\Admin\WebshopController as AdminWebshopController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Integrations\ConnectionController;
use App\Http\Controllers\Api\V1\Integrations\WebhookController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Onboarding\AgreementController;
use App\Http\Controllers\Api\V1\Onboarding\BusinessDirectoryController;
use App\Http\Controllers\Api\V1\Onboarding\BusinessProfileController;
use App\Http\Controllers\Api\V1\Onboarding\OnboardingController;
use App\Http\Controllers\Api\V1\Onboarding\OwnershipVerificationController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Workspaces\InvitationController;
use App\Http\Controllers\Api\V1\Workspaces\MemberController;
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
    // Provider webhooks (public; authenticity proven by the connector's signature check).
    // One provider IP serves many merchants, so the per-IP api limiter is replaced by a per-connection one.
    Route::post('webhooks/{platform}/{token}', [WebhookController::class, 'receive'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:webhooks')->name('webhooks.receive');

    Route::post('invitations/preview', [InvitationController::class, 'preview'])
        ->middleware('throttle:invitation-preview')->name('invitations.preview');

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
            Route::get('integrations/platforms', [ConnectionController::class, 'platforms'])->name('integrations.platforms');

            // Business lookup (suggestions only; never proof of ownership).
            Route::middleware('throttle:business-search')->group(function () {
                Route::get('business-directory/search', [BusinessDirectoryController::class, 'search'])->name('business-directory.search');
                Route::get('business-directory/places/{placeId}', [BusinessDirectoryController::class, 'show'])->name('business-directory.show');
            });

            // Super Admin (privately provisioned). Mutations need a recent password confirmation (step-up until MFA, B16).
            Route::prefix('admin')->name('admin.')->middleware('platform:super_admin')->group(function () {
                Route::get('webshops', [AdminWebshopController::class, 'index'])->name('webshops.index');
                Route::get('webshops/{webshop}', [AdminWebshopController::class, 'show'])->name('webshops.show');
                Route::get('webshops/{webshop}/evidence/{file}', [AdminWebshopController::class, 'evidence'])->name('webshops.evidence');
                Route::get('agreement-templates', [AdminAgreementTemplateController::class, 'index'])->name('agreement-templates.index');

                Route::middleware('password.recent')->group(function () {
                    Route::post('webshops/{webshop}/approve', [AdminWebshopController::class, 'approve'])->name('webshops.approve');
                    Route::post('webshops/{webshop}/reject', [AdminWebshopController::class, 'reject'])->name('webshops.reject');
                    Route::post('webshops/{webshop}/suspend', [AdminWebshopController::class, 'suspend'])->name('webshops.suspend');
                    Route::post('webshops/{webshop}/unsuspend', [AdminWebshopController::class, 'unsuspend'])->name('webshops.unsuspend');
                    Route::post('agreement-templates', [AdminAgreementTemplateController::class, 'store'])->name('agreement-templates.store');
                    Route::patch('agreement-templates/{template}', [AdminAgreementTemplateController::class, 'update'])->name('agreement-templates.update');
                    Route::post('agreement-templates/{template}/publish', [AdminAgreementTemplateController::class, 'publish'])->name('agreement-templates.publish');
                });
            });

            Route::post('invitations/accept', [InvitationController::class, 'accept'])
                ->middleware('throttle:invitation-preview')->name('invitations.accept');

            Route::prefix('workspaces/{workspace}')->middleware('workspace')->name('workspaces.')->group(function () {
                Route::get('/', [WorkspaceController::class, 'show'])->name('show');
                Route::patch('/', [WorkspaceController::class, 'update'])
                    ->middleware('workspace.can:settings.manage')->name('update');
                Route::post('select', [WorkspaceController::class, 'select'])->name('select');

                // Team (Trello #16 "Team management").
                Route::get('members', [MemberController::class, 'index'])->middleware('workspace.can:team.view')->name('members.index');
                Route::delete('membership', [MemberController::class, 'leave'])->name('members.leave');
                Route::middleware('workspace.can:team.manage')->group(function () {
                    Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
                    Route::post('members/{member}/suspend', [MemberController::class, 'suspend'])->name('members.suspend');
                    Route::post('members/{member}/reactivate', [MemberController::class, 'reactivate'])->name('members.reactivate');
                    Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

                    Route::post('invitations', [InvitationController::class, 'store'])
                        ->middleware('throttle:invitation-create')->name('invitations.store');
                    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
                    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
                });
                Route::get('invitations', [InvitationController::class, 'index'])->middleware('workspace.can:team.view')->name('invitations.index');

                // Onboarding (Trello #16): business details, progress, ownership.
                Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
                Route::get('business-profile', [BusinessProfileController::class, 'show'])
                    ->middleware('workspace.can:settings.view')->name('business-profile.show');
                Route::put('business-profile', [BusinessProfileController::class, 'update'])
                    ->middleware('workspace.can:settings.manage')->name('business-profile.update');
                Route::middleware('workspace.can:ownership.manage')->prefix('ownership')->name('ownership.')->group(function () {
                    Route::get('verifications', [OwnershipVerificationController::class, 'index'])->name('verifications.index');
                    Route::post('verifications', [OwnershipVerificationController::class, 'store'])
                        ->middleware('throttle:ownership-check')->name('verifications.store');
                    Route::post('verifications/{verification}/check', [OwnershipVerificationController::class, 'check'])
                        ->middleware('throttle:ownership-check')->name('verifications.check');
                    Route::post('verifications/{verification}/confirm-code', [OwnershipVerificationController::class, 'confirmCode'])
                        ->middleware('throttle:ownership-check')->name('verifications.confirm-code');
                    Route::post('review', [OwnershipVerificationController::class, 'review'])->name('review');
                });
                // Ecommerce connections (Trello #1).
                Route::get('connections', [ConnectionController::class, 'index'])->middleware('workspace.can:integrations.view')->name('connections.index');
                Route::get('connections/{connection}', [ConnectionController::class, 'show'])->middleware('workspace.can:integrations.view')->name('connections.show');
                Route::get('connections/{connection}/events', [ConnectionController::class, 'events'])->middleware('workspace.can:integrations.view')->name('connections.events');
                Route::post('connections/{connection}/verify', [ConnectionController::class, 'verify'])->middleware('workspace.can:integrations.view')->name('connections.verify');
                Route::middleware('workspace.can:integrations.manage')->group(function () {
                    Route::post('connections', [ConnectionController::class, 'store'])->name('connections.store');
                    Route::post('connections/{connection}/credentials', [ConnectionController::class, 'replaceCredentials'])->name('connections.credentials');
                    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');
                    Route::post('connections/{connection}/events/{event}/retry', [ConnectionController::class, 'retryEvent'])->name('connections.events.retry');
                });

                // Live Order Sheet (Trello #1).
                Route::get('orders', [OrderController::class, 'index'])->middleware('workspace.can:orders.view')->name('orders.index');
                Route::get('orders/export', [OrderController::class, 'export'])
                    ->middleware(['workspace.can:orders.export', 'throttle:order-export'])->name('orders.export');
                Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('workspace.can:orders.view')->name('orders.show');

                Route::get('agreement', [AgreementController::class, 'show'])->middleware('workspace.can:settings.view')->name('agreement.show');
                Route::post('agreement/accept', [AgreementController::class, 'accept'])
                    ->middleware('workspace.can:agreements.accept')->name('agreement.accept');
            });
        });
    });
});
