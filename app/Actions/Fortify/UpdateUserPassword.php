<?php

namespace App\Actions\Fortify;

use App\Enums\AuditCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * PUT /user/password (Fortify). Keeps the current session, signs out every
 * other session and revokes all API tokens.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validate();

        $user->forceFill(['password' => $input['password']])->save();
        $user->tokens()->delete();

        // Other sessions still hold the old password hash and are rejected by
        // Sanctum's AuthenticateSession on their next request. Refresh the
        // hash in this session so the current device stays signed in.
        // Keys are "password_hash_{guard name}" as written by Sanctum.
        foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guardName) {
            $guard = Auth::guard($guardName);

            if ($guard instanceof SessionGuard && request()->hasSession()) {
                request()->session()->put(
                    'password_hash_'.$guardName,
                    $guard->hashPasswordForCookie($user->getAuthPassword()),
                );
            }
        }

        $this->audit->record(
            action: 'identity.password_changed',
            category: AuditCategory::Identity,
            resource: $user,
        );
    }
}
