<?php

namespace App\Services\Onboarding;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\VerificationStatus;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Privileged explicit reset; ordinary profile autosaves cannot move ownership proof. */
class DomainChange
{
    public function __construct(private readonly ConnectionManager $connections, private readonly AuditLogger $audit) {}

    public function change(Webshop $shop, User $actor, string $expectedDomain, string $websiteUrl, string $reason): Webshop
    {
        $domain = DomainName::fromInput($websiteUrl);
        $expectedDomain = DomainName::fromInput($expectedDomain);
        $reason = trim($reason);
        if (strlen($websiteUrl) > 500 || $reason === '' || strlen($reason) > 200 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new ApiException('A bounded website and review case reason are required.', 422, 'domain_change_invalid');
        }

        $websiteUrl = 'https://'.$domain;

        return DB::transaction(function () use ($shop, $actor, $expectedDomain, $websiteUrl, $reason, $domain) {
            $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $admin->isActive() || $admin->email_verified_at === null || ! $admin->isSuperAdmin()) {
                throw new ApiException('Verified active Super Admin access required.', 403, 'forbidden');
            }
            $current = Webshop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if (in_array($current->status, [WebshopStatus::Suspended, WebshopStatus::Rejected], true)) {
                throw ApiException::conflict('Resolve suspension or rejection separately before changing the domain.', 'domain_change_status_blocked');
            }
            if (! $current->isOwnershipVerified() || $current->verified_domain !== $expectedDomain || $domain === $expectedDomain) {
                throw ApiException::conflict('The verified domain changed or the requested domain is unchanged.', 'domain_change_conflict');
            }
            $profile = BusinessProfile::where('webshop_id', $current->id)->lockForUpdate()->firstOrFail();
            $profile->forceFill(['website_url' => $websiteUrl, 'website_domain' => $domain, 'confirmed_at' => null, 'confirmed_by' => null])->save();
            $current->verifications()->whereIn('status', [VerificationStatus::Pending, VerificationStatus::Submitted])
                ->update(['status' => VerificationStatus::Superseded, 'failure_reason' => 'domain_changed']);
            foreach (WebshopConnection::where('webshop_id', $current->id)->where('status', '!=', ConnectionStatus::Disconnected)->orderBy('id')->lockForUpdate()->get() as $connection) {
                $this->connections->disconnect($connection);
            }
            $current->forceFill(['ownership_verified_at' => null, 'ownership_method' => null, 'verified_domain' => null,
                'status' => WebshopStatus::AwaitingVerification, 'approved_at' => null, 'approved_by' => null,
                'review_requested_at' => null, 'status_reason' => 'domain_changed'])->save();
            $this->audit->record('onboarding.domain_changed', category: AuditCategory::Admin, resource: $current,
                webshopId: $current->id, actorUserId: $admin->id, metadata: ['old_domain' => $expectedDomain, 'new_domain' => $domain, 'reference' => $reason, 'via' => 'console']);

            return $current;
        });
    }
}
