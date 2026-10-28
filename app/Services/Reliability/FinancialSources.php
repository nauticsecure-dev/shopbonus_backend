<?php

namespace App\Services\Reliability;

use App\Enums\LedgerEntryType;
use App\Models\AffiliateClick;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\FundingTopUp;
use App\Models\SponsorshipObligation;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletPendingCredit;
use App\Models\WalletReconciliationFlag;
use App\Models\WalletReservation;
use Illuminate\Database\Eloquent\Model;

/** Local source invariants, not independent provider settlement or recognized revenue. */
class FinancialSources
{
    /** @var array<string, class-string<Model>> */
    public const MODELS = ['affiliate' => AffiliateTransaction::class, 'checkout' => VoucherCheckout::class,
        'funding' => FundingTopUp::class, 'sponsorship' => SponsorshipObligation::class, 'ledger' => WalletLedgerEntry::class];

    /** @return array{status:string,evidence:array<string,mixed>} */
    public function inspect(Model $source): array
    {
        [$issues, $unknown, $snapshot] = match (true) {
            $source instanceof AffiliateTransaction => $this->affiliate($source),
            $source instanceof VoucherCheckout => $this->checkout($source),
            $source instanceof FundingTopUp => $this->funding($source),
            $source instanceof SponsorshipObligation => $this->sponsorship($source),
            $source instanceof WalletLedgerEntry => $this->ledger($source),
            default => throw new \InvalidArgumentException('Unknown financial source.'),
        };

        return ['status' => $issues !== [] ? 'discrepancy' : ($unknown !== [] ? 'unknown' : 'matched'),
            'evidence' => ['version' => 2, 'issues' => $issues, 'unknown' => $unknown, 'snapshot' => $snapshot]];
    }

