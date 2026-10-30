<?php

namespace App\Services\Identity;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationSources;
use Illuminate\Support\Facades\DB;

/**
 * Six-digit email verification codes (Trello #4).
 *
 * - Only an HMAC of the code is stored (keyed with APP_KEY and bound to the
 *   user), so a database leak does not reveal usable codes.
 * - A code expires after `code_ttl_minutes`, allows `max_attempts` wrong
 *   guesses, and can be consumed once. Issuing a new code supersedes all
 *   open ones.
 * - Consumption is a conditional UPDATE on `consumed_at IS NULL` inside a
 *   locked transaction: concurrent correct submissions succeed at most once.
 * - The plain code exists only in memory and in the encrypted queued mail.
 */
class EmailVerificationCodes
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Issues a fresh code, superseding open ones, and queues the email.
     *
     * @throws ApiException 409 if already verified, 429 inside the resend cooldown
     */
    public function issue(User $user, bool $enforceCooldown = true): EmailVerificationChallenge
    {
        if ($user->email_verified_at !== null) {
            throw ApiException::conflict('This email address is already verified.', 'email_already_verified');
        }

        if ($enforceCooldown) {
            $this->assertCooldownElapsed($user);
        }

        $code = $this->generateCode();
        $ttl = (int) config('shopbonus.identity.verification.code_ttl_minutes');

        $challenge = DB::transaction(function () use ($user, $code, $ttl) {
            $user->emailVerificationChallenges()->open()->update(['superseded_at' => now()]);

            return $user->emailVerificationChallenges()->create([
                'email' => $user->email,
                'code_hash' => $this->hash($user, $code),
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });

        $user->notify(new EmailVerificationCode($code, $ttl));

        $this->audit->recordSafely(
            action: 'identity.verification_code_sent',
            category: AuditCategory::Identity,
            resource: $user,
            actorUserId: $user->id,
        );

        return $challenge;
    }

    /**
     * Verifies and consumes a code, marking the email verified.
     *
     * @throws ApiException 409 already verified; 422 invalid/expired/attempts exceeded
     */
    public function verify(User $user, #[\SensitiveParameter] string $code): User
    {
        if ($user->email_verified_at !== null) {
            throw ApiException::conflict('This email address is already verified.', 'email_already_verified');
        }

        $maxAttempts = (int) config('shopbonus.identity.verification.max_attempts');

        // The transaction commits attempt counters even when the code is
        // wrong; the error is thrown only after commit.
        $failure = DB::transaction(function () use ($user, $code, $maxAttempts): ?string {
            $challenge = $user->emailVerificationChallenges()
                ->open()
                ->where('email', $user->email)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return 'verification_code_invalid';
            }

            if ($challenge->expires_at->isPast()) {
                return 'verification_code_expired';
            }

            if ($challenge->attempt_count >= $maxAttempts) {
                return 'verification_code_attempts_exceeded';
            }

            if (! hash_equals($challenge->code_hash, $this->hash($user, $code))) {
                $challenge->increment('attempt_count');

                return $challenge->attempt_count >= $maxAttempts
                    ? 'verification_code_attempts_exceeded'
                    : 'verification_code_invalid';
            }

            $consumed = EmailVerificationChallenge::query()
                ->whereKey($challenge->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            if ($consumed !== 1) {
                return 'verification_code_invalid';
            }

            User::query()->whereKey($user->id)->whereNull('email_verified_at')
                ->update(['email_verified_at' => now()]);

            app(NotificationSources::class)->capture('email_verified', 'email-verified:'.$user->public_id, [$user->id]);

            return null;
        });

        if ( $failure !== null) {
            $this->audit->recordSafely(
                action: 'identity.verification_code_failed',
                outcome: AuditOutcome::Failure,
                category: AuditCategory::Identity,
                resource: $user,
                metadata: ['reason' => $failure],
                actorUserId: $user->id,
            );

            throw new ApiException($this->messageFor($failure), 422, $failure);
        }

        $this->audit->recordSafely(
            action: 'identity.email_verified',
            category: AuditCategory::Identity,
            resource: $user,
            actorUserId: $user->id,
        );

        return $user->refresh();
    }

    private function assertCooldownElapsed(User $user): void
    {
        $cooldown = (int) config('shopbonus.identity.verification.resend_cooldown_seconds');
        $latest = $user->emailVerificationChallenges()->latest('id')->first();

        if ($latest === null) {
            return;
        }

        $retryAfter = (int) ceil($cooldown - $latest->created_at->diffInSeconds(now()));

        if ($retryAfter > 0) {
            throw new ApiException(
                'Please wait before requesting a new code.',
                429,
                'verification_resend_too_soon',
                ['retry_after_seconds' => $retryAfter],
                ['Retry-After' => (string) $retryAfter],
            );
        }
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private function hash(User $user, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.trim($code), (string) config('app.key'));
    }

    private function messageFor(string $failure): string
    {
        return match ($failure) {
            'verification_code_expired' => 'This code has expired. Request a new code.',
            'verification_code_attempts_exceeded' => 'Too many incorrect attempts. Request a new code.',
            default => 'This code is not valid.',
        };
    }
}
