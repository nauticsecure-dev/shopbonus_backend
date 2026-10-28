<?php

namespace App\Services\Funding;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Models\FundingInvoice;
use App\Models\FundingTopUp;
use App\Models\OfferVersion;
use App\Models\SponsorshipObligation;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Checkout\MollieClient;
use App\Services\Finance\FeeCalculator;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Merchant funding of sponsored rewards (Trello #24 §4): reward + 30% fee
 * on top, reserved in the webshop's funding wallet when a sponsored voucher
 * is reserved, settled (billed) at redemption, released when it lapses.
 * One obligation per voucher, so issuance or redemption never bills twice.
 * Only WalletService touches balances; there is no cash-out.
 */
class MerchantFunding
{
    /** Funding holds last as long as the voucher; the voucher lifecycle releases them. */
    private const HOLD_MINUTES = 60 * 24 * 3650;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly FeeCalculator $fees,
        private readonly MollieClient $mollie,
        private readonly AuditLogger $audit,
    ) {}

    public function wallet(Webshop|int $webshop): Wallet
    {
        return $this->wallets->walletFor('webshop', $webshop instanceof Webshop ? $webshop->id : $webshop, WalletKind::MerchantFunding, WalletUnit::EurCredit);
    }

    /**
     * Reward basis, fee and total for one voucher of these terms.
     *
     * @return array{reward_minor: int, fee_minor: int, total_minor: int, basis: string}
     */
    public function costOf(OfferVersion $v): array
    {
        $reward = $v->discount_type === 'fixed' ? $v->discount_value : (int) ($v->max_discount_minor ?? 0);
        $fee = $this->fees->feeOnReward($reward);

        return ['reward_minor' => $reward, 'fee_minor' => $fee, 'total_minor' => $reward + $fee, 'basis' => $v->discount_type === 'fixed' ? 'fixed' : (string) config('shopbonus.funding.percentage_basis')];
    }

    public function required(): bool
    {
        return (bool) config('shopbonus.funding.required', true);
    }

    /** Can the webshop fund one more voucher of these terms right now? */
    public function canFund(Webshop|int $webshop, OfferVersion $v): bool
    {
        return ! $this->required() || $this->wallet($webshop)->available >= $this->costOf($v)['total_minor'];
    }

    /**
     * Called by VoucherInventory inside the reservation transaction.
     */
    public function reserveFor(Voucher $voucher, OfferVersion $version): ?SponsorshipObligation
    {
        if (! $this->required()) {
            return null;
        }
        if (($existing = SponsorshipObligation::where('voucher_id', $voucher->id)->first()) !== null) {
            return $existing;
        }

        $cost = $this->costOf($version);
        try {
            $reservation = $this->wallets->reserve($this->wallet($voucher->webshop_id), $cost['total_minor'], 'sponsorship:'.$voucher->public_id, self::HOLD_MINUTES,
                ['source_type' => 'sponsorship', 'source_ref' => $voucher->public_id]);
        } catch (ApiException $e) {
            if ($e->errorCode === 'insufficient_funds') {
                $this->audit->recordSafely('funding.insufficient', category: AuditCategory::Financial, webshopId: $voucher->webshop_id, metadata: ['required' => $cost['total_minor']]);

                throw ApiException::conflict('This webshop has not funded enough rewards right now.', 'merchant_funding_insufficient', ['required_minor' => $cost['total_minor']]);
            }
            throw $e;
        }

        return SponsorshipObligation::create(['webshop_id' => $voucher->webshop_id, 'voucher_id' => $voucher->id, 'source_order_id' => $voucher->source_order_id,
            'reward_minor' => $cost['reward_minor'], 'fee_minor' => $cost['fee_minor'], 'total_minor' => $cost['total_minor'], 'fee_rate_bps' => $this->fees->feeRateBps(), 'basis' => $cost['basis'],
            'status' => 'reserved', 'wallet_reservation_id' => $reservation->id]);
    }

    /** Redemption: the reserved reward + fee are now owed (billed). Once. */
    public function settle(Voucher $voucher): void
    {
        $o = SponsorshipObligation::where('voucher_id', $voucher->id)->lockForUpdate()->first();
        if ($o === null || $o->status !== 'reserved') {
            return;
        }
        $this->wallets->capture(WalletReservation::findOrFail($o->wallet_reservation_id));
        $o->forceFill(['status' => 'settled', 'settled_at' => now()])->save();
        $this->audit->record('funding.settled', category: AuditCategory::Financial, resource: $o, webshopId: $o->webshop_id,
            metadata: ['reward' => $o->reward_minor, 'fee' => $o->fee_minor]);
    }

    /** Released/expired voucher: the hold goes back to available. Once. */
    public function release(Voucher $voucher, string $reason): void
    {
        $o = SponsorshipObligation::where('voucher_id', $voucher->id)->lockForUpdate()->first();
        if ($o === null || $o->status !== 'reserved') {
            return;
        }
        $this->wallets->release(WalletReservation::findOrFail($o->wallet_reservation_id), $reason);
        $o->forceFill(['status' => 'released', 'released_at' => now()])->save();
        $this->audit->record('funding.released', category: AuditCategory::Financial, resource: $o, webshopId: $o->webshop_id, metadata: ['reason' => $reason]);
    }

    // ------------------------------------------------------------------ top-ups

    public function startTopUp(Webshop $webshop, int $amountMinor, User $actor, ?string $key = null): FundingTopUp
    {
        [$topUp, $create] = DB::transaction(function () use ($webshop, $amountMinor, $actor, $key) {
            Webshop::whereKey($webshop->id)->lockForUpdate()->firstOrFail();
            $existing = $key === null ? null : FundingTopUp::where('webshop_id', $webshop->id)->where('created_by', $actor->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->amount_minor !== $amountMinor || $existing->currency !== 'EUR') {
                    throw ApiException::conflict('This idempotency key belongs to a different top-up.', 'funding_request_conflict');
                }

                return [$existing, false];
            }
            if (! $this->mollie->available()) {
                throw ApiException::conflict('Online payment is not available right now.', 'payments_unavailable');
            }
            $t = FundingTopUp::create(['webshop_id' => $webshop->id, 'amount_minor' => $amountMinor, 'currency' => 'EUR', 'state' => 'pending', 'created_by' => $actor->id,
                'request_key' => $key, 'provider_idempotency_key' => (string) Str::uuid(), 'creation_state' => 'creating', 'creation_attempted_at' => now()]);

            return [$t, true];
        }, 3);
        if (! $create) {
            return $topUp;
        }
        try {
            $payment = $this->mollie->createPayment($amountMinor, 'EUR', 'ShopBonus reward funding '.$webshop->name,
                config('shopbonus.identity.frontend_url').'/workspace/funding?top_up='.$topUp->public_id, route('api.v1.payments.mollie.webhook'),
                ['top_up_id' => $topUp->public_id], 'ideal', $topUp->provider_idempotency_key);
            if (preg_match('/^tr_[A-Za-z0-9]{4,40}$/D', $payment['id']) !== 1) {
                throw new \RuntimeException('Invalid payment reference.');
            }
        } catch (Throwable) {
            $topUp->forceFill(['creation_state' => 'uncertain'])->save();
            $this->audit->recordSafely('funding.top_up_creation_uncertain', category: AuditCategory::Financial, outcome: AuditOutcome::Failure,
                resource: $topUp, webshopId: $webshop->id, actorUserId: $actor->id, metadata: ['amount' => $amountMinor]);
            throw new ApiException('Payment creation could not be confirmed. Reuse the same request key and review this top-up; do not start another payment automatically.', 502, 'funding_creation_uncertain', ['top_up_id' => $topUp->public_id]);
        }
        DB::transaction(function () use ($topUp, $payment, $webshop, $actor, $amountMinor) {
            $topUp->forceFill(['provider_ref' => $payment['id'], 'checkout_url' => $payment['checkout_url'], 'creation_state' => 'created'])->save();
            $this->audit->record('funding.top_up_started', category: AuditCategory::Financial, resource: $topUp, webshopId: $webshop->id, actorUserId: $actor->id, metadata: ['amount' => $amountMinor]);
        });

        return $topUp;
    }

    /** Webshop top-up webhook: credits once after Mollie confirms a matching paid payment. */
    public function syncTopUp(FundingTopUp $topUp): FundingTopUp
    {
        $remote = $this->mollie->getPayment((string) $topUp->provider_ref);
        if ($remote['amount_minor'] !== $topUp->amount_minor || $remote['currency'] !== $topUp->currency || ($remote['metadata']['top_up_id'] ?? null) !== $topUp->public_id) {
            report(new \RuntimeException("Mollie payment {$topUp->provider_ref} does not match top-up {$topUp->public_id}."));

            return $topUp;
        }

        return DB::transaction(function () use ($topUp, $remote) {
            $t = FundingTopUp::whereKey($topUp->id)->lockForUpdate()->firstOrFail();
            if ($t->state !== 'pending') {
                return $t;
            }
            if ($remote['status'] === 'paid') {
                $entry = $this->wallets->credit($this->wallet($t->webshop_id), $t->amount_minor, 'top-up:'.$t->public_id, neverExpires: true,
                    meta: ['source_type' => 'top_up', 'source_ref' => $t->public_id, 'reason' => 'Mollie top-up']);
                $t->forceFill(['state' => 'paid', 'paid_at' => now(), 'credit_entry_id' => $entry->id])->save();
                $this->audit->record('funding.top_up_paid', category: AuditCategory::Financial, resource: $t, webshopId: $t->webshop_id, metadata: ['amount' => $t->amount_minor]);
            } elseif (in_array($remote['status'], ['failed', 'canceled', 'expired'], true)) {
                $t->forceFill(['state' => 'failed'])->save();
            }

            return $t;
        });
    }

    // ------------------------------------------------------------------ invoices

    /**
     * Builds (or rebuilds while draft) each webshop's statement of settled
     * obligations in $period (YYYY-MM). Returns the number of invoices touched.
     */
    public function prepareInvoices(string $period): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period, 'UTC') ?: throw new \InvalidArgumentException('Period must be YYYY-MM.');
        $end = $start->addMonth();
        $count = 0;

        $webshops = SponsorshipObligation::where('status', 'settled')->where('settled_at', '>=', $start)->where('settled_at', '<', $end)->distinct()->pluck('webshop_id');
        foreach ($webshops as $webshopId) {
            DB::transaction(function () use ($webshopId, $period, $start, $end, &$count) {
                $invoice = FundingInvoice::where('webshop_id', $webshopId)->where('period', $period)->lockForUpdate()->first();
                if ($invoice !== null && $invoice->status !== 'draft') {
                    return; // sent invoices are never changed
                }
                $invoice ??= FundingInvoice::create(['webshop_id' => $webshopId, 'period' => $period, 'reward_minor' => 0, 'fee_minor' => 0, 'lines' => 0, 'status' => 'draft']);

                SponsorshipObligation::where('webshop_id', $webshopId)->where('status', 'settled')->where('settled_at', '>=', $start)->where('settled_at', '<', $end)
                    ->where(fn ($q) => $q->whereNull('invoice_id')->orWhere('invoice_id', $invoice->id))->update(['invoice_id' => $invoice->id]);
                $sums = SponsorshipObligation::where('invoice_id', $invoice->id)->selectRaw('COALESCE(SUM(reward_minor),0) r, COALESCE(SUM(fee_minor),0) f, COUNT(*) n')->first();
                $invoice->forceFill(['reward_minor' => (int) $sums->getAttribute('r'), 'fee_minor' => (int) $sums->getAttribute('f'), 'lines' => (int) $sums->getAttribute('n')])->save();
                $count++;
            });
        }

        return $count;
    }
}
