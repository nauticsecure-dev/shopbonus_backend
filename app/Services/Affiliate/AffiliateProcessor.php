<?php

namespace App\Services\Affiliate;

use App\Affiliate\Data\AffiliateTransactionData;
use App\Enums\AuditCategory;
use App\Enums\WalletUnit;
use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateReconciliationEvent;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletPendingCredit;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Normalised conversions in, exactly-once rewards out (Trello #19
 * "Commission reconciliation", "Points earning rules"; #23 test 6).
 *
 * - One row per (network, external ID): retries and replays update it.
 * - A network update older than the last applied one is ignored.
 * - Attribution needs credible evidence: our click reference, for the same
 *   webshop, inside the attribution window. Otherwise: unmatched (review).
 * - A second report of the same click or order reference: disputed, no reward.
 * - Postbacks are untrusted: they only record "tracked"; rewards need sync/import.
 * - Rewards follow `affiliate.credit_timing` (default on_network_approval).
 */
class AffiliateProcessor
{
    private const TRUSTED = ['sync', 'import', 'admin'];

    public function __construct(private readonly WalletService $wallets, private readonly AuditLogger $audit) {}

    public function ingest(AffiliateConnection $connection, AffiliateTransactionData $data, string $source, ?int $actorUserId = null): AffiliateTransaction
    {
        try {
            return DB::transaction(fn () => $this->apply($connection, $data, $source, $actorUserId));
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery created the row first: apply as an update.
            return DB::transaction(fn () => $this->apply($connection, $data, $source, $actorUserId));
        }
    }

    /** Re-evaluates rewards after an admin resolution. */
    public function settle(AffiliateTransaction $tx, string $source, ?int $actorUserId = null): AffiliateTransaction
    {
        return DB::transaction(function () use ($tx, $source, $actorUserId) {
            $tx = AffiliateTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();
            $this->reward($tx, $source, $actorUserId, skipReview: true);

            return $tx;
        });
    }

    private function apply(AffiliateConnection $connection, AffiliateTransactionData $data, string $source, ?int $actorUserId): AffiliateTransaction
    {
        $tx = AffiliateTransaction::where('network', $connection->network)->where('external_id', $data->externalId)->lockForUpdate()->first();
        $trusted = in_array($source, self::TRUSTED, true);

        if ($tx !== null) {
            if ($tx->network_updated_at !== null && $data->networkUpdatedAt !== null && $data->networkUpdatedAt->lessThan($tx->network_updated_at)) {
                $this->event($tx, $source, $tx->status, $tx->status, $data->networkStatus, 'stale_update_ignored', $actorUserId);

                return $tx;
            }
            if (! $trusted) {
                return $tx; // a postback never changes a row the network API already reported
            }

            $from = $tx->status;
            $tx->forceFill([
                'order_amount_minor' => $data->orderAmountMinor, 'commission_minor' => $data->commissionMinor, 'currency' => $data->currency,
                'network_status' => $data->networkStatus, 'network_updated_at' => $data->networkUpdatedAt ?? $tx->network_updated_at, 'last_synced_at' => now(),
                'status' => in_array($tx->status, ['unmatched', 'disputed'], true) ? $tx->status : $data->networkStatus,
            ])->save();
            if ($from !== $tx->status || $tx->wasChanged('network_status')) {
                $this->event($tx, $source, $from, $tx->status, $data->networkStatus, null, $actorUserId);
            }
            $this->reward($tx, $source, $actorUserId);

            return $tx;
        }

        $program = AffiliateProgram::where('connection_id', $connection->id)->where('external_id', $data->programExternalId)->first();
        [$click, $reason] = $this->attribute($program, $data);
        $orderHash = $data->orderReference === null || $program?->webshop_id === null ? null
            : hash_hmac('sha256', 'affiliate-order|'.$program->webshop_id.'|'.$data->orderReference, (string) config('app.key'));
        $duplicate = $this->duplicateOf($click, $program?->webshop_id, $orderHash);

        $status = match (true) {
            $duplicate !== null => 'disputed',
            $reason !== null => 'unmatched',
            ! $trusted => 'tracked',
            default => $data->networkStatus,
        };

        $tx = AffiliateTransaction::create([
            'network' => $connection->network, 'connection_id' => $connection->id, 'external_id' => $data->externalId,
            'program_id' => $program?->id, 'click_id' => $click?->id, 'user_id' => $click?->user_id, 'webshop_id' => $program?->webshop_id,
            'order_reference_hash' => $orderHash, 'order_amount_minor' => $data->orderAmountMinor, 'currency' => $data->currency,
            'commission_minor' => $data->commissionMinor, 'network_status' => $data->networkStatus, 'status' => $status, 'reward_status' => 'none',
            'review_reason' => $duplicate !== null ? 'duplicate' : $reason, 'duplicate_of_id' => $duplicate?->id,
            'transaction_at' => $data->transactionAt, 'network_updated_at' => $data->networkUpdatedAt, 'last_synced_at' => $trusted ? now() : null,
        ]);
        $this->event($tx, $source, null, $status, $data->networkStatus, $tx->review_reason, $actorUserId);

        if ($trusted) {
            $this->reward($tx, $source, $actorUserId);
        }

        return $tx;
    }

    /**
     * @return array{0: AffiliateClick|null, 1: string|null} click, or the reason there is none
     */
    private function attribute(?AffiliateProgram $program, AffiliateTransactionData $data): array
    {
        if ($program === null || $program->webshop_id === null) {
            return [null, 'unknown_program'];
        }
        if ($data->clickReference === null) {
            return [null, 'no_click_reference'];
        }

        $click = AffiliateClick::where('reference', $data->clickReference)->first();
        $window = (int) config('shopbonus.affiliate.attribution_window_days', 30);

        return match (true) {
            $click === null => [null, 'unknown_click'],
            $click->webshop_id !== $program->webshop_id => [null, 'click_for_other_webshop'],
            $click->created_at->greaterThan($data->transactionAt->addMinutes(5)) || $click->created_at->lessThan($data->transactionAt->subDays($window)) => [null, 'click_outside_window'],
            $click->user_id === null => [$click, 'anonymous_click'],
            default => [$click, null],
        };
    }

    private function duplicateOf(?AffiliateClick $click, ?int $webshopId, ?string $orderHash): ?AffiliateTransaction
    {
        return AffiliateTransaction::where(function ($q) use ($click, $webshopId, $orderHash) {
            $q->when($click !== null, fn ($q) => $q->orWhere('click_id', $click?->id))
                ->when($orderHash !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('webshop_id', $webshopId)->where('order_reference_hash', $orderHash)));
        })->when($click === null && $orderHash === null, fn ($q) => $q->whereRaw('1 = 0'))->orderBy('id')->first();
    }

    private function reward(AffiliateTransaction $tx, string $source, ?int $actorUserId, bool $skipReview = false): void
    {
        if ($tx->user_id === null || in_array($tx->status, ['unmatched', 'disputed', 'tracked'], true)) {
            return;
        }

        $rule = AffiliateRewardRule::current($tx->transaction_at);
        $instant = config('shopbonus.affiliate.credit_timing') === 'on_validated_attribution';

        if ($tx->reward_status === 'none') {
            $amount = $rule === null ? 0 : $rule->rewardFor($tx->commission_minor);
            if ($rule === null || $amount === 0 || ! in_array($tx->network_status, ['pending', 'approved'], true)) {
                return; // no rule in force → no reward
            }
            if (! $skipReview && $rule->review_above_minor !== null && $amount > $rule->review_above_minor) {
                $from = $tx->status;
                $tx->forceFill(['status' => 'disputed', 'review_reason' => 'review_threshold'])->save();
                $this->event($tx, $source, $from, 'disputed', $tx->network_status, 'review_threshold', $actorUserId);

                return;
            }
            $tx->forceFill(['reward_amount' => $amount, 'reward_unit' => $rule->unit->value, 'reward_rule_version' => $rule->version])->save();
        }

        $wallet = $this->wallets->consumerWallet(User::findOrFail($tx->user_id), WalletUnit::from((string) $tx->reward_unit));
        $meta = ['source_type' => 'affiliate', 'source_ref' => $tx->public_id];
        $creditKey = 'affiliate:credit:'.$tx->public_id;

        match (true) {
            // Card #23 instant credit: valid attribution is enough; later network rejection does not reverse it.
            $instant && $tx->reward_status === 'none' => $this->credited($tx, $this->wallets->credit($wallet, (int) $tx->reward_amount, $creditKey, meta: $meta + ['reason' => 'Affiliate purchase'])),
            $instant && in_array($tx->network_status, ['rejected', 'reversed'], true) && $tx->reward_status === 'credited' => $tx->forceFill(['review_reason' => 'network_'.$tx->network_status.'_after_credit'])->save(),

            // Safe default: pending until the network approves.
            ! $instant && $tx->network_status === 'pending' && $tx->reward_status === 'none' => $this->pending($tx, $this->wallets->addPending($wallet, (int) $tx->reward_amount, 'affiliate:'.$tx->public_id, $meta + ['description' => 'Affiliate purchase, awaiting confirmation'])),
            ! $instant && $tx->network_status === 'approved' && in_array($tx->reward_status, ['none', 'pending'], true) => $this->approve($tx, $wallet, $creditKey, $meta),
            ! $instant && in_array($tx->network_status, ['rejected', 'reversed'], true) && $tx->reward_status === 'pending' => $this->cancelPending($tx),
            ! $instant && in_array($tx->network_status, ['rejected', 'reversed'], true) && $tx->reward_status === 'credited' => $this->reverseCredit($tx, $actorUserId),
            default => null,
        };
    }

    /**
     * @param  array{source_type: string, source_ref: string}  $meta
     */
    private function approve(AffiliateTransaction $tx, Wallet $wallet, string $key, array $meta): void
    {
        $pending = $tx->wallet_pending_id === null ? null : WalletPendingCredit::find($tx->wallet_pending_id);
        $rule = AffiliateRewardRule::where('version', $tx->reward_rule_version)->first();
        $amount = $rule?->rewardFor($tx->commission_minor) ?? (int) $tx->reward_amount; // approved commission may differ from the expected one

        if ($pending !== null && $pending->status === 'pending' && $pending->amount === $amount) {
            $this->credited($tx, $this->wallets->confirmPending($pending));

            return;
        }
        if ($pending !== null && $pending->status === 'pending') {
            $this->wallets->cancelPending($pending);
        }
        $tx->forceFill(['reward_amount' => $amount])->save();
        if ($amount > 0) {
            $this->credited($tx, $this->wallets->credit($wallet, $amount, $key, meta: $meta + ['reason' => 'Affiliate purchase']));
        }
    }

    private function pending(AffiliateTransaction $tx, WalletPendingCredit $pending): void
    {
        $tx->forceFill(['reward_status' => 'pending', 'wallet_pending_id' => $pending->id])->save();
    }

    private function credited(AffiliateTransaction $tx, WalletLedgerEntry $entry): void
    {
        $tx->forceFill(['reward_status' => 'credited', 'wallet_entry_id' => $entry->id])->save();
    }

    private function cancelPending(AffiliateTransaction $tx): void
    {
        $this->wallets->cancelPending(WalletPendingCredit::findOrFail($tx->wallet_pending_id));
        $tx->forceFill(['reward_status' => 'cancelled'])->save();
    }

    private function reverseCredit(AffiliateTransaction $tx, ?int $actorUserId): void
    {
        $result = $this->wallets->reverse(WalletLedgerEntry::findOrFail($tx->wallet_entry_id), 'affiliate:reverse:'.$tx->public_id, 'network_'.$tx->network_status, $actorUserId);
        $tx->forceFill(['reward_status' => $result['unrecovered'] > 0 ? 'reversal_flagged' : 'reversed'])->save();
        $this->audit->record('affiliate.reward_reversed', category: AuditCategory::Financial, resource: $tx, actorUserId: $actorUserId,
            metadata: ['unrecovered' => $result['unrecovered']]);
    }

    private function event(AffiliateTransaction $tx, string $source, ?string $from, string $to, ?string $networkStatus, ?string $note, ?int $actorUserId): void
    {
        AffiliateReconciliationEvent::create(['transaction_id' => $tx->id, 'source' => $source, 'from_status' => $from, 'to_status' => $to,
            'network_status' => $networkStatus, 'note' => $note, 'actor_user_id' => $actorUserId]);
    }
}
