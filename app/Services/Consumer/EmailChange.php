<?php

namespace App\Services\Consumer;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\EmailChangeCode;
use App\Notifications\EmailChangeRequested;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Secured login-email change (Trello #10 E2E 4, #18 "Email with
 * re-verification on change"). The account keeps working with the current
 * address until a 6-digit code sent to the new address is confirmed; then
 * the email is replaced and is verified (the code proved the mailbox).
 * Codes: HMAC-stored, bound to user + new address, expiry, attempt limit,
 * single use (conditional update), newer requests supersede older ones.
 */
class EmailChange
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function request(User $user, string $newEmail, #[\SensitiveParameter] string $currentPassword): EmailChangeRequest
    {
        if (! Hash::check($currentPassword, $user->password)) {
            $this->audit->recordSafely('identity.email_change_failed', AuditOutcome::Failure, AuditCategory::Identity, $user, actorUserId: $user->id, metadata: ['reason' => 'wrong_password']);

            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }

        $newEmail = User::normalizeEmail($newEmail);
        if ($newEmail === $user->email) {
            throw ValidationException::withMessages(['email' => 'This is already your email address.']);
        }
        if (User::where('email', $newEmail)->exists()) {
            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => 'email'])]);
        }

        $this->assertCooldownElapsed($user);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('shopbonus.identity.verification.code_ttl_minutes');

        $request = DB::transaction(function () use ($user, $newEmail, $code, $ttl) {
            EmailChangeRequest::where('user_id', $user->id)->open()->update(['superseded_at' => now(), 'updated_at' => now()]);

            return EmailChangeRequest::create(['user_id' => $user->id, 'new_email' => $newEmail,
                'code_hash' => $this->hash($user, $newEmail, $code), 'expires_at' => now()->addMinutes($ttl)]);
        });

        Notification::route('mail', $newEmail)->notify((new EmailChangeCode($code, $user->first_name, $ttl))->locale($user->preferredLocale()));
        $user->notify(new EmailChangeRequested(self::mask($newEmail), completed: false, firstName: $user->first_name));
        $this->audit->recordSafely('identity.email_change_requested', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);

        return $request;
    }

    public function confirm(User $user, #[\SensitiveParameter] string $code): User
    {
        $maxAttempts = (int) config('shopbonus.identity.verification.max_attempts');
        $oldEmail = $user->email;

        // Attempt counters commit even for a wrong code; errors are thrown after commit.
        $result = DB::transaction(function () use ($user, $code, $maxAttempts): string|EmailChangeRequest {
            $request = EmailChangeRequest::where('user_id', $user->id)->open()->latest('id')->lockForUpdate()->first();

            if ($request === null) {
                return 'email_change_not_pending';
            }
            if ($request->expires_at->isPast()) {
                return 'verification_code_expired';
            }
            if ($request->attempt_count >= $maxAttempts) {
                return 'verification_code_attempts_exceeded';
            }
            if (! hash_equals($request->code_hash, $this->hash($user, $request->new_email, $code))) {
                $request->increment('attempt_count');

                return $request->attempt_count >= $maxAttempts ? 'verification_code_attempts_exceeded' : 'verification_code_invalid';
            }
            if (EmailChangeRequest::whereKey($request->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]) !== 1) {
                return 'verification_code_invalid';
            }

            return $request;
        });

        if (is_string($result)) {
            $this->audit->recordSafely('identity.email_change_failed', AuditOutcome::Failure, AuditCategory::Identity, $user, actorUserId: $user->id, metadata: ['reason' => $result]);

            throw match ($result) {
                'email_change_not_pending' => ApiException::conflict('There is no pending email change.', $result),
                'verification_code_expired' => new ApiException('This code has expired. Request a new code.', 422, $result),
                'verification_code_attempts_exceeded' => new ApiException('Too many incorrect attempts. Request a new code.', 422, $result),
                default => new ApiException('This code is not valid.', 422, $result),
            };
        }

        try {
            User::whereKey($user->id)->update(['email' => $result->new_email, 'email_verified_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Someone registered the address after the request was made.
            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => 'email'])]);
        }

        Notification::route('mail', $oldEmail)->notify((new EmailChangeRequested(self::mask($result->new_email), completed: true, firstName: $user->first_name))->locale($user->preferredLocale()));
        $this->audit->recordSafely('identity.email_changed', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);

        return $user->refresh();
    }

    public function cancel(User $user): int
    {
        return EmailChangeRequest::where('user_id', $user->id)->open()->update(['superseded_at' => now(), 'updated_at' => now()]);
    }

    public function pending(User $user): ?EmailChangeRequest
    {
        return EmailChangeRequest::where('user_id', $user->id)->open()->where('expires_at', '>', now())->latest('id')->first();
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function assertCooldownElapsed(User $user): void
    {
        $cooldown = (int) config('shopbonus.identity.verification.resend_cooldown_seconds');
        $latest = EmailChangeRequest::where('user_id', $user->id)->latest('id')->first();
        $retryAfter = $latest === null ? 0 : (int) ceil($cooldown - $latest->created_at->diffInSeconds(now()));

        if ($retryAfter > 0) {
            throw new ApiException('Please wait before requesting a new code.', 429, 'verification_resend_too_soon',
                ['retry_after_seconds' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
        }
    }

    private function hash(User $user, string $newEmail, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', 'email-change|'.$user->id.'|'.$newEmail.'|'.trim($code), (string) config('app.key'));
    }
}
