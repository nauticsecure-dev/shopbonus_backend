<?php

namespace App\Services\Identity;

use App\Enums\ConsentPurpose;
use App\Enums\LegalDocumentType;
use App\Exceptions\ApiException;
use App\Models\CmsRevision;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\CmsService;
use Illuminate\Support\Facades\Context;

/**
 * Records legal acceptances and optional consents as immutable history.
 *
 * Current document versions come from the published CMS legal pages (B15),
 * falling back to config('shopbonus.legal'); this class is the single place
 * that decides.
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
        // The published CMS legal version wins (B15); the env config is the fallback.
        $versions = [
            LegalDocumentType::Terms->value => CmsService::publishedLegalVersion('terms') ?? config('shopbonus.legal.terms_version'),
            LegalDocumentType::Privacy->value => CmsService::publishedLegalVersion('privacy') ?? config('shopbonus.legal.privacy_version'),
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

    /** Public discovery uses exactly the same version selection as registration.
     * @return array<string, mixed>
     */
    public function requirements(string $locale): array
    {
        $documents = [];
        foreach (['terms', 'privacy'] as $type) {
            $canonical = CmsService::publishedLegalRevision($type);
            $published = $canonical?->legal_version;
            $version = $published ?? config('shopbonus.legal.'.$type.'_version');
            $version = is_string($version) && $version !== '' ? $version : null;
            $revision = $version === null ? null : CmsRevision::query()
                ->join('cms_pages', 'cms_pages.id', '=', 'cms_revisions.page_id')
                ->join('cms_page_locales', 'cms_page_locales.published_revision_id', '=', 'cms_revisions.id')
                ->where('cms_pages.type', $type)->where('cms_pages.audience', 'public')->whereNull('cms_pages.archived_at')
                ->where('cms_revisions.locale', $locale)->where('cms_revisions.legal_version', $version)
                ->when($canonical !== null, fn ($query) => $query->where('cms_revisions.page_id', $canonical->page_id))
                ->orderByDesc('cms_revisions.id')->value('cms_page_locales.slug');
            $documents[$type] = ['version' => $version, 'source' => $published !== null ? 'cms' : ($version !== null ? 'configuration' : null),
                'document_url' => $revision === null ? null : route('api.v1.public.pages.show', ['locale' => $locale, 'slug' => $revision])];
        }

        return ['locale' => $locale, 'registration_available' => collect($documents)->every(fn ($d) => $d['version'] !== null),
            'documents_available' => collect($documents)->every(fn ($d) => $d['document_url'] !== null), 'documents' => $documents];
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
