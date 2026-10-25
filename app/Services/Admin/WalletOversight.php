<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReconciliationFlag;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

class WalletOversight
{
    public function __construct(private readonly WalletService $wallets, private readonly AuditLogger $audit) {}

    public function resolve(WalletReconciliationFlag $flag, User $actor, string $reason): WalletReconciliationFlag
    {
        return DB::transaction(function () use ($flag, $actor, $reason) {
            // Same lock order as wallet operations. Resolving never changes balances.
            $wallet = Wallet::whereKey($flag->wallet_id)->lockForUpdate()->firstOrFail();
            $flag = WalletReconciliationFlag::whereKey($flag->id)->lockForUpdate()->firstOrFail();
            if ($flag->status !== 'open') {
                throw ApiException::conflict('This reconciliation flag is already resolved.', 'flag_already_resolved');
            }
            if ($flag->reason === 'balance_mismatch' && $this->wallets->mismatches($wallet) !== []) {
                throw ApiException::conflict('The wallet still has a balance mismatch.', 'wallet_still_unreconciled');
            }
            $flag->forceFill(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_reason' => $reason])->save();
            $this->audit->record('admin.wallet_flag_resolved', category: AuditCategory::Financial, resource: $wallet,
                actorUserId: $actor->id, metadata: ['flag' => $flag->public_id, 'reason' => $reason]);

            return $flag;
        });
    }
}
