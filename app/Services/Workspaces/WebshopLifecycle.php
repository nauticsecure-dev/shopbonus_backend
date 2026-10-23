<?php

namespace App\Services\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\VerificationMethod;
use App\Enums\VerificationStatus;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Agreements\AgreementService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Webshop status transitions after onboarding (Trello #16 statuses).
 *
 * Automatic approval happens only when ownership is verified by a challenge,
 * the current agreement is accepted, and nothing is flagged for review.
 * Every other approval, rejection and suspension is a Super Admin decision.
 */
class WebshopLifecycle
{
    public function __construct(private readonly AuditLogger $audit) {}

    private function agreements(): AgreementService
    {
        return app(AgreementService::class); // resolved lazily: AgreementService depends on this class
    }

    public function evaluate(Webshop $webshop): Webshop
    {
        $eligible = $webshop->status === WebshopStatus::Draft
            && $webshop->isOwnershipVerified()
            && empty($webshop->review_flags)
            && $webshop->businessProfile?->confirmed_at !== null
            && $this->agreements()->hasAcceptedCurrent($webshop);

        if (! $eligible) {
            return $webshop;
        }

        $webshop->forceFill(['status' => WebshopStatus::Approved, 'approved_at' => now(), 'approved_by' => null, 'status_reason' => null])->save();
        $this->audit->record('workspace.approved', category: AuditCategory::Workspace, resource: $webshop,
            webshopId: $webshop->id, metadata: ['automatic' => true, 'ownership_method' => $webshop->ownership_method]);

        return $webshop;
    }

    public function approve(User $admin, Webshop $webshop, ?string $reason, bool $confirmDuplicateClaim): Webshop
    {
        if (! in_array($webshop->status, [WebshopStatus::Draft, WebshopStatus::AwaitingVerification, WebshopStatus::VerificationFailed, WebshopStatus::PendingReview], true)) {
            throw ApiException::conflict('Only webshops in onboarding or review can be approved.', 'invalid_status_transition');
        }

        if ($webshop->businessProfile?->confirmed_at === null) {
            throw ApiException::conflict('The business details are not confirmed.', 'business_details_incomplete');
        }

        if (! $this->agreements()->hasAcceptedCurrent($webshop)) {
            throw ApiException::conflict('The current merchant agreement has not been accepted.', 'agreement_not_accepted');
        }

        $duplicate = in_array('duplicate_domain_claim', $webshop->review_flags ?? [], true);
        if ($duplicate && ! $confirmDuplicateClaim) {
            throw ApiException::conflict(
                'This webshop claims a domain verified by another webshop. Confirm the duplicate claim explicitly to approve.',
                'duplicate_claim_unconfirmed',
            );
        }

        return DB::transaction(function () use ($admin, $webshop, $reason, $duplicate) {
            $attributes = ['status' => WebshopStatus::Approved, 'approved_at' => now(), 'approved_by' => $admin->id, 'status_reason' => $reason];

            if (! $webshop->isOwnershipVerified()) {
                $attributes['ownership_verified_at'] = now();
                $attributes['ownership_method'] = VerificationMethod::ManualReview->value;
                // Never silently move a domain claim: a confirmed duplicate is approved without the domain.
                $attributes['verified_domain'] = $duplicate ? null : $webshop->businessProfile->website_domain;
            }

            $webshop->forceFill($attributes)->save();
            $webshop->verifications()->where('status', VerificationStatus::Submitted)
                ->update(['status' => VerificationStatus::Verified, 'verified_at' => now()]);

            $this->audit->record('workspace.approved', category: AuditCategory::Admin, resource: $webshop, webshopId: $webshop->id,
                metadata: ['automatic' => false, 'reason' => $reason, 'duplicate_claim_confirmed' => $duplicate]);

            return $webshop;
        });
    }

    public function reject(User $admin, Webshop $webshop, string $reason): Webshop
    {
        if (in_array($webshop->status, [WebshopStatus::Approved, WebshopStatus::Rejected, WebshopStatus::Suspended], true)) {
            throw ApiException::conflict('Only webshops in onboarding or review can be rejected.', 'invalid_status_transition');
        }

        return DB::transaction(function () use ($admin, $webshop, $reason) {
            $webshop->forceFill(['status' => WebshopStatus::Rejected, 'rejected_at' => now(), 'status_reason' => $reason])->save();
            $webshop->verifications()->whereIn('status', [VerificationStatus::Submitted, VerificationStatus::Pending])
                ->update(['status' => VerificationStatus::Failed, 'failure_reason' => 'rejected']);

            $this->audit->record('workspace.rejected', category: AuditCategory::Admin, resource: $webshop,
                webshopId: $webshop->id, metadata: ['reason' => $reason, 'by' => $admin->public_id]);

            return $webshop;
        });
    }

    public function suspend(User $admin, Webshop $webshop, string $reason): Webshop
    {
        if ($webshop->status === WebshopStatus::Suspended) {
            throw ApiException::conflict('This webshop is already suspended.', 'invalid_status_transition');
        }

        $webshop->forceFill(['status' => WebshopStatus::Suspended, 'suspended_at' => now(), 'status_reason' => $reason])->save();
        $this->audit->record('workspace.suspended', category: AuditCategory::Admin, resource: $webshop,
            webshopId: $webshop->id, metadata: ['reason' => $reason, 'by' => $admin->public_id]);

        return $webshop;
    }

    public function unsuspend(User $admin, Webshop $webshop, string $reason): Webshop
    {
        if ($webshop->status !== WebshopStatus::Suspended) {
            throw ApiException::conflict('This webshop is not suspended.', 'invalid_status_transition');
        }

        $status = match (true) {
            $webshop->approved_at !== null => WebshopStatus::Approved,
            $webshop->rejected_at !== null => WebshopStatus::Rejected,
            default => WebshopStatus::Draft,
        };

        $webshop->forceFill(['status' => $status, 'suspended_at' => null, 'status_reason' => $reason])->save();
        $this->audit->record('workspace.unsuspended', category: AuditCategory::Admin, resource: $webshop,
            webshopId: $webshop->id, metadata: ['reason' => $reason, 'restored_status' => $status->value, 'by' => $admin->public_id]);

        return $webshop;
    }
}
