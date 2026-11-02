<?php

namespace App\Services\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\MembershipRole;
use App\Exceptions\ApiException;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * D-108: the only way the owner role changes hands (Trello #16). The owner
 * proposes, the member accepts; both from a recently password-confirmed
 * session (route middleware). Acceptance swaps the roles atomically.
 */
class OwnershipTransfers
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function start(WebshopMembership $owner, string $memberPublicId): OwnershipTransfer
    {
        if ($owner->role !== MembershipRole::Owner || ! $owner->isActive()) {
            throw new ApiException('Only the owner can transfer ownership.', 403, 'owner_required');
        }
        $this->expireStale($owner->webshop_id);

        return DB::transaction(function () use ($owner, $memberPublicId) {
            Webshop::whereKey($owner->webshop_id)->lockForUpdate()->first(); // one pending transfer per workspace
            $target = WebshopMembership::where('webshop_id', $owner->webshop_id)
                ->whereHas('user', fn ($q) => $q->where('public_id', $memberPublicId))->with('user')->first();
            if ($target === null || ! $target->isActive() || ! $target->user->isActive()) {
                throw new ApiException('That person is not an active member of this workspace.', 404, 'member_not_found');
            }
            if ($target->role === MembershipRole::Owner) {
                throw ApiException::conflict('That member is already an owner.', 'already_owner');
            }
            if ($target->user->email_verified_at === null) {
                throw ApiException::conflict('That member must verify their email address first.', 'member_email_unverified');
            }
            if ($this->open($owner->webshop_id) !== null) {
                throw ApiException::conflict('An ownership transfer is already pending; cancel it first.', 'transfer_pending');
            }

            $transfer = OwnershipTransfer::create(['webshop_id' => $owner->webshop_id, 'from_user_id' => $owner->user_id, 'to_user_id' => $target->user_id,
                'status' => 'pending', 'expires_at' => now()->addHours((int) config('shopbonus.workspaces.ownership_transfer_ttl_hours', 72))]);
            $this->audit->record('ownership.transfer_started', category: AuditCategory::Workspace, resource: $transfer, webshopId: $owner->webshop_id,
                actorUserId: $owner->user_id, metadata: ['to' => $target->user->public_id]);

            return $transfer;
        });
    }

    /** The pending transfer the member is part of (owner or target), if any. */
    public function visibleTo(WebshopMembership $member): ?OwnershipTransfer
    {
        $transfer = $this->open($member->webshop_id);

        return $transfer !== null && in_array($member->user_id, [$transfer->from_user_id, $transfer->to_user_id], true) ? $transfer : null;
    }

    public function accept(WebshopMembership $member): OwnershipTransfer
    {
        $this->expireStale($member->webshop_id);

        $result = DB::transaction(function () use ($member) {
            $webshop = Webshop::whereKey($member->webshop_id)->lockForUpdate()->firstOrFail();
            $transfer = $this->open($member->webshop_id, lock: true);
            if ($transfer === null || $transfer->to_user_id !== $member->user_id) {
                throw new ApiException('There is no ownership transfer for you to accept.', 404, 'transfer_not_found');
            }
            $from = WebshopMembership::where('webshop_id', $webshop->id)->where('user_id', $transfer->from_user_id)->lockForUpdate()->first();
            $to = WebshopMembership::where('webshop_id', $webshop->id)->where('user_id', $transfer->to_user_id)->lockForUpdate()->first();
            // Anything that changed since the proposal voids it: authority is only as good as it is today.
            if ($from?->role !== MembershipRole::Owner || ! $from->isActive() || $to === null || ! $to->isActive() || $to->role === MembershipRole::Owner
                || ! $webshop->status->allowsMemberAccess() || ! User::whereKey($transfer->to_user_id)->sole()->isActive()) {
                return ['void' => $transfer];
            }

            $to->forceFill(['role' => MembershipRole::Owner, 'permissions' => null, 'granted_by' => $from->user_id])->save();
            $from->forceFill(['role' => MembershipRole::Manager, 'permissions' => null])->save();
            $transfer->forceFill(['status' => 'accepted', 'decided_at' => now()])->save();
            $this->audit->record('ownership.transfer_accepted', category: AuditCategory::Workspace, resource: $transfer, webshopId: $webshop->id,
                actorUserId: $member->user_id, metadata: ['from' => $transfer->from->public_id, 'to' => $transfer->to->public_id]);

            return $transfer;
        });
        if (is_array($result)) {
            // Voided after the transaction, so the refusal below cannot roll it back.
            $void = $result['void'];
            $void->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();
            $this->audit->record('ownership.transfer_voided', category: AuditCategory::Workspace, resource: $void, webshopId: $member->webshop_id, actorUserId: $member->user_id);

            throw ApiException::conflict('This ownership transfer is no longer valid.', 'transfer_invalid');
        }

        return $result;
    }

    /** The owner cancels, the target declines. */
    public function withdraw(WebshopMembership $member): OwnershipTransfer
    {
        $this->expireStale($member->webshop_id);

        return DB::transaction(function () use ($member) {
            $transfer = $this->open($member->webshop_id, lock: true);
            if ($transfer === null || ! in_array($member->user_id, [$transfer->from_user_id, $transfer->to_user_id], true)) {
                throw new ApiException('There is no pending ownership transfer.', 404, 'transfer_not_found');
            }
            $status = $member->user_id === $transfer->from_user_id ? 'cancelled' : 'declined';
            $transfer->forceFill(['status' => $status, 'decided_at' => now()])->save();
            $this->audit->record("ownership.transfer_{$status}", category: AuditCategory::Workspace, resource: $transfer, webshopId: $member->webshop_id, actorUserId: $member->user_id);

            return $transfer;
        });
    }

    private function open(int $webshopId, bool $lock = false): ?OwnershipTransfer
    {
        return OwnershipTransfer::with(['from', 'to'])->where('webshop_id', $webshopId)->where('status', 'pending')->where('expires_at', '>', now())
            ->when($lock, fn ($q) => $q->lockForUpdate())->latest('id')->first();
    }

    /** Outside the caller's transaction, so a refused request does not roll the expiry back. */
    private function expireStale(int $webshopId): void
    {
        OwnershipTransfer::where('webshop_id', $webshopId)->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
    }
}
