<?php

use App\Http\Controllers\Api\V1\Admin\AffiliateController as AdminAffiliateController;
use App\Http\Controllers\Api\V1\Admin\AgreementTemplateController as AdminAgreementTemplateController;
use App\Http\Controllers\Api\V1\Admin\WebshopController as AdminWebshopController;
use App\Http\Controllers\Api\V1\Affiliate\PostbackController;
use App\Http\Controllers\Api\V1\Affiliate\VisitController;
use App\Http\Controllers\Api\V1\Answer24\ContactSettingsController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Claims\ClaimController;
use App\Http\Controllers\Api\V1\Consumer\AffiliateActivityController;
use App\Http\Controllers\Api\V1\Consumer\BenefitsController;
use App\Http\Controllers\Api\V1\Consumer\EmailChangeController;
use App\Http\Controllers\Api\V1\Consumer\PreferenceController;
use App\Http\Controllers\Api\V1\Consumer\PrivacyController;
use App\Http\Controllers\Api\V1\Consumer\ProfileController;
use App\Http\Controllers\Api\V1\Consumer\WalletController;
use App\Http\Controllers\Api\V1\Discovery\PublicDiscoveryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Integrations\Answer24Controller;
use App\Http\Controllers\Api\V1\Integrations\ConnectionController;
use App\Http\Controllers\Api\V1\Integrations\WebhookController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Offers\OfferController;
use App\Http\Controllers\Api\V1\Offers\VoucherCodeController;
use App\Http\Controllers\Api\V1\Onboarding\AgreementController;
use App\Http\Controllers\Api\V1\Onboarding\BusinessDirectoryController;
use App\Http\Controllers\Api\V1\Onboarding\BusinessProfileController;
use App\Http\Controllers\Api\V1\Onboarding\OnboardingController;
use App\Http\Controllers\Api\V1\Onboarding\OwnershipVerificationController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Workspaces\InvitationController;
use App\Http\Controllers\Api\V1\Workspaces\MemberController;
use App\Http\Controllers\Api\V1\Workspaces\PublicProfileController;
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

    // Answer24 → Shopbonus (server to server; scoped key + signature, Trello #23).
    Route::prefix('integrations/answer24')->name('integrations.answer24.')
        ->withoutMiddleware('throttle:api')->middleware('throttle:integrations')->group(function () {
            Route::post('contact-results', [Answer24Controller::class, 'contactResult'])
                ->middleware('integration.signed:answer24.contact_results')->name('contact-results.store');
            Route::post('handoffs', [Answer24Controller::class, 'handoff'])
                ->middleware('integration.signed:answer24.handoffs')->name('handoffs.store');
            Route::get('claims/{claim}', [Answer24Controller::class, 'claimStatus'])
                ->middleware('integration.signed:answer24.claims')->name('claims.show');
            Route::post('claims/{claim}/reminders', [Answer24Controller::class, 'reminder'])
                ->middleware('integration.signed:answer24.claims')->name('claims.reminders.store');
        });

    // Public discovery (Trello #18): published data of approved webshops only.
    Route::prefix('public')->name('public.')->group(function () {
        Route::get('categories', [PublicDiscoveryController::class, 'categories'])->name('categories.index');
        Route::get('webshops', [PublicDiscoveryController::class, 'webshops'])->name('webshops.index');
        Route::get('webshops/{slug}', [PublicDiscoveryController::class, 'webshop'])->name('webshops.show');
        Route::get('webshops/{slug}/logo', [PublicDiscoveryController::class, 'logo'])->name('webshops.logo');
        // Affiliate "Visit webshop": redirect through the selected network (Trello #19). Signed-in consumers are attributed.
        Route::get('webshops/{slug}/visit', [VisitController::class, 'visit'])->middleware('throttle:visits')->name('webshops.visit');
        Route::get('offers', [PublicDiscoveryController::class, 'offers'])->name('offers.index');
        Route::get('offers/{slug}', [PublicDiscoveryController::class, 'offer'])->name('offers.show');
    });

    // Affiliate network postback: secret token in the URL, untrusted (records "tracked" only).
    Route::match(['get', 'post'], 'affiliate/postback/{token}', [PostbackController::class, 'receive'])
        ->withoutMiddleware('throttle:api')->middleware('throttle:webhooks')->name('affiliate.postback');

    // Claim link preview (public; the token is the secret).
    Route::post('claims/preview', [ClaimController::class, 'preview'])->middleware('throttle:claims')->name('claims.preview');

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
                Route::prefix('affiliate')->name('affiliate.')->group(function () {
                    Route::get('connections', [AdminAffiliateController::class, 'connections'])->name('connections.index');
                    Route::get('programs', [AdminAffiliateController::class, 'programs'])->name('programs.index');
                    Route::get('webshops/{webshop}/selections', [AdminAffiliateController::class, 'selections'])->name('selections.index');
                    Route::get('transactions', [AdminAffiliateController::class, 'transactions'])->name('transactions.index');
                    Route::get('transactions/{transaction}', [AdminAffiliateController::class, 'showTransaction'])->name('transactions.show');
                    Route::get('reward-rules', [AdminAffiliateController::class, 'rules'])->name('reward-rules.index');
                });

                Route::middleware('password.recent')->group(function () {
                    Route::post('webshops/{webshop}/approve', [AdminWebshopController::class, 'approve'])->name('webshops.approve');
                    Route::post('webshops/{webshop}/reject', [AdminWebshopController::class, 'reject'])->name('webshops.reject');
                    Route::post('webshops/{webshop}/suspend', [AdminWebshopController::class, 'suspend'])->name('webshops.suspend');
                    Route::post('webshops/{webshop}/unsuspend', [AdminWebshopController::class, 'unsuspend'])->name('webshops.unsuspend');
                    Route::post('agreement-templates', [AdminAgreementTemplateController::class, 'store'])->name('agreement-templates.store');
                    Route::patch('agreement-templates/{template}', [AdminAgreementTemplateController::class, 'update'])->name('agreement-templates.update');
                    Route::post('agreement-templates/{template}/publish', [AdminAgreementTemplateController::class, 'publish'])->name('agreement-templates.publish');
                    Route::prefix('affiliate')->name('affiliate.')->group(function () {
                        Route::post('connections', [AdminAffiliateController::class, 'storeConnection'])->name('connections.store');
                        Route::post('connections/{connection}/test', [AdminAffiliateController::class, 'testConnection'])->name('connections.test');
                        Route::post('connections/{connection}/disable', [AdminAffiliateController::class, 'disableConnection'])->name('connections.disable');
                        Route::post('connections/{connection}/sync', [AdminAffiliateController::class, 'sync'])->name('connections.sync');
                        Route::post('connections/{connection}/import', [AdminAffiliateController::class, 'import'])->name('connections.import');
                        Route::patch('programs/{program}', [AdminAffiliateController::class, 'updateProgram'])->name('programs.update');
                        Route::post('webshops/{webshop}/selections', [AdminAffiliateController::class, 'select'])->name('selections.store');
                        Route::post('transactions/{transaction}/resolve', [AdminAffiliateController::class, 'resolve'])->name('transactions.resolve');
                        Route::post('reward-rules', [AdminAffiliateController::class, 'storeRule'])->name('reward-rules.store');
                        Route::post('reward-rules/{version}/deactivate', [AdminAffiliateController::class, 'deactivateRule'])->name('reward-rules.deactivate');
                    });
                });
            });

            Route::post('invitations/accept', [InvitationController::class, 'accept'])
                ->middleware('throttle:invitation-preview')->name('invitations.accept');
            Route::post('claims/accept', [ClaimController::class, 'accept'])->middleware('throttle:claims')->name('claims.accept');

            // The signed-in consumer's own account (Trello #10, #18). Identity comes from the session only.
            Route::prefix('consumer')->name('consumer.')->group(function () {
                Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
                Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
                Route::get('profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar.show');
                Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar'])->middleware('throttle:profile-upload')->name('profile.avatar.store');
                Route::delete('profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.destroy');

                Route::post('email-change', [EmailChangeController::class, 'store'])->middleware('throttle:email-change')->name('email-change.store');
                Route::post('email-change/confirm', [EmailChangeController::class, 'confirm'])->middleware('throttle:identity-verify-code')->name('email-change.confirm');
                Route::delete('email-change', [EmailChangeController::class, 'destroy'])->name('email-change.destroy');

                Route::get('preferences', [PreferenceController::class, 'show'])->name('preferences.show');
                Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
                Route::get('favorites', [PreferenceController::class, 'favorites'])->name('favorites.index');
                Route::put('favorites/{slug}', [PreferenceController::class, 'addFavorite'])->name('favorites.store');
                Route::delete('favorites/{slug}', [PreferenceController::class, 'removeFavorite'])->name('favorites.destroy');

                Route::get('vouchers', [BenefitsController::class, 'vouchers'])->name('vouchers.index');
                Route::get('affiliate-activity', [AffiliateActivityController::class, 'index'])->name('affiliate-activity.index');
                Route::get('dashboard', [BenefitsController::class, 'dashboard'])->name('dashboard');

                Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
                Route::get('wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions.index');
                Route::get('wallet/transactions/{id}', [WalletController::class, 'transaction'])->name('wallet.transactions.show');

                Route::middleware('throttle:privacy')->group(function () {
                    Route::get('privacy/export', [PrivacyController::class, 'export'])->name('privacy.export');
                    Route::post('privacy/deletion-request', [PrivacyController::class, 'requestDeletion'])->name('privacy.deletion-request');
                });
                Route::get('privacy/requests', [PrivacyController::class, 'requests'])->name('privacy.requests');
            });

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

                // Offers (Trello #7). Drafts can be prepared during onboarding (card #9);
                // activating and resuming need a commercially active webshop.
                Route::get('offers', [OfferController::class, 'index'])->middleware('workspace.can:offers.view')->name('offers.index');
                Route::get('offers/{offer}', [OfferController::class, 'show'])->middleware('workspace.can:offers.view')->name('offers.show');
                Route::middleware('workspace.can:offers.manage')->group(function () {
                    Route::post('offers', [OfferController::class, 'store'])->name('offers.store');
                    Route::patch('offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
                    Route::post('offers/{offer}/activate', [OfferController::class, 'activate'])->middleware('workspace.commercial')->name('offers.activate');
                    Route::post('offers/{offer}/pause', [OfferController::class, 'pause'])->name('offers.pause');
                    Route::post('offers/{offer}/end', [OfferController::class, 'end'])->name('offers.end');
                });

                // Code inventory per offer (Trello #7 §5–7).
                Route::get('offers/{offer}/codes', [VoucherCodeController::class, 'index'])->middleware('workspace.can:vouchers.view')->name('codes.index');
                Route::get('offers/{offer}/codes/export', [VoucherCodeController::class, 'export'])
                    ->middleware(['workspace.can:vouchers.export', 'throttle:order-export'])->name('codes.export');
                Route::get('codes/{code}/history', [VoucherCodeController::class, 'history'])->middleware('workspace.can:vouchers.view')->name('codes.history');
                Route::get('code-imports/{batch}', [VoucherCodeController::class, 'showBatch'])->middleware('workspace.can:vouchers.view')->name('code-imports.show');
                Route::middleware('workspace.can:vouchers.manage')->group(function () {
                    Route::post('offers/{offer}/code-imports', [VoucherCodeController::class, 'import'])->middleware('throttle:code-import')->name('code-imports.store');
                    Route::post('code-imports/{batch}/commit', [VoucherCodeController::class, 'commitBatch'])->name('code-imports.commit');
                    Route::post('codes/{code}/reveal', [VoucherCodeController::class, 'reveal'])->middleware('throttle:code-reveal')->name('codes.reveal');
                    Route::post('codes/{code}/invalidate', [VoucherCodeController::class, 'invalidate'])->name('codes.invalidate');
                });

                // Answer24 contact (Trello #1, #23): owner attestation, suppressions, opportunity queue.
                Route::get('answer24/settings', [ContactSettingsController::class, 'show'])->middleware('workspace.can:settings.view')->name('answer24.settings.show');
                Route::put('answer24/settings', [ContactSettingsController::class, 'update'])->middleware('workspace.can:agreements.accept')->name('answer24.settings.update');
                Route::post('answer24/suppressions', [ContactSettingsController::class, 'suppress'])->middleware('workspace.can:settings.manage')->name('answer24.suppressions.store');
                Route::get('answer24/opportunities', [ContactSettingsController::class, 'opportunities'])->middleware('workspace.can:orders.view')->name('answer24.opportunities.index');

                // Public discovery profile (Trello #18).
                Route::get('public-profile', [PublicProfileController::class, 'show'])->middleware('workspace.can:settings.view')->name('public-profile.show');
                Route::middleware('workspace.can:settings.manage')->group(function () {
                    Route::put('public-profile', [PublicProfileController::class, 'update'])->name('public-profile.update');
                    Route::post('public-profile/logo', [PublicProfileController::class, 'uploadLogo'])->name('public-profile.logo.store');
                    Route::delete('public-profile/logo', [PublicProfileController::class, 'deleteLogo'])->name('public-profile.logo.destroy');
                });

                Route::get('agreement', [AgreementController::class, 'show'])->middleware('workspace.can:settings.view')->name('agreement.show');
                Route::post('agreement/accept', [AgreementController::class, 'accept'])
                    ->middleware('workspace.can:agreements.accept')->name('agreement.accept');
            });
        });
    });
});
