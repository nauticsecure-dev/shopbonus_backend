<?php

namespace App\Services\Disputes;

use App\Enums\AuditCategory;
use App\Enums\VoucherOrigin;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherDispute;
use App\Models\VoucherDisputeEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Checkout\MollieClient;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Voucher disputes and compensation (Trello #20). Every compensation is a
 * ledger/inventory/payment operation with an idempotency key derived from
 * the dispute, applied under the dispute row lock, so it happens at most
 * once. Merchants can respond but never touch balances or vouchers.
 */
class DisputeService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly VoucherInventory $inventory,
        private readonly MollieClient $mollie,
        private readonly AuditLogger $audit,
    ) {}

    public function open(User $consumer, ?Voucher $voucher, ?VoucherCheckout $checkout, string $reason, string $description): VoucherDispute
    {
        $webshopId = $voucher->webshop_id ?? $checkout->webshop_id ?? throw new ApiException('Choose the voucher or purchase this is about.', 422, 'dispute_subject_required');
        $openKey = $voucher !== null ? 'voucher:'.$voucher->id : 'checkout:'.$checkout->id;

        try {
            return DB::transaction(function () use ($consumer, $voucher, $checkout, $reason, $description, $webshopId, $openKey) {
                $dispute = VoucherDispute::create(['user_id' => $consumer->id, 'webshop_id' => $webshopId, 'voucher_id' => $voucher?->id, 'checkout_id' => $checkout?->id,
                    'reason' => $reason, 'description' => $description, 'status' => 'open', 'open_key' => $openKey]);
                $this->event($dispute, 'consumer', $consumer->id, 'opened', null);
                $this->audit->record('dispute.opened', category: AuditCategory::Voucher, resource: $dispute, webshopId: $webshopId, actorUserId: $consumer->id, metadata: ['reason' => $reason]);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('There is already an open report for this voucher.', 'dispute_already_open');
        }
    }

    public function merchantRespond(VoucherDispute $dispute, int $actorUserId, string $message): VoucherDispute
    {
        return DB::transaction(function () use ($dispute, $actorUserId, $message) {
            $d = VoucherDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($d);
            $this->event($d, 'merchant', $actorUserId, 'merchant_response', $message);
            if ($d->status === 'waiting_for_merchant') {
                $d->forceFill(['status' => 'under_review'])->save();
                $this->event($d, 'system', null, 'status_changed', 'under_review');
            }

            return $d;
        });
    }

    public function setStatus(VoucherDispute $dispute, string $status, int $actorUserId, ?string $note): VoucherDispute
    {
        return DB::transaction(function () use ($dispute, $status, $actorUserId, $note) {
            $d = VoucherDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($d);
            $d->forceFill(['status' => $status])->save();
            $this->event($d, 'admin', $actorUserId, 'status_changed', trim($status.' '.($note ?? '')));

            return $d;
        });
    }

    /**
     * @param  'resolved'|'rejected'  $outcome
     * @param  'none'|'wallet_credit'|'replacement'|'refund'  $compensation
     */
    public function resolve(VoucherDispute $dispute, string $outcome, string $compensation, ?int $amountMinor, string $note, int $actorUserId): VoucherDispute
    {
        if ($outcome === 'rejected' && $compensation !== 'none') {
            throw new ApiException('A rejected report gets no compensation.', 422, 'invalid_resolution');
        }

        return DB::transaction(function () use ($dispute, $outcome, $compensation, $amountMinor, $note, $actorUserId) {
            $d = VoucherDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($d);

            [$amount, $ref] = match ($compensation) {
                'wallet_credit' => $this->walletCredit($d, $amountMinor, $note, $actorUserId),
                'replacement' => $this->replacement($d, $actorUserId),
                'refund' => $this->refund($d, $amountMinor),
                default => [null, null],
            };

            $d->forceFill(['status' => $outcome, 'compensation' => $compensation, 'compensation_amount_minor' => $amount, 'compensation_ref' => $ref,
                'compensated_at' => $compensation === 'none' ? null : now(), 'resolution_note' => $note, 'resolved_by' => $actorUserId, 'resolved_at' => now(), 'open_key' => null])->save();
            $this->event($d, 'admin', $actorUserId, 'resolved', $outcome.': '.$note);
            if ($compensation !== 'none') {
                $this->event($d, 'admin', $actorUserId, 'compensated', $compensation.($amount === null ? '' : ' '.$amount));
            }
            $this->audit->record('dispute.resolved', category: AuditCategory::Financial, resource: $d, webshopId: $d->webshop_id, actorUserId: $actorUserId,
                metadata: ['outcome' => $outcome, 'compensation' => $compensation, 'amount' => $amount]);

            return $d;
        });
    }

    /** Refund of a paid checkout that could not be fulfilled (D-053). Once. */
    public function refundUnfulfilled(VoucherCheckout $checkout, int $actorUserId, string $note): VoucherCheckout
    {
        return DB::transaction(function () use ($checkout, $actorUserId, $note) {
            $c = VoucherCheckout::with('payment')->whereKey($checkout->id)->lockForUpdate()->firstOrFail();
            if ($c->state !== 'paid_unfulfilled') {
                throw ApiException::conflict('Only paid but unfulfilled checkouts are refunded here.', 'checkout_not_refundable', ['state' => $c->state]);
            }
            $payment = $c->payment ?? throw ApiException::conflict('This checkout has no payment.', 'checkout_not_refundable');
            $refund = $this->mollie->refund($payment->provider_ref, $payment->amount_minor - $payment->refunded_minor, $payment->currency, 'ShopBonus voucher could not be delivered', 'refund:checkout:'.$c->public_id);
            $payment->forceFill(['refunded_minor' => $payment->amount_minor, 'refund_ref' => $refund['id']])->save();
            $c->forceFill(['state' => 'refunded'])->save();
            $this->audit->record('checkout.refunded', category: AuditCategory::Financial, resource: $c, webshopId: $c->webshop_id, actorUserId: $actorUserId,
                metadata: ['amount' => $payment->amount_minor, 'note' => mb_substr($note, 0, 200)]);

            return $c;
        });
    }

    // ------------------------------------------------------------------ compensation

    /** @return array{0: int, 1: string} */
    private function walletCredit(VoucherDispute $d, ?int $amount, string $note, int $actorUserId): array
    {
        if ($amount === null || $amount <= 0) {
            throw new ApiException('Give the credit amount in cents.', 422, 'amount_required');
        }
        $entry = $this->wallets->adjust($this->wallets->consumerWallet(User::findOrFail($d->user_id), WalletUnit::EurCredit), $amount,
            'dispute:'.$d->public_id, 'Compensation for voucher report: '.mb_substr($note, 0, 180), $actorUserId);

        return [$amount, $entry->public_id];
    }

    /** @return array{0: null, 1: string} */
    private function replacement(VoucherDispute $d, int $actorUserId): array
    {
        $original = $d->voucher_id === null ? null : Voucher::find($d->voucher_id);
        $offer = $original === null ? null : Offer::find($original->offer_id);
        if ($offer === null) {
            throw new ApiException('A replacement needs the original voucher.', 422, 'replacement_not_possible');
        }
        $voucher = $this->inventory->reserve($offer, VoucherOrigin::Promotional, 'dispute-replacement:'.$d->public_id, actorUserId: $actorUserId);
        if ($voucher->consumer_user_id === null) {
            $voucher = $this->inventory->issue($voucher, User::findOrFail($d->user_id));
        }

        return [null, $voucher->public_id];
    }

    /** @return array{0: int, 1: string} */
    private function refund(VoucherDispute $d, ?int $amount): array
    {
        $checkout = $d->checkout_id !== null ? VoucherCheckout::with('payment')->find($d->checkout_id)
            : VoucherCheckout::with('payment')->where('voucher_id', $d->voucher_id)->first();
        $payment = $checkout->payment ?? throw new ApiException('There is no card/bank payment to refund for this voucher.', 422, 'refund_not_possible');
        $amount ??= $payment->amount_minor - $payment->refunded_minor;
        if ($amount <= 0 || $amount > $payment->amount_minor - $payment->refunded_minor) {
            throw new ApiException('The refund exceeds what was paid.', 422, 'refund_exceeds_payment');
        }
        $refund = $this->mollie->refund($payment->provider_ref, $amount, $payment->currency, 'ShopBonus voucher report '.$d->public_id, 'refund:dispute:'.$d->public_id);
        $payment->forceFill(['refunded_minor' => $payment->refunded_minor + $amount, 'refund_ref' => $refund['id']])->save();

        return [$amount, $refund['id']];
    }

    private function assertOpen(VoucherDispute $d): void
    {
        if (in_array($d->status, VoucherDispute::CLOSED, true)) {
            throw ApiException::conflict('This report is already closed.', 'dispute_closed', ['status' => $d->status]);
        }
    }

    private function event(VoucherDispute $d, string $role, ?int $actor, string $type, ?string $note): void
    {
        VoucherDisputeEvent::create(['dispute_id' => $d->id, 'actor_role' => $role, 'actor_user_id' => $actor, 'type' => $type, 'note' => $note === null ? null : mb_substr($note, 0, 2000)]);
    }
}