    /** @return array{list<string>,list<string>,array<string,mixed>} */
    private function affiliate(AffiliateTransaction $tx): array
    {
        $issues = $unknown = [];
        $snapshot = ['reward_status' => $tx->reward_status, 'network_status' => $tx->network_status,
            'commission_minor' => $tx->commission_minor, 'currency' => $tx->currency, 'reward_amount' => $tx->reward_amount, 'unit' => $tx->reward_unit];
        if ($tx->reward_status === 'pending') {
            $pending = WalletPendingCredit::find($tx->wallet_pending_id);
            $pendingWallet = $pending === null ? null : Wallet::find($pending->wallet_id);
            if ($pending === null || $pending->status !== 'pending' || $pending->amount !== $tx->reward_amount
                || $pending->source_type !== 'affiliate' || $pending->source_ref !== $tx->public_id
                || $pendingWallet?->owner_type !== 'user' || $pendingWallet->owner_id !== $tx->user_id
                || $pendingWallet->kind->value !== 'consumer_credit' || $pendingWallet->unit->value !== $tx->reward_unit) {
                $issues[] = 'affiliate_pending_identity';
            }
        }
        if ($tx->wallet_entry_id === null) {
            if (in_array($tx->reward_status, ['credited', 'reversed', 'reversal_flagged'], true)) {
                $issues[] = 'affiliate_credit_missing';
            }

            return [$issues, $unknown, $snapshot];
        }
        $entry = WalletLedgerEntry::find($tx->wallet_entry_id);
        $wallet = $entry === null ? null : Wallet::find($entry->wallet_id);
        if ($entry === null || $entry->type !== LedgerEntryType::Credit || $entry->amount !== $tx->reward_amount
            || $entry->source_type !== 'affiliate' || $entry->source_ref !== $tx->public_id
            || $wallet?->owner_type !== 'user' || $wallet->owner_id !== $tx->user_id
            || $wallet->kind->value !== 'consumer_credit' || $wallet->unit->value !== $tx->reward_unit) {
            $issues[] = 'affiliate_credit_identity';
        }
        $click = AffiliateClick::find($tx->click_id);
        $program = AffiliateProgram::find($tx->program_id);
        if ($click === null || $program === null || $click->user_id !== $tx->user_id || $click->webshop_id !== $tx->webshop_id
            || $click->program_id !== $tx->program_id || $program->connection_id !== $tx->connection_id) {
            $issues[] = 'affiliate_attribution';
        }
        if (WalletLedgerEntry::where('source_type', 'affiliate')->where('source_ref', $tx->public_id)->where('type', 'credit')->count() !== 1) {
            $issues[] = 'affiliate_duplicate_credit';
        }
        $proof = $tx->credit_evidence;
        $terms = $proof['rule_terms'] ?? null;
        if (! is_array($terms) || ! isset($proof['commission_minor'], $proof['reward_amount'], $proof['currency'], $proof['reward_unit'], $proof['ledger_entry'], $proof['rule_version'], $terms['version'], $terms['share_bps'], $terms['min_commission_minor'], $terms['unit'])) {
            $unknown[] = 'legacy_reward_evidence';
        } else {
            $snapshot['credit_evidence'] = $proof;
            $rule = new AffiliateRewardRule($terms);
            $expected = $rule->rewardFor((int) $proof['commission_minor']);
            if ($rule->share_bps < 0 || $rule->share_bps > 10000 || $rule->version !== $tx->reward_rule_version || $rule->version !== $proof['rule_version'] || $expected !== $entry?->amount
                || $proof['reward_amount'] !== $entry->amount || $proof['currency'] !== 'EUR'
                || $proof['reward_unit'] !== $wallet?->unit->value || $proof['ledger_entry'] !== $entry->public_id
                || ! in_array($proof['source'] ?? null, ['sync', 'import', 'admin'], true)) {
                $issues[] = 'affiliate_historical_calculation';
            }
            if (($proof['timing'] ?? null) === 'on_network_approval' && ($proof['network_status'] ?? null) !== 'approved') {
                $issues[] = 'affiliate_approval_required';
            }
            if (($proof['timing'] ?? null) === null) {
                $unknown[] = 'legacy_reward_timing';
            }
            if ($proof['commission_minor'] !== $tx->commission_minor && $tx->reward_status === 'credited') {
                $issues[] = 'affiliate_commission_changed_after_credit';
            }
            if ($terms['unit'] === 'eur_credit') {
                $allocation = $proof['allocation'] ?? null;
                if (is_array($allocation) && (($allocation['consumer_minor'] ?? null) !== $expected
                    || ($allocation['commission_minor'] ?? null) !== $proof['commission_minor']
                    || ($allocation['platform_unrecognized_minor'] ?? null) !== (int) $proof['commission_minor'] - $expected)) {
                    $issues[] = 'affiliate_allocation';
                }
                $snapshot['calculated_split'] = ['consumer_minor' => $expected,
                    'unrecognized_residual_minor' => (int) $proof['commission_minor'] - $expected];
            } else {
                $unknown[] = 'points_money_allocation_undefined';
            }
        }
        if ($entry !== null && in_array($tx->reward_status, ['reversed', 'reversal_flagged'], true)) {
            $reversed = -(int) WalletLedgerEntry::where('related_entry_id', $entry->id)->where('type', 'reversal')->sum('amount');
            $unrecovered = (int) WalletReconciliationFlag::where('wallet_id', $entry->wallet_id)->where('reason', 'reversal_exceeds_unspent')->where('details->credit', $entry->public_id)->sum('amount');
            $snapshot['reversal'] = ['original' => $entry->amount, 'compensated' => $reversed, 'flagged' => $unrecovered];
            if ($reversed + $unrecovered !== $entry->amount) {
                $issues[] = 'affiliate_reversal_incomplete';
            }
        }

        return [$issues, $unknown, $snapshot];
    }

