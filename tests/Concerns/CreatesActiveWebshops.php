<?php

namespace Tests\Concerns;

use App\Enums\AgreementTemplateStatus;
use App\Enums\MembershipRole;
use App\Models\AgreementTemplate;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Funding\MerchantFunding;
use App\Services\Wallet\WalletService;

/**
 * An approved webshop whose owner accepted the current merchant agreement,
 * i.e. one that passes `workspace.commercial`.
 */
trait CreatesActiveWebshops
{
    protected function activeWebshop(User $owner, array $attributes = []): Webshop
    {
        $template = AgreementTemplate::where('key', AgreementTemplate::MERCHANT)->where('status', AgreementTemplateStatus::Published)->first()
            ?? AgreementTemplate::create([
                'key' => AgreementTemplate::MERCHANT, 'version' => 'test-1', 'locale' => 'nl', 'title' => 'Test agreement',
                'body' => 'Test agreement for {{legal_name}}', 'status' => AgreementTemplateStatus::Published,
                'published_at' => now(), 'created_by' => $owner->id,
            ]);

        $webshop = Webshop::factory()->approved()->withMember($owner, MembershipRole::Owner)
            ->create(['created_by' => $owner->id, 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt', ...$attributes]);

        $webshop->agreementAcceptances()->create([
            'agreement_template_id' => $template->id, 'version' => $template->version, 'rendered_body' => 'Test agreement',
            'document_hash' => hash('sha256', 'Test agreement'), 'signatory_name' => 'Test', 'accepted_by' => $owner->id, 'accepted_at' => now(),
        ]);

        // Commercially active shops in tests can sponsor rewards (B12); funding tests start from zero explicitly.
        app(WalletService::class)->credit(app(MerchantFunding::class)->wallet($webshop), 1_000_000, 'test-funding:'.$webshop->id, neverExpires: true);

        return $webshop;
    }
}
