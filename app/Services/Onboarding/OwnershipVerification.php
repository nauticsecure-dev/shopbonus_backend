<?php

namespace App\Services\Onboarding;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\VerificationMethod;
use App\Enums\VerificationStatus;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Models\WebshopVerification;
use App\Notifications\OwnershipVerificationCode;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\WebsiteFileFetcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ownership verification (Trello #16). External business data never proves
 * ownership; only these challenges or a Super Admin review do.
 *
 * - DNS TXT: `_shopbonus-verification.<domain>` must contain the value.
 * - Website file: https://<domain>/.well-known/shopbonus-verification.txt
 *   must contain the value (fetched SSRF-safely).
 * - Domain email: a code sent to an administrative mailbox at the domain.
 * - Manual review: notes and private evidence for a Super Admin.
 *
 * A domain already verified by another webshop never auto-verifies: such
 * claims must go through manual review, flagged as a duplicate claim.
 */
class OwnershipVerification
{
    public const CHALLENGE_DAYS = 7;

    public const EMAIL_CODE_MINUTES = 30;

    public const EMAIL_MAX_ATTEMPTS = 5;

    public const DNS_PREFIX = '_shopbonus-verification';

    public const FILE_PATH = '/.well-known/shopbonus-verification.txt';

    /** Mailboxes accepted as proof of domain control (as used for CA domain validation). */
    public const ADMIN_MAILBOXES = ['admin', 'administrator', 'webmaster', 'hostmaster', 'postmaster'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HostResolver $resolver,
        private readonly WebsiteFileFetcher $fetcher,
    ) {}

    public function start(WebshopMembership $actor, VerificationMethod $method, ?string $mailbox = null): WebshopVerification
    {
        $webshop = $actor->webshop;
        $domain = $this->assertCanStart($webshop);

        if ($this->domainClaimedElsewhere($webshop, $domain)) {
            throw ApiException::conflict(
                'This domain is already verified for another webshop. Submit a manual review instead.',
                'domain_already_claimed',
            );
        }

        // Domain email: the mailbox and the code travel together.
        $mail = $method === VerificationMethod::DomainEmail
            ? ['to' => $this->adminMailbox($domain, (string) $mailbox), 'code' => str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT)]
            : null;
        $email = $mail['to'] ?? null;
        $code = $mail['code'] ?? null;

        $verification = DB::transaction(function () use ($actor, $webshop, $method, $domain, $email, $code) {
            $webshop->verifications()->where('status', VerificationStatus::Pending)->update(['status' => VerificationStatus::Superseded]);

            $verification = $webshop->verifications()->create([
                'method' => $method,
                'status' => VerificationStatus::Pending,
                'domain' => $domain,
                'challenge_value' => $code === null ? 'shopbonus-verification='.bin2hex(random_bytes(16)) : null,
                'email' => $email,
                'code_hash' => $code === null ? null : $this->hash($webshop, $code),
                'expires_at' => $code === null ? now()->addDays(self::CHALLENGE_DAYS) : now()->addMinutes(self::EMAIL_CODE_MINUTES),
                'created_by' => $actor->user_id,
            ]);

            if (in_array($webshop->status, [WebshopStatus::Draft, WebshopStatus::VerificationFailed], true)) {
                $webshop->forceFill(['status' => WebshopStatus::AwaitingVerification])->save();
            }

            $this->audit->record('ownership.verification_started', category: AuditCategory::Workspace,
                resource: $verification, webshopId: $webshop->id, metadata: ['method' => $method->value, 'domain' => $domain]);

            return $verification;
        });

        if ($mail !== null) {
            Notification::route('mail', $mail['to'])->notify(
                (new OwnershipVerificationCode($mail['code'], $webshop->name, $domain, self::EMAIL_CODE_MINUTES))
                    ->locale($webshop->businessProfile->preferred_language ?? 'nl'),
            );
        }

        return $verification;
    }

    /**
     * Checks a DNS or website-file challenge now.
     */
    public function check(WebshopMembership $actor, WebshopVerification $verification): WebshopVerification
    {
        $this->assertOpen($verification, [VerificationMethod::DnsTxt, VerificationMethod::WebsiteFile]);
        $verification->increment('attempt_count');

        $found = match ($verification->method) {
            VerificationMethod::DnsTxt => in_array(
                $verification->challenge_value,
                array_map('trim', $this->resolver->txtRecords(self::DNS_PREFIX.'.'.$verification->domain)),
                true,
            ),
            default => trim((string) $this->fetcher->fetch((string) $verification->domain, self::FILE_PATH))
                === $verification->challenge_value,
        };

        if (! $found) {
            $this->audit->recordSafely('ownership.verification_check_failed', AuditOutcome::Failure, AuditCategory::Workspace,
                resource: $verification, webshopId: $verification->webshop_id, metadata: ['method' => $verification->method->value]);

            throw new ApiException(
                'The verification value was not found yet. DNS changes can take a while; try again later.',
                422,
                'challenge_not_found',
            );
        }

        return $this->complete($actor, $verification);
    }

    public function confirmEmailCode(WebshopMembership $actor, WebshopVerification $verification, #[\SensitiveParameter] string $code): WebshopVerification
    {
        $this->assertOpen($verification, [VerificationMethod::DomainEmail]);

        $failure = DB::transaction(function () use ($actor, $verification, $code): ?string {
            $locked = WebshopVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VerificationStatus::Pending) {
                return 'verification_not_pending';
            }

            if (hash_equals((string) $locked->code_hash, $this->hash($actor->webshop, $code))) {
                return null;
            }

            $locked->increment('attempt_count');

            if ($locked->attempt_count >= self::EMAIL_MAX_ATTEMPTS) {
                $this->fail($locked, 'attempts_exceeded');

                return 'verification_attempts_exceeded';
            }

            return 'verification_code_invalid';
        });

        if ($failure !== null) {
            $this->audit->recordSafely('ownership.verification_code_failed', AuditOutcome::Failure, AuditCategory::Workspace,
                resource: $verification, webshopId: $verification->webshop_id, metadata: ['reason' => $failure]);

            throw new ApiException(match ($failure) {
                'verification_attempts_exceeded' => 'Too many incorrect attempts. Start a new verification.',
                'verification_not_pending' => 'This verification is no longer open.',
                default => 'This code is not valid.',
            }, $failure === 'verification_not_pending' ? 409 : 422, $failure);
        }

        return $this->complete($actor, $verification);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function submitManualReview(WebshopMembership $actor, string $notes, array $files): WebshopVerification
    {
        $webshop = $actor->webshop;
        $domain = $this->assertCanStart($webshop);
        $duplicate = $this->domainClaimedElsewhere($webshop, $domain);

        $stored = array_map(fn (UploadedFile $file) => $this->storeEvidence($webshop, $file), $files);

        return DB::transaction(function () use ($actor, $webshop, $domain, $notes, $stored, $duplicate) {
            $webshop->verifications()->where('status', VerificationStatus::Pending)->update(['status' => VerificationStatus::Superseded]);

            $verification = $webshop->verifications()->create([
                'method' => VerificationMethod::ManualReview,
                'status' => VerificationStatus::Submitted,
                'domain' => $domain,
                'evidence' => ['notes' => $notes, 'files' => $stored],
                'created_by' => $actor->user_id,
            ]);

            $webshop->forceFill([
                'status' => WebshopStatus::PendingReview,
                'review_requested_at' => now(),
                'review_flags' => $duplicate ? ['duplicate_domain_claim'] : null,
            ])->save();

            $this->audit->record('ownership.review_submitted', category: AuditCategory::Workspace,
                resource: $verification, webshopId: $webshop->id,
                metadata: ['files' => count($stored), 'duplicate_domain_claim' => $duplicate]);

            return $verification;
        });
    }

    // ------------------------------------------------------------------ internals

    private function complete(WebshopMembership $actor, WebshopVerification $verification): WebshopVerification
    {
        // Returns null when the domain was claimed by another webshop in the
        // meantime; the failure is committed before the error is thrown.
        $result = DB::transaction(function () use ($verification): ?WebshopVerification {
            $webshop = Webshop::whereKey($verification->webshop_id)->lockForUpdate()->firstOrFail();
            $locked = WebshopVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VerificationStatus::Pending) {
                throw ApiException::conflict('This verification is no longer open.', 'verification_not_pending');
            }

            // Re-checked under lock: two webshops racing for the same domain.
            if ($this->domainClaimedElsewhere($webshop, (string) $locked->domain)) {
                $this->fail($locked, 'domain_already_claimed');

                return null;
            }

            $locked->forceFill(['status' => VerificationStatus::Verified, 'verified_at' => now()])->save();
            $webshop->forceFill([
                'ownership_verified_at' => now(),
                'ownership_method' => $locked->method->value,
                'verified_domain' => $locked->domain,
                'status' => in_array($webshop->status, [WebshopStatus::AwaitingVerification, WebshopStatus::VerificationFailed], true)
                    ? WebshopStatus::Draft : $webshop->status,
            ])->save();

            $this->audit->record('ownership.verified', category: AuditCategory::Workspace,
                resource: $locked, webshopId: $webshop->id,
                metadata: ['method' => $locked->method->value, 'domain' => $locked->domain]);

            return $locked;
        });

        return $result ?? throw ApiException::conflict(
            'This domain is already verified for another webshop. Submit a manual review instead.',
            'domain_already_claimed',
        );
    }

    private function fail(WebshopVerification $verification, string $reason): void
    {
        $verification->forceFill(['status' => VerificationStatus::Failed, 'failure_reason' => $reason])->save();

        Webshop::whereKey($verification->webshop_id)
            ->where('status', WebshopStatus::AwaitingVerification)
            ->update(['status' => WebshopStatus::VerificationFailed]);
    }

    /**
     * @param  list<VerificationMethod>  $methods
     */
    private function assertOpen(WebshopVerification $verification, array $methods): void
    {
        if (! in_array($verification->method, $methods, true)) {
            throw ApiException::conflict('This action does not apply to this verification method.', 'wrong_verification_method');
        }

        if ($verification->status !== VerificationStatus::Pending) {
            throw ApiException::conflict('This verification is no longer open.', 'verification_not_pending');
        }

        if ($verification->isExpired()) {
            $this->fail($verification, 'expired');

            throw new ApiException('This verification has expired. Start a new one.', 410, 'verification_expired');
        }
    }

    private function assertCanStart(Webshop $webshop): string
    {
        $profile = $webshop->businessProfile;

        if ($profile === null || $profile->confirmed_at === null || $profile->website_domain === null) {
            throw ApiException::conflict('Confirm the business details first.', 'business_details_incomplete');
        }

        if ($webshop->isOwnershipVerified()) {
            throw ApiException::conflict('Ownership is already verified.', 'ownership_already_verified');
        }

        if ($webshop->status === WebshopStatus::PendingReview) {
            throw ApiException::conflict('A review is already in progress.', 'review_in_progress');
        }

        if (in_array($webshop->status, [WebshopStatus::Approved, WebshopStatus::Rejected], true)) {
            throw ApiException::conflict('This webshop is not in onboarding.', 'not_in_onboarding');
        }

        return $profile->website_domain;
    }

    private function domainClaimedElsewhere(Webshop $webshop, string $domain): bool
    {
        return Webshop::where('verified_domain', $domain)->whereKeyNot($webshop->id)->exists();
    }

    private function adminMailbox(string $domain, string $mailbox): string
    {
        $local = Str::lower(trim($mailbox));

        if (in_array($domain, (array) config('shopbonus.onboarding.free_email_domains'), true)) {
            throw new ApiException('Email verification is not possible for free email domains. Use DNS, the website file or manual review.',
                422, 'free_email_domain');
        }

        if (! in_array($local, self::ADMIN_MAILBOXES, true)) {
            throw new ApiException('Choose one of the administrative mailboxes at your domain.', 422, 'mailbox_not_allowed',
                ['allowed' => self::ADMIN_MAILBOXES]);
        }

        return $local.'@'.$domain;
    }

    /**
     * @return array{id: string, name: string, mime: string, size: int}
     */
    private function storeEvidence(Webshop $webshop, UploadedFile $file): array
    {
        $id = (string) Str::uuid7();
        Storage::disk('local')->put(
            "verification-evidence/{$webshop->public_id}/{$id}.enc",
            Crypt::encryptString(base64_encode((string) $file->get())),
        );

        return [
            'id' => $id,
            'name' => Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?? 'file', 100, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    private function hash(Webshop $webshop, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $webshop->id.'|ownership|'.trim($code), (string) config('app.key'));
    }
}
