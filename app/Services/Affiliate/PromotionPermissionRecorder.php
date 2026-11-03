<?php

namespace App\Services\Affiliate;

use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\AffiliateProgram;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Operator-reviewed advertiser permission, scoped to one immutable offer version. */
class PromotionPermissionRecorder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function record(Offer $offer, AffiliateProgram $program, User $actor, string $reference, string $terms, CarbonImmutable $validUntil): Offer
    {
        if (! $actor->isActive() || ! $actor->isSuperAdmin()) {
            throw new ApiException('Super Admin access required.', 403, 'forbidden');
        }
        if (trim($reference) === '' || strlen($reference) > 200 || trim($terms) === '' || mb_strlen($terms) > 10000 || ! $validUntil->isFuture()) {
            throw new ApiException('An advertiser reference, applicable terms and future expiry are required.', 422, 'invalid_cashback_permission');
        }

        return DB::transaction(function () use ($offer, $program, $actor, $reference, $terms, $validUntil) {
            $locked = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $program = AffiliateProgram::with('connection')->whereKey($program->id)->lockForUpdate()->firstOrFail();
            if ($locked->acquisition !== OfferAcquisition::PromotionalCode || in_array($locked->status, [OfferStatus::Ended, OfferStatus::Archived], true)
                || $program->webshop_id !== $locked->webshop_id || ! $program->active || ! $program->cashback_allowed || $program->connection->status !== 'connected') {
                throw ApiException::conflict('Permission requires an eligible program for this promotional offer.', 'ineligible_cashback_permission');
            }
            $current = OfferVersion::whereKey($locked->current_version_id)->lockForUpdate()->firstOrFail();
            $permission = [
                'cashback_preserved' => true, 'network' => $program->connection->network,
                'program_external_id' => $program->external_id, 'connection_public_id' => $program->connection->public_id,
                'reference' => trim($reference), 'terms' => trim($terms),
                'verified_at' => now()->toIso8601String(), 'verified_by' => $actor->public_id,
                'valid_until' => $validUntil->toIso8601String(),
            ];
            // A new version leaves issued vouchers and their terms unchanged.
            $next = $locked->versions()->create([
                ...$current->only(OfferVersion::TERM_FIELDS), 'combinable' => true, 'cashback_permission' => $permission,
                'version' => $current->version + 1, 'created_by' => $actor->id,
                'locked_at' => $locked->status === OfferStatus::Draft ? null : now(),
            ]);
            $locked->forceFill(['current_version_id' => $next->id])->save();
            $this->audit->record('affiliate.promotion_permission_recorded', category: AuditCategory::Integration, resource: $locked,
                actorUserId: $actor->id, webshopId: $locked->webshop_id,
                metadata: ['version' => $next->version, 'permission' => $permission]);

            return $locked->setRelation('currentVersion', $next);
        });
    }
}
