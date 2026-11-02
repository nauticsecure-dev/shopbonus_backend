<?php

namespace App\Services\Affiliate;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Manual reconciliation of received network funds; approval is never payment evidence. */
class SettlementRecorder
{
    public function __construct(private readonly AffiliateProcessor $processor, private readonly AuditLogger $audit) {}

    public function record(AffiliateTransaction $transaction, User $actor, string $reference, CarbonImmutable $settledAt): AffiliateTransaction
    {
        if (! $actor->isActive() || ! $actor->isSuperAdmin()) {
            throw new ApiException('Super Admin access required.', 403, 'forbidden');
        }
        if (trim($reference) === '' || strlen($reference) > 200 || $settledAt->isFuture()) {
            throw new ApiException('A settlement reference and past settlement date are required.', 422, 'invalid_settlement_evidence');
        }

        return DB::transaction(function () use ($transaction, $actor, $reference, $settledAt) {
            $tx = AffiliateTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($tx->network_status !== 'approved' || $tx->settlement_evidence !== null) {
                throw ApiException::conflict('Settlement requires an approved conversion without existing settlement evidence.', 'settlement_conflict');
            }
            $tx->forceFill(['settlement_evidence' => [
                'network' => $tx->network, 'external_id' => $tx->external_id,
                'commission_minor' => $tx->commission_minor, 'currency' => $tx->currency,
                'reference' => trim($reference), 'settled_at' => $settledAt->toIso8601String(),
                'recorded_by' => $actor->public_id, 'recorded_at' => now()->toIso8601String(),
            ]])->save();
            $this->audit->record('affiliate.settlement_recorded', category: AuditCategory::Integration, resource: $tx, actorUserId: $actor->id,
                metadata: ['reference' => trim($reference), 'settled_at' => $settledAt->toIso8601String()]);

            // Settlement recording does not waive fraud/threshold review.
            return $this->processor->ingest(\App\Models\AffiliateConnection::findOrFail($tx->connection_id), $this->data($tx), 'admin', $actor->id);
        });
    }

    private function data(AffiliateTransaction $tx): \App\Affiliate\Data\AffiliateTransactionData
    {
        return new \App\Affiliate\Data\AffiliateTransactionData($tx->external_id, '', null, null,
            $tx->order_amount_minor, $tx->currency, $tx->commission_minor, $tx->network_status, $tx->transaction_at, $tx->network_updated_at);
    }
}
