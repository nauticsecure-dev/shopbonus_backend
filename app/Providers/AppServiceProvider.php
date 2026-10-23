<?php

namespace App\Providers;

use App\Services\Errors\ErrorRecorder;
use App\Services\Onboarding\Directory\BusinessDirectory;
use App\Services\Onboarding\Directory\GooglePlacesDirectory;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\SafeWebsiteFileFetcher;
use App\Services\Onboarding\Network\SystemHostResolver;
use App\Services\Onboarding\Network\WebsiteFileFetcher;
use App\Services\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so its re-entrancy guard spans the whole request/job.
        $this->app->singleton(ErrorRecorder::class);

        // One resolved workspace per request (and per queued job).
        $this->app->scoped(CurrentWorkspace::class);

        // Ownership verification network access (swappable in tests).
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(WebsiteFileFetcher::class, SafeWebsiteFileFetcher::class);
        $this->app->bind(BusinessDirectory::class, GooglePlacesDirectory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing
        // attributes as errors outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // now()/today() return immutable dates, matching the immutable_datetime
        // casts used on every model (no accidental in-place date mutation).
        Date::use(CarbonImmutable::class);

        // Breached-password check (k-anonymity HIBP lookup) only in production,
        // where outbound HTTP is expected; length/character rules everywhere.
        Password::defaults(function () {
            $rule = Password::min(10)->max(255)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('workspace-create', fn (Request $request) => Limit::perHour(10)->by('ws-create:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('invitation-create', fn (Request $request) => Limit::perHour(30)->by('inv-create:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('invitation-preview', fn (Request $request) => Limit::perMinute(10)->by('inv-token:'.$request->ip()));

        RateLimiter::for('business-search', fn (Request $request) => Limit::perMinute(30)->by('biz:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('ownership-check', fn (Request $request) => Limit::perMinute(10)->by('own:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhook:'.$request->route('token')));

        // Partner systems: per integration key, not per IP. Claim links: per IP (token guessing).
        RateLimiter::for('integrations', fn (Request $request) => Limit::perMinute(600)->by('integration:'.$request->header('X-Shopbonus-Key', $request->ip())));
        RateLimiter::for('claims', fn (Request $request) => Limit::perMinute(10)->by('claims:'.$request->ip()));

        // Consumer account actions (per user).
        RateLimiter::for('email-change', fn (Request $request) => Limit::perHour(10)->by('email-change:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('profile-upload', fn (Request $request) => Limit::perMinute(10)->by('profile-upload:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('privacy', fn (Request $request) => Limit::perHour(5)->by('privacy:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('order-export', fn (Request $request) => Limit::perMinute(5)->by('export:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('code-import', fn (Request $request) => Limit::perMinute(10)->by('code-import:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('code-reveal', fn (Request $request) => Limit::perMinute(30)->by('code-reveal:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) config('shopbonus.api.rate_limit_per_minute', 60);

            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($perMinute)->by($userId ? 'user:'.$userId : 'ip:'.$request->ip());
        });
    }
}