    /** @return array{list<string>,list<string>,array<string,mixed>} */
    private function checkout(VoucherCheckout $c): array
    {
        $issues = [];
        $snapshot = ['state' => $c->state, 'price_minor' => $c->price_minor, 'wallet_part_minor' => $c->wallet_part_minor, 'payment_part_minor' => $c->payment_part_minor];
        if ($c->price_minor !== $c->wallet_part_minor + $c->payment_part_minor || min($c->wallet_part_minor, $c->payment_part_minor) < 0 || $c->currency !== 'EUR') {
            $issues[] = 'checkout_allocation';
        }
        if ($c->state === 'completed') {
            $v = Voucher::find($c->voucher_id);
            if ($v === null || $v->consumer_user_id !== $c->user_id || $v->webshop_id !== $c->webshop_id || $v->offer_id !== $c->offer_id || $v->origin->value !== 'wallet_purchase') {
                $issues[] = 'checkout_voucher_identity';
            }
            if ($c->wallet_part_minor > 0 && ! $this->reservation($c->wallet_reservation_id, $c->wallet_part_minor, 'captured', 'voucher_checkout', $c->public_id, 'user', $c->user_id)) {
                $issues[] = 'checkout_wallet_capture';
            }
            $payment = $c->payment;
            if ($c->payment_part_minor > 0 && ($payment === null || $payment->verified_at === null || $payment->status !== 'paid'
                || $payment->amount_minor !== $c->payment_part_minor || $payment->currency !== $c->currency)) {
                $issues[] = 'checkout_payment_proof';
            }
        }
        if ($c->state === 'paid_unfulfilled') {
            $issues[] = 'checkout_paid_unfulfilled';
        }

        return [$issues, [], $snapshot];
    }

    /** @return array{list<string>,list<string>,array<string,mixed>} */
    private function funding(FundingTopUp $top): array
    {
        $issues = $unknown = [];
        if ($top->state === 'paid') {
            $e = WalletLedgerEntry::find($top->credit_entry_id);
            $w = $e === null ? null : Wallet::find($e->wallet_id);
            if ($e === null || $e->type !== LedgerEntryType::Credit || $e->amount !== $top->amount_minor || $top->currency !== 'EUR'
                || $e->source_type !== 'top_up' || $e->source_ref !== $top->public_id || $top->paid_at === null || $top->provider_ref === null
                || $w?->owner_type !== 'webshop' || $w->owner_id !== $top->webshop_id || $w->kind->value !== 'merchant_funding' || $w->unit->value !== 'eur_credit') {
                $issues[] = 'funding_paid_credit_identity';
            }
            if (WalletLedgerEntry::where('source_type', 'top_up')->where('source_ref', $top->public_id)->where('type', 'credit')->count() !== 1) {
                $issues[] = 'funding_duplicate_credit';
            }
        } elseif ($top->credit_entry_id !== null) {
            $issues[] = 'funding_unpaid_credit';
        }
        if ($top->creation_state === 'uncertain') {
            $unknown[] = 'provider_creation_uncertain';
        }

        return [$issues, $unknown, ['state' => $top->state, 'creation_state' => $top->creation_state, 'amount_minor' => $top->amount_minor, 'currency' => $top->currency]];
    }

    /** @return array{list<string>,list<string>,array<string,mixed>} */
    private function sponsorship(SponsorshipObligation $o): array
    {
        $issues = $unknown = [];
        if ($o->total_minor !== $o->reward_minor + $o->fee_minor || min($o->reward_minor, $o->fee_minor) < 0) {
            $issues[] = 'sponsorship_allocation';
        }
        if ($o->fee_rate_bps === null) {
            $unknown[] = 'legacy_sponsorship_fee_rate';
        } elseif (intdiv($o->reward_minor * $o->fee_rate_bps + 5000, 10000) !== $o->fee_minor) {
            $issues[] = 'sponsorship_historical_fee';
        }
        $v = Voucher::find($o->voucher_id);
        if ($v === null || $v->webshop_id !== $o->webshop_id || $v->source_order_id !== $o->source_order_id) {
            $issues[] = 'sponsorship_voucher_identity';
        }
        $status = match ($o->status) {
            'reserved' => 'active', 'settled' => 'captured', 'released' => 'released', default => 'invalid'
        };
        if (! $this->reservation($o->wallet_reservation_id, $o->total_minor, $status, 'sponsorship', $v->public_id ?? '', 'webshop', $o->webshop_id)) {
            $issues[] = 'sponsorship_wallet_allocation';
        }
        if ($o->status === 'settled' && $v?->redeemed_at === null) {
            $issues[] = 'sponsorship_redemption_missing';
        }

        return [$issues, $unknown, ['status' => $o->status, 'reward_minor' => $o->reward_minor, 'fee_minor' => $o->fee_minor, 'fee_rate_bps' => $o->fee_rate_bps, 'total_minor' => $o->total_minor]];
    }

