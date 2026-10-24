<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\StepUpChallenge;
use App\Models\User;
use App\Notifications\StepUpCode;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Emailed step-up codes for sensitive Super Admin actions (Trello #4
 * "fresh password plus emailed OTP", #21 "Impersonation").
 *
 * Same storage rules as email verification: only a user-bound HMAC is
 * stored, a code expires, allows `max_attempts` wrong guesses and is
 * consumed once under a row lock. A successful code marks the browser
 * session as stepped up for `valid_minutes` (`step_up.otp` middleware).
 */
class StepUpCodes
{
    public const SESSION_KEY = 'auth.step_up_confirmed_at';

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(User $user): StepUpChallenge
    {
        $latest = StepUpChallenge::where('user_id', $user->id)->latest('id')->first();
        $retryAfter = $latest === null ? 0 : (int) ceil(self::RESEND_COOLDOWN_SECONDS - $latest->created_at->diffInSeconds(now()));

        if ($retryAfter > 0) {
            throw new ApiException('Please wait before requesting a new code.', 429, 'step_up_resend_too_soon',
                ['retry_after_seconds' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('shopbonus.admin.step_up.code_ttl_minutes');

        $challenge = DB::transaction(function () use ($user, $code, $ttl) {
            // A new code supersedes every open one.
            StepUpChallenge::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return StepUpChallenge::create([
                'user_id' => $user->id,
                'code_hash' => $this->hash($user, $code),
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });

        $user->notify(new StepUpCode($code, $ttl));

        $this->audit->recordSafely(action: 'admin.step_up_code_sent', category: AuditCategory::Security, resource: $user);

        return $challenge;
    }

    /**
     * @throws ApiException 422 invalid, expired or too many attempts
     */
    public function verify(User $user, #[\SensitiveParameter] string $code, Session $session): void
    {
        $maxAttempts = (int) config('shopbonus.admin.step_up.max_attempts');

        // Attempt counters commit even when the code is wrong.
        $failure = DB::transaction(function () use ($user, $code, $maxAttempts): ?string {
            $challenge = StepUpChallenge::where('user_id', $user->id)->whereNull('consumed_at')
                ->latest('id')->lockForUpdate()->first();

            if ($challenge === null) {
                return 'step_up_code_invalid';
            }

            if ($challenge->expires_at->isPast()) {
                return 'step_up_code_expired';
            }

            if ($challenge->attempt_count >= $maxAttempts) {
                return 'step_up_code_attempts_exceeded';
            }

            if (! hash_equals($challenge->code_hash, $this->hash($user, $code))) {
                $challenge->increment('attempt_count');

                return $challenge->attempt_count >= $maxAttempts ? 'step_up_code_attempts_exceeded' : 'step_up_code_invalid';
            }

            $consumed = StepUpChallenge::whereKey($challenge->id)->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return $consumed === 1 ? null : 'step_up_code_invalid';
        });

        if ($failure !== null) {
            $this->audit->recordSafely(action: 'admin.step_up_failed', outcome: AuditOutcome::Failure,
                category: AuditCategory::Security, resource: $user, metadata: ['reason' => $failure]);

            throw new ApiException(match ($failure) {
                'step_up_code_expired' => 'This code has expired. Request a new code.',
                'step_up_code_attempts_exceeded' => 'Too many incorrect attempts. Request a new code.',
                default => 'This code is not valid.',
            }, 422, $failure);
        }

        $session->put(self::SESSION_KEY, time());

        $this->audit->recordSafely(action: 'admin.step_up_confirmed', category: AuditCategory::Security, resource: $user);
    }

    public static function confirmedUntil(Session $session): ?int
    {
        $at = (int) $session->get(self::SESSION_KEY, 0);
        $until = $at + 60 * (int) config('shopbonus.admin.step_up.valid_minutes');

        return $at > 0 && $until > time() ? $until : null;
    }

    private function hash(User $user, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', 'step-up|'.$user->id.'|'.trim($code), (string) config('app.key'));
    }
}
