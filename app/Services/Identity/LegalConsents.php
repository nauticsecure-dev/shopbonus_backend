<?php

namespace App\Services\Identity;

use App\Enums\ConsentPurpose;
use App\Enums\LegalDocumentType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Context;

/**
 * Records legal acceptances and optional consents as immutable history.
 *
 * Current document versions come from config('shopbonus.legal') until the
 * CMS (B15) publishes versioned documents; this class is the single place to
 * switch that source.
 */
class LegalConsents
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, string> document type => current published version
     *
     * @throws ApiException 503 when a required document has no published version
     */
    public function requiredVersions(): array
    {
        $versions = [
            LegalDocumentType::Terms->value => config('shopbonus.legal.terms_version'),
            LegalDocumentType::Privacy->value => config('shopbonus.legal.privacy_version'),
        ];

        foreach ($versions as $version) {
            if (! is_string($version) || $version === '') {
                throw new ApiException(
                    'Registration is temporarily unavailable.',
                    503,
                    'legal_documents_unavailable',
                );
            }
        }

        return $versions;
    }

    public function marketingConsentVersion(): ?string
    {
        $version = config('shopbonus.legal.marketing_consent_version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Stores acceptance of every required document at its current version.
     */
    public function recordRequiredAcceptances(User $user): void
    {
        $now = now();

        foreach ($this->requiredVersions() as $type => $version) {
            $user->legalAcceptances()->create([
                'document_type' => $type,
                'document_version' => $version,
                'accepted_at' => $now,
                'evidence' => $this->evidence($user),
            ]);
        }
    }

    /**
     * Appends a consent decision. Optional consent is only recorded as granted
     * when a consent wording version is published.
     */
    public function recordConsent(User $user, ConsentPurpose $purpose, bool $granted, string $source): void
    {
        $version = $this->marketingConsentVersion();

        if ($version === null) {
            return;
        }

        $user->consentRecords()->create([
            'purpose' => $purpose,
            'granted' => $granted,
            'policy_version' => $version,
            'source' => $source,
            'recorded_at' => now(),
            'evidence' => $this->evidence($user),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function evidence(User $user): array
    {
        return [
            'request_id' => Context::get('request_id'),
            'ip_hash' => $this->audit->hashIp(request()->ip()),
            'locale' => $user->locale,
        ];
    }
}
