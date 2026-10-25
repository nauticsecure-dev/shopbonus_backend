<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\UserStatus;
use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\AdminApprovalRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/** All amounts/units require dual approval (owner delegated policy choice, 2026-10-25). */
class WalletAdjustments
{
    public function __construct(private readonly WalletService $wallets, private readonly AuditLogger $audit) {}

    public function request(User $actor, Wallet $wallet, User $consumer, WalletLedgerEntry $source, int $amount, string $reason, string $verification, string $key): AdminApprovalRequest
    {
        return DB::transaction(function () use ($actor, $wallet, $consumer, $source, $amount, $reason, $verification, $key) {
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $existing = AdminApprovalRequest::where('requested_by', $actor->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->wallet_id !== $wallet->id || $existing->consumer_id !== $consumer->id || $existing->source_entry_id !== $source->id
                    || $existing->amount !== $amount || $existing->reason !== $reason || $existing->verification_note !== $verification) {
                    throw ApiException::conflict('The request key was already used for different evidence.', 'approval_key_conflict');
                }

                return $existing;
            }
            if ($wallet->kind !== WalletKind::ConsumerCredit || $wallet->owner_type !== 'user' || $wallet->owner_id !== $consumer->id
                || $source->wallet_id !== $wallet->id || $consumer->status !== UserStatus::Active || $consumer->email_verified_at === null) {
                throw ApiException::conflict('Verify an active consumer and a transaction from this consumer wallet.', 'adjustment_verification_failed');
            }
            $request = AdminApprovalRequest::create(['wallet_id' => $wallet->id, 'consumer_id' => $consumer->id, 'source_entry_id' => $source->id,
                'requested_by' => $actor->id, 'amount' => $amount, 'unit' => $wallet->unit->value, 'reason' => $reason,
                'verification_note' => $verification, 'request_key' => $key, 'policy_version' => 'dual-admin-v1', 'status' => 'pending']);
            $this->audit->record('admin.wallet_adjustment_requested', category: AuditCategory::Financial, resource: $request,
                actorUserId: $actor->id, metadata: ['wallet' => $wallet->public_id, 'source_entry' => $source->public_id, 'amount' => $amount, 'unit' => $wallet->unit->value]);

            return $request;
        }, 3);
    }

    public function review(AdminApprovalRequest $request, User $reviewer, string $decision, string $note): AdminApprovalRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $note) {
            $wallet = Wallet::whereKey($request->wallet_id)->lockForUpdate()->firstOrFail();
            $request = AdminApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->requested_by === $reviewer->id) {
                throw new ApiException('A different administrator must review this adjustment.', 403, 'second_admin_required');
            }
            if ($request->status !== 'pending') {
                if ($request->reviewed_by === $reviewer->id && $request->status === $decision) {
                    return $request;
                }
                throw ApiException::conflict('This adjustment has already been reviewed.', 'approval_already_reviewed');
            }
            $entry = null;
            if ($decision === 'approved') {
                $consumer = User::findOrFail($request->consumer_id);
                $requester = User::findOrFail($request->requested_by);
                if ($consumer->status !== UserStatus::Active || $consumer->email_verified_at === null || $requester->status !== UserStatus::Active || ! $requester->isSuperAdmin()) {
                    throw ApiException::conflict('The consumer or requesting administrator is no longer eligible.', 'adjustment_verification_failed');
                }
                $entry = $this->wallets->adjust($wallet, $request->amount, 'admin-approval:'.$request->public_id, $request->reason, $reviewer->id);
            }
            $request->forceFill(['status' => $decision, 'reviewed_by' => $reviewer->id, 'review_note' => $note, 'reviewed_at' => now(), 'posted_entry_id' => $entry?->id])->save();
            $this->audit->record('admin.wallet_adjustment_reviewed', category: AuditCategory::Financial, resource: $request,
                actorUserId: $reviewer->id, metadata: ['decision' => $decision, 'note' => $note, 'entry' => $entry?->public_id]);

            return $request;
        }, 3);
    }
}
