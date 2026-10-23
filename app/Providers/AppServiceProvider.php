<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing
        // attributes as errors outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) config('shopbonus.api.rate_limit_per_minute', 60);

            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($perMinute)->by($userId ? 'user:'.$userId : 'ip:'.$request->ip());
        });
    }
}
