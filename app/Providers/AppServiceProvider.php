<?php

namespace App\Providers;

use App\Services\Errors\ErrorRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing
        // attributes as errors outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Breached-password check (k-anonymity HIBP lookup) only in production,
        // where outbound HTTP is expected; length/character rules everywhere.
        Password::defaults(function () {
            $rule = Password::min(10)->max(255)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) config('shopbonus.api.rate_limit_per_minute', 60);

            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($perMinute)->by($userId ? 'user:'.$userId : 'ip:'.$request->ip());
        });
    }
}