    private function reservation(?int $id, int $amount, string $status, string $source, string $ref, string $ownerType, int $ownerId): bool
    {
        $r = WalletReservation::find($id);
        $w = $r === null ? null : Wallet::find($r->wallet_id);
        if ($r === null || $r->amount !== $amount || $r->status !== $status || $r->source_type !== $source || $r->source_ref !== $ref
            || $w?->owner_type !== $ownerType || $w->owner_id !== $ownerId || $w->unit->value !== 'eur_credit') {
            return false;
        }
        if ($status === 'captured') {
            $entries = WalletLedgerEntry::where('wallet_id', $w->id)->where('idempotency_key', 'capture:'.$r->public_id)->get();

            return $entries->count() === 1 && $entries->first()->type === LedgerEntryType::Debit && $entries->first()->amount === -$amount;
        }

        return true;
    }

    /** @return array{list<string>,list<string>,array<string,mixed>} */
    private function ledger(WalletLedgerEntry $e): array
    {
        $issues = $unknown = [];
        if ($e->amount !== $e->available_delta + $e->reserved_delta || min($e->available_after, $e->reserved_after) < 0) {
            $issues[] = 'ledger_arithmetic';
        }
        if (in_array($e->source_type, ['voucher_checkout', 'sponsorship'], true) && $e->type === LedgerEntryType::Debit) {
            $reservation = WalletReservation::where('wallet_id', $e->wallet_id)->where('source_type', $e->source_type)->where('source_ref', $e->source_ref)->first();
            $linked = $reservation !== null && ($e->source_type === 'voucher_checkout'
                ? VoucherCheckout::where('public_id', $e->source_ref)->where('wallet_reservation_id', $reservation->id)->exists()
                : SponsorshipObligation::where('voucher_id', Voucher::where('public_id', $e->source_ref)->value('id'))->where('wallet_reservation_id', $reservation->id)->exists());
            if (! $linked || $reservation->status !== 'captured' || $e->idempotency_key !== 'capture:'.$reservation->public_id
                || $e->amount !== -$reservation->amount
                || WalletLedgerEntry::where('wallet_id', $e->wallet_id)->where('source_type', $e->source_type)->where('source_ref', $e->source_ref)->where('type', 'debit')->count() !== 1) {
                $issues[] = 'ledger_purchase_allocation';
            }
        }
        if ($e->type === LedgerEntryType::Credit && $e->source_type === 'affiliate'
            && ! AffiliateTransaction::where('public_id', $e->source_ref)->where('wallet_entry_id', $e->id)->exists()) {
            $issues[] = 'ledger_orphan_affiliate_credit';
        } elseif ($e->type === LedgerEntryType::Credit && $e->source_type === 'top_up'
            && ! FundingTopUp::where('public_id', $e->source_ref)->where('credit_entry_id', $e->id)->exists()) {
            $issues[] = 'ledger_orphan_funding_credit';
        } elseif ($e->type === LedgerEntryType::Reversal) {
            $original = WalletLedgerEntry::find($e->related_entry_id);
            if ($original === null || $original->wallet_id !== $e->wallet_id || $e->amount >= 0 || -$e->amount > $original->amount) {
                $issues[] = 'ledger_reversal_identity';
            }
        } elseif ($e->type === LedgerEntryType::Adjustment && ($e->actor_user_id === null || $e->reason === null)) {
            $issues[] = 'ledger_adjustment_authority';
        } elseif ($e->type === LedgerEntryType::Credit && ! in_array($e->source_type, ['affiliate', 'top_up'], true)) {
            $unknown[] = 'untyped_credit_source';
        }

        return [$issues, $unknown, ['type' => $e->type->value, 'amount' => $e->amount, 'source_type' => $e->source_type]];
    }
}
