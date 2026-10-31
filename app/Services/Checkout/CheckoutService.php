<?php

namespace App\Services\Checkout;

use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCheckoutPayment;
use App\Models\VoucherCode;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Services\Audit\AuditLogger;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Buying a wallet_purchase offer with wallet credit, Mollie or both
 * (Trello #20 "Checkout process", #23 "Wallet/Mollie voucher").
 *
 * - Wallet-only: wallet debit, code allocation and voucher issuance happen
 *   in one transaction; any failure rolls all of it back.
 * - With Mollie: the wallet part and one code are reserved (bounded), the
 *   voucher is issued only after the payment is verified with Mollie, and
 *   everything is released on failure/expiry. A redirect proves nothing.
 * - Idempotent per (consumer, Idempotency-Key); finalisation exactly once.
 * - Paid but not fulfillable → paid_unfulfilled, alerted, refundable.
 */
class CheckoutService
{
    public function __construct(
        private readonly VoucherInventory $inventory,
        private readonly WalletService $wallets,
        private readonly MollieClient $mollie,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Server-side price and split. Never mutates anything.
     *
     * @return array{
     *   wallet_unit: string,
     *   price_points: int|null,
     *   wallet_available_points: int,
     *   wallet_part_points: int,
     *   shortfall_points: int,
     *   price_minor: int,
     *   currency: string,
     *   wallet_available: int,
     *   wallet_part_minor: int,
     *   payment_part_minor: int,
     *   shortfall_minor: int,
     *   validity_days: int|null,
     *   blockers: list<string>
     * }
     */
    public function quote(User $user, Offer $offer, bool $useWallet = true, WalletUnit $unit = WalletUnit::EurCredit): array
    {
        $version = $offer->currentVersion;
        $points = $unit === WalletUnit::Points;
        $price = $points ? 0 : (int) ($version->price_minor ?? 0);
        $pointsPrice = $points ? $version?->price_points : null;
        $available = Wallet::where('owner_type', 'user')->where('owner_id', $user->id)->where('kind', WalletKind::ConsumerCredit)->where('unit', $unit)->value('available') ?? 0;
        $walletPart = $useWallet && ! $points ? min($available, $price) : 0;
        $pointsPart = $points && $useWallet ? min($available, (int) $pointsPrice) : 0;
        $paymentPart = $price - $walletPart;
        $shortfall = max(0, $price - $available);

        $blockers = array_keys(array_filter([
            'not_for_sale' => $offer->acquisition !== OfferAcquisition::WalletPurchase || ($points ? (int) $pointsPrice <= 0 : $price <= 0),
            'offer_not_active' => ! $offer->status->isPurchasableStorage(),
            'webshop_not_active' => $offer->webshop()->firstOrFail()->status !== WebshopStatus::Approved,
            'outside_validity' => ($version?->starts_at?->isFuture() ?? false) || ($version?->ends_at?->isPast() ?? false),
            'sold_out' => ! VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->exists(),
            // Shortfall requiring money: blocked when Mollie is unavailable (covers insufficient wallet).
            'payments_unavailable' => $paymentPart > 0 && ! $this->mollie->available(),
            'insufficient_points' => $points && (int) $pointsPrice > $available,
            'points_wallet_required' => $points && ! $useWallet,
            'email_not_verified' => $user->email_verified_at === null,
        ]));

        return [
            'wallet_unit' => $unit->value,
            'price_points' => $pointsPrice,
            'wallet_available_points' => $points ? $available : 0,
            'wallet_part_points' => $pointsPart,
            'shortfall_points' => $points ? max(0, (int) $pointsPrice - $available) : 0,
            'price_minor' => $price,
            'currency' => (string) ($version->currency ?? 'EUR'),
            'wallet_available' => $points ? 0 : $available,
            'wallet_part_minor' => $walletPart,
            'payment_part_minor' => $paymentPart,
            'shortfall_minor' => $shortfall,
            'validity_days' => $version?->redemption_validity_days,
            'blockers' => $blockers,
        ];
    }

    public function start(User $user, Offer $offer, string $key, bool $useWallet = true, WalletUnit $unit = WalletUnit::EurCredit): VoucherCheckout
    {
        if (($existing = $this->byKey($user, $key)) !== null) {
            $this->assertSamePurchase($existing, $offer, $unit);
            // A retry after an unanswered payment request asks Mollie again with the same key.
            if ($existing->state === 'pending_payment' && $existing->payment_part_minor > 0 && $existing->payment()->doesntExist()) {
                $this->createPayment($existing, $offer);
            }

            return $existing->refresh();
        }

        $quote = $this->quote($user, $offer, $useWallet, $unit);
        if ($quote['blockers'] !== []) {
            throw ApiException::conflict('This voucher cannot be bought right now.', 'checkout_blocked', ['blockers' => $quote['blockers']]);
        }

        try {
            // Deadlocks are retried; the lock order in open() makes them rare.
            $checkout = DB::transaction(fn () => $this->open($user, $offer, $key, $useWallet, $unit), 3);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->byKey($user, $key) ?? throw ApiException::conflict('Checkout conflict, retry.', 'checkout_conflict');
            $this->assertSamePurchase($existing, $offer, $unit);

            return $existing;
        }

        if ($checkout->state === 'pending_payment') {
            $this->createPayment($checkout, $offer);
        }

        return $checkout->refresh();
    }

    /** Mollie webhook: never trusts the request; asks Mollie. */
    public function handleWebhook(string $paymentId): void
    {
        $payment = VoucherCheckoutPayment::where('provider_ref', $paymentId)->first() ?? $this->recoverPayment($paymentId);
        if ($payment === null) {
            return; // unknown or forged ID: nothing to do, and nothing revealed
        }

        $this->sync(VoucherCheckout::findOrFail($payment->checkout_id));
    }

    /**
     * A payment Mollie created whose answer never reached us (timeout, crash
     * before the insert): Mollie's own record names the checkout. Linked only
     * to an unlinked checkout of exactly that amount and currency.
     */
    private function recoverPayment(string $paymentId): ?VoucherCheckoutPayment
    {
        try {
            $remote = $this->mollie->getPayment($paymentId);
        } catch (ApiException $e) {
            $status = (int) ($e->details['provider_status'] ?? 0);
            if ($status >= 400 && $status < 500) {
                return null; // Mollie does not know it either
            }
            throw $e; // unreachable: a non-200 makes Mollie retry the webhook
        }

        $checkout = VoucherCheckout::where('public_id', (string) ($remote['metadata']['checkout_id'] ?? ''))->first();
        if ($checkout === null || $checkout->payment_part_minor <= 0 || $remote['amount_minor'] !== $checkout->payment_part_minor || $remote['currency'] !== $checkout->currency) {
            return null;
        }
        if ($checkout->payment()->exists()) {
            report(new \RuntimeException("Mollie payment {$paymentId} names checkout {$checkout->public_id}, which already has another payment."));

            return null;
        }

        $payment = VoucherCheckoutPayment::firstOrCreate(['checkout_id' => $checkout->id], ['provider' => 'mollie', 'provider_ref' => $remote['id'], 'amount_minor' => $checkout->payment_part_minor,
            'currency' => $checkout->currency, 'status' => $remote['status'], 'checkout_url' => null, 'test_mode' => $this->mollie->testMode()]);
        if ($payment->wasRecentlyCreated) {
            $this->audit->record('checkout.payment_recovered', category: AuditCategory::Financial, resource: $checkout, webshopId: $checkout->webshop_id,
                metadata: ['provider_ref' => $remote['id']]);
        }

        return $payment->provider_ref === $paymentId ? $payment : null;
    }

    /** Applies Mollie's actual status to the checkout. */
    public function sync(VoucherCheckout $checkout): VoucherCheckout
    {
        $payment = $checkout->payment ?? throw new \LogicException('Checkout without payment.');
        $remote = $this->mollie->getPayment($payment->provider_ref);

        if ($remote['amount_minor'] !== $payment->amount_minor || $remote['currency'] !== $payment->currency || ($remote['metadata']['checkout_id'] ?? null) !== $checkout->public_id) {
            report(new \RuntimeException("Mollie payment {$payment->provider_ref} does not match checkout {$checkout->public_id}."));

            return $checkout;
        }

        $payment->forceFill(['status' => $remote['status'], 'verified_at' => now()])->save();

        return match ($remote['status']) {
            'paid' => $this->finalize($checkout),
            'failed', 'canceled', 'expired' => $this->fail($checkout, 'payment_'.$remote['status']),
            default => $checkout,
        };
    }

    /**
     * Scheduled: settles checkouts past their soft expiry. Returns the count changed.
     */
    public function expire(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $hard = (int) config('shopbonus.payments.checkout_hard_limit_minutes', 180);
        $changed = 0;

        VoucherCheckout::with('payment')->where('state', 'pending_payment')->where('expires_at', '<=', $now)->orderBy('id')
            ->each(function (VoucherCheckout $c) use ($now, $hard, &$changed) {
                try {
                    $after = $c->payment === null ? $this->fail($c, 'checkout_expired') : $this->sync($c);
                    if ($after->state === 'pending_payment' && $c->created_at->addMinutes($hard)->lessThanOrEqualTo($now)) {
                        $after = $this->fail($c, 'checkout_expired');
                    }
                    $changed += $after->state === 'pending_payment' ? 0 : 1;
                } catch (ApiException $e) {
                    report($e); // provider unreachable: try again next run
                }
            });

        return $changed;
    }

    /**
     * Scheduled: asks Mollie for refunds still in flight. A refunded one settles
     * a refund_pending checkout; a failed or canceled one returns the amount to
     * the refundable balance and the checkout to paid_unfulfilled. Returns the count changed.
     */
    public function syncRefunds(): int
    {
        $changed = 0;
        VoucherCheckoutPayment::whereNotNull('refund_ref')->whereIn('refund_status', ['queued', 'pending', 'processing'])->orderBy('id')
            ->each(function (VoucherCheckoutPayment $p) use (&$changed) {
                try {
                    $remote = $this->mollie->getRefund($p->provider_ref, (string) $p->refund_ref);
                } catch (ApiException $e) {
                    report($e); // provider unreachable: try again next run

                    return;
                }
                if ($remote['status'] === $p->refund_status) {
                    return;
                }
                DB::transaction(function () use ($p, $remote) {
                    $payment = VoucherCheckoutPayment::whereKey($p->id)->lockForUpdate()->firstOrFail();
                    $c = VoucherCheckout::whereKey($payment->checkout_id)->lockForUpdate()->firstOrFail();
                    $failed = in_array($remote['status'], ['failed', 'canceled'], true);
                    $payment->forceFill(['refund_status' => $remote['status']] + ($failed ? ['refunded_minor' => max(0, $payment->refunded_minor - (int) $payment->refund_amount_minor)] : []))->save();
                    if ($c->state === 'refund_pending' && ($remote['status'] === 'refunded' || $failed)) {
                        $c->forceFill(['state' => $failed ? 'paid_unfulfilled' : 'refunded'])->save();
                    }
                    $this->audit->record('checkout.refund_'.($failed ? 'failed' : 'status_changed'), category: AuditCategory::Financial, resource: $c, webshopId: $c->webshop_id,
                        metadata: ['refund_status' => $remote['status'], 'amount' => $payment->refund_amount_minor]);
                    if ($failed) {
                        report(new \RuntimeException("Refund {$payment->refund_ref} for checkout {$c->public_id} {$remote['status']}; refund again."));
                    }
                });
                $changed++;
            });

        return $changed;
    }

    // ------------------------------------------------------------------ internals

    private function open(User $user, Offer $offer, string $key, bool $useWallet, WalletUnit $unit): VoucherCheckout
    {
        // Lock order: offer → checkout row → code → wallet. Taking the offer lock before
        // inserting (whose foreign key takes a shared lock on it) avoids an S→X upgrade deadlock.
        $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
        if (($existing = $this->byKey($user, $key)) !== null) {
            $this->assertSamePurchase($existing, $offer, $unit);

            return $existing; // a concurrent identical request finished while we waited
        }

        $version = OfferVersion::whereKey($offer->current_version_id)->lockForUpdate()->firstOrFail();
        $offer->setRelation('currentVersion', $version);
        // Re-read terms under the offer and version locks; never charge a stale pre-lock quote.
        $quote = $this->quote($user, $offer, $useWallet, $unit);
        // Inventory owns the stock-race error and the final atomic stock check.
        $blockers = array_values(array_diff($quote['blockers'], ['sold_out']));
        if ($blockers !== []) {
            throw ApiException::conflict('This voucher cannot be bought right now.', 'checkout_blocked', ['blockers' => $blockers]);
        }

        $checkout = VoucherCheckout::create([
            'user_id' => $user->id, 'offer_id' => $offer->id, 'webshop_id' => $offer->webshop_id, 'idempotency_key' => $key, 'state' => 'pending_payment',
            'wallet_unit' => $unit, 'price_points' => $quote['price_points'], 'wallet_part_points' => $quote['wallet_part_points'],
            'price_minor' => $quote['price_minor'], 'wallet_part_minor' => $quote['wallet_part_minor'], 'payment_part_minor' => $quote['payment_part_minor'],
            'currency' => $quote['currency'], 'expires_at' => now()->addMinutes((int) config('shopbonus.payments.checkout_ttl_minutes', 30)),
        ]);

        // Reserve the code (fails with offer_out_of_stock if the last one just went) and the wallet part.
        $voucher = $this->inventory->reserve($offer, VoucherOrigin::WalletPurchase, 'checkout:'.$checkout->public_id, actorUserId: $user->id);
        $walletAmount = $unit === WalletUnit::Points ? $quote['wallet_part_points'] : $quote['wallet_part_minor'];
        $reservation = $walletAmount === 0 ? null : $this->wallets->reserve(
            $this->wallets->consumerWallet($user, $unit), $walletAmount, 'checkout:'.$checkout->public_id,
            (int) config('shopbonus.payments.checkout_hard_limit_minutes', 180) + 30, ['source_type' => 'voucher_checkout', 'source_ref' => $checkout->public_id]);
        $checkout->forceFill(['voucher_id' => $voucher->id, 'wallet_reservation_id' => $reservation?->id])->save();

        $this->audit->record('checkout.started', category: AuditCategory::Financial, resource: $checkout, webshopId: $checkout->webshop_id, actorUserId: $user->id,
            metadata: ['wallet_unit' => $unit->value, 'price_points' => $quote['price_points'], 'points' => $quote['wallet_part_points'], 'price' => $quote['price_minor'], 'wallet' => $quote['wallet_part_minor'], 'payment' => $quote['payment_part_minor']]);

        return $quote['payment_part_minor'] === 0 ? $this->complete($checkout) : $checkout;
    }

    /**
     * Mollie's Idempotency-Key makes a repeated request return the same payment.
     * When the outcome is unknown (timeout, provider 5xx) the checkout stays
     * pending so a retry can recover that payment; a clear refusal fails it.
     */
    private function createPayment(VoucherCheckout $checkout, Offer $offer): void
    {
        try {
            $payment = $this->mollie->createPayment($checkout->payment_part_minor, $checkout->currency, 'ShopBonus voucher: '.$offer->currentVersion?->name,
                config('shopbonus.identity.frontend_url').'/checkout/'.$checkout->public_id, route('api.v1.payments.mollie.webhook'), ['checkout_id' => $checkout->public_id],
                idempotencyKey: 'checkout:'.$checkout->public_id);
        } catch (Throwable $e) {
            $unknown = $e instanceof ApiException && ($e->errorCode === 'payment_provider_unavailable' || (int) ($e->details['provider_status'] ?? 0) >= 500);
            if (! $unknown) {
                $this->fail($checkout, 'payment_creation_failed');
            }
            throw $e instanceof ApiException ? $e : new ApiException('The payment could not be started.', 502, 'payment_provider_error');
        }

        VoucherCheckoutPayment::firstOrCreate(['checkout_id' => $checkout->id], ['provider' => 'mollie', 'provider_ref' => $payment['id'], 'amount_minor' => $checkout->payment_part_minor,
            'currency' => $checkout->currency, 'status' => $payment['status'], 'checkout_url' => $payment['checkout_url'], 'test_mode' => $this->mollie->testMode()]);
    }

    /** Exactly once: wallet capture + voucher issue, or paid_unfulfilled. */
    private function finalize(VoucherCheckout $checkout): VoucherCheckout
    {
        return DB::transaction(function () use ($checkout) {
            $c = VoucherCheckout::whereKey($checkout->id)->lockForUpdate()->firstOrFail();
            if (! in_array($c->state, ['pending_payment', 'expired', 'failed'], true)) {
                return $c; // already completed / flagged
            }

            $voucher = Voucher::findOrFail($c->voucher_id);
            $reservation = $c->wallet_reservation_id === null ? null : WalletReservation::find($c->wallet_reservation_id);
            if ($voucher->status !== VoucherStatus::Reserved || ($reservation !== null && $reservation->status !== 'active')) {
                // Paid after the reservations were released: never charge without a recoverable record.
                if ($reservation?->status === 'active') {
                    $this->wallets->release($reservation, 'checkout_unfulfilled');
                }
                $c->forceFill(['state' => 'paid_unfulfilled', 'failure_reason' => 'reservation_lost'])->save();
                $this->audit->record('checkout.paid_unfulfilled', category: AuditCategory::Financial, resource: $c, webshopId: $c->webshop_id);
                report(new \RuntimeException("Checkout {$c->public_id} was paid but could not be fulfilled; refund required."));

                return $c;
            }

            return $this->complete($c);
        });
    }

    private function complete(VoucherCheckout $c): VoucherCheckout
    {
        if ($c->wallet_reservation_id !== null) {
            $this->wallets->capture(WalletReservation::findOrFail($c->wallet_reservation_id));
        }
        $this->inventory->issue(Voucher::findOrFail($c->voucher_id), User::findOrFail($c->user_id));
        $c->forceFill(['state' => 'completed', 'completed_at' => now()])->save();
        $this->audit->record('checkout.completed', category: AuditCategory::Financial, resource: $c, webshopId: $c->webshop_id, actorUserId: $c->user_id);

        return $c;
    }

    private function fail(VoucherCheckout $checkout, string $reason): VoucherCheckout
    {
        return DB::transaction(function () use ($checkout, $reason) {
            $c = VoucherCheckout::whereKey($checkout->id)->lockForUpdate()->firstOrFail();
            if ($c->state !== 'pending_payment') {
                return $c;
            }

            $voucher = $c->voucher_id === null ? null : Voucher::find($c->voucher_id);
            if ($voucher?->status === VoucherStatus::Reserved) {
                $this->inventory->release($voucher, $reason);
            }
            $reservation = $c->wallet_reservation_id === null ? null : WalletReservation::find($c->wallet_reservation_id);
            if ($reservation?->status === 'active') {
                $this->wallets->release($reservation, $reason);
            }
            $c->forceFill(['state' => $reason === 'checkout_expired' || $reason === 'payment_expired' ? 'expired' : 'failed', 'failure_reason' => $reason])->save();
            $this->audit->record('checkout.failed', category: AuditCategory::Financial, resource: $c, webshopId: $c->webshop_id, metadata: ['reason' => $reason]);

            return $c;
        });
    }

    private function assertSamePurchase(VoucherCheckout $checkout, Offer $offer, WalletUnit $unit): void
    {
        if ($checkout->offer_id !== $offer->id || $checkout->wallet_unit !== $unit) {
            throw ApiException::conflict('This checkout key belongs to a different purchase.', 'checkout_request_conflict');
        }
    }

    /** @phpstan-impure */
    private function byKey(User $user, string $key): ?VoucherCheckout
    {
        return VoucherCheckout::where('user_id', $user->id)->where('idempotency_key', $key)->first();
    }
}
