<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Http\Responses\MeResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Headless Fortify (Trello #4): native /register, /login, /logout,
 * /forgot-password, /reset-password and /user/password, JSON only.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponse::class, MeResponse::class);
        $this->app->singleton(RegisterResponse::class, MeResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', User::normalizeEmail((string) $request->input('email')))->first();
            $password = (string) $request->input('password');

            if ($user === null) {
                // Same hashing cost as a real check, so timing does not reveal unknown emails.
                static $dummyHash = null;
                $dummyHash ??= Hash::make(Str::random(40));
                Hash::check($password, $dummyHash);

                return null;
            }

            // Suspended/deleted accounts get the same generic failure as a wrong password.
            return Hash::check($password, $user->password) && $user->isActive() ? $user : null;
        });

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return config('shopbonus.identity.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $email = fn (Request $request) => User::normalizeEmail((string) $request->input('email'));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$email($request).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('identity-register', fn (Request $request) => [
            Limit::perMinutes(10, 5)->by('register-ip:'.$request->ip()),
            Limit::perHour(3)->by('register-email:'.$email($request)),
        ]);

        RateLimiter::for('identity-forgot-password', fn (Request $request) => [
            Limit::perHour(5)->by('forgot:'.$email($request).'|'.$request->ip()),
            Limit::perHour(20)->by('forgot-ip:'.$request->ip()),
        ]);

        RateLimiter::for('identity-reset-password', fn (Request $request) => [
            Limit::perHour(10)->by('reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('identity-verify-code', fn (Request $request) => [
            Limit::perMinute(10)->by('verify:'.$request->user()?->getAuthIdentifier().'|'.$request->ip()),
        ]);

        RateLimiter::for('identity-resend-code', fn (Request $request) => [
            Limit::perHour(5)->by('resend:'.$request->user()?->getAuthIdentifier()),
        ]);

        // Password change while signed in (Trello #10 E2E 5): limits guessing the current password.
        RateLimiter::for('identity-change-password', fn (Request $request) => [
            Limit::perMinute(5)->by('change-password:'.$request->user()?->getAuthIdentifier().'|'.$request->ip()),
        ]);
    }
}
