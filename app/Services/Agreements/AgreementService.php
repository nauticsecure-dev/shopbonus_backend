<?php

namespace App\Services\Agreements;

use App\Enums\AgreementTemplateStatus;
use App\Enums\AuditCategory;
use App\Enums\VerificationStatus;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\BusinessProfile;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\WebshopLifecycle;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Merchant agreements (Trello #16 "Merchant agreement", #3 A.3).
 *
 * The server selects the current published version; the client must echo
 * back exactly that version and document hash, so it can neither choose an
 * older text nor alter the wording. The rendered document is stored with
 * its SHA-256 as immutable evidence.
 */
class AgreementService
{
    /** Placeholders a template may use; anything else is rejected when saving a template. */
    public const PLACEHOLDERS = ['webshop_name', 'legal_name', 'trading_name', 'kvk_number', 'vat_number', 'website_domain', 'address'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WebshopLifecycle $lifecycle,
    ) {}

    public function currentTemplate(Webshop $webshop): ?AgreementTemplate
    {
        $preferred = $webshop->businessProfile->preferred_language ?? 'nl';

        foreach (array_unique([$preferred, 'nl']) as $locale) {
            $template = AgreementTemplate::where('key', AgreementTemplate::MERCHANT)->where('locale', $locale)
                ->where('status', AgreementTemplateStatus::Published)->latest('published_at')->first();

            if ($template !== null) {
                return $template;
            }
        }

        return null;
    }

    public function render(AgreementTemplate $template, Webshop $webshop): string
    {
        $profile = $webshop->businessProfile ?? new BusinessProfile;
        $values = [
            'webshop_name' => $webshop->name,
            'legal_name' => $profile->legal_name,
            'trading_name' => $profile->trading_name,
            'kvk_number' => $profile->kvk_number,
            'vat_number' => $profile->vat_number,
            'website_domain' => $profile->website_domain,
            'address' => trim(implode(', ', array_filter([$profile->address_line, trim(($profile->postal_code ?? '').' '.($profile->city ?? '')), $profile->country_code]))),
        ];

        // Values are business data entered by merchants: escape so they cannot inject markup.
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($values) {
            return htmlspecialchars((string) ($values[$m[1]] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE);
        }, $template->body);
    }

    public function hash(string $renderedBody): string
    {
        return hash('sha256', $renderedBody);
    }

    public function latestAcceptance(Webshop $webshop): ?AgreementAcceptance
    {
        return $webshop->agreementAcceptances()->with('template')->latest('accepted_at')->latest('id')->first();
    }

    /** not_started | complete | outdated */
    public function status(Webshop $webshop): string
    {
        $latest = $this->latestAcceptance($webshop);
        $current = $this->currentTemplate($webshop);

        return match (true) {
            $latest === null => 'not_started',
            $current === null, $latest->agreement_template_id === $current->id => 'complete',
            ! $current->requires_reacceptance => 'complete',
            default => 'outdated',
        };
    }

    public function hasAcceptedCurrent(Webshop $webshop): bool
    {
        return $this->status($webshop) === 'complete';
    }

    /**
     * @param  array{version: string, document_hash: string, signatory_name: string}  $data
     */
    public function accept(WebshopMembership $actor, array $data): AgreementAcceptance
    {
        $webshop = $actor->webshop;
        $profile = $webshop->businessProfile;

        if ($profile?->confirmed_at === null) {
            throw ApiException::conflict('Confirm the business details first.', 'business_details_incomplete');
        }

        $reviewSubmitted = $webshop->status === WebshopStatus::PendingReview
            || $webshop->verifications()->where('status', VerificationStatus::Submitted)->exists();

        if (! $webshop->isOwnershipVerified() && ! $reviewSubmitted) {
            throw ApiException::conflict('Verify ownership (or submit a review) before accepting the agreement.', 'ownership_not_verified');
        }

        if (in_array($webshop->status, [WebshopStatus::Rejected, WebshopStatus::Suspended], true)) {
            throw ApiException::conflict('This webshop cannot accept agreements in its current state.', 'not_in_onboarding');
        }

        $template = $this->currentTemplate($webshop)
            ?? throw new ApiException('No merchant agreement is published yet.', 503, 'agreement_unavailable');
        $rendered = $this->render($template, $webshop);
        $hash = $this->hash($rendered);

        if ($data['version'] !== $template->version || ! hash_equals($hash, $data['document_hash'])) {
            throw ApiException::conflict(
                'The agreement has changed. Reload it, review the current version and accept again.',
                'agreement_outdated',
                ['current_version' => $template->version],
            );
        }

        $acceptance = DB::transaction(function () use ($actor, $webshop, $template, $rendered, $hash, $data) {
            $acceptance = $webshop->agreementAcceptances()->create([
                'agreement_template_id' => $template->id,
                'version' => $template->version,
                'rendered_body' => $rendered,
                'document_hash' => $hash,
                'signatory_name' => $data['signatory_name'],
                'accepted_by' => $actor->user_id,
                'accepted_at' => now(),
                'evidence' => [
                    'request_id' => Context::get('request_id'),
                    'ip_hash' => $this->audit->hashIp(request()->ip()),
                ],
            ]);

            $this->audit->record('agreement.accepted', category: AuditCategory::Workspace, resource: $acceptance,
                webshopId: $webshop->id, metadata: ['version' => $template->version, 'document_hash' => $hash]);

            return $acceptance;
        });

        $this->lifecycle->evaluate($webshop->fresh() ?? $webshop);

        return $acceptance;
    }
}
