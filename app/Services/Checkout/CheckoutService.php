<?php

namespace App\Services\Checkout;

use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Enums\WalletUnit;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCheckoutPayment;
use App\Models\VoucherCode;
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
     * @return array{price_minor: int, currency: string, wallet_available: int, wallet_part_minor: int, payment_part_minor: int, blockers: list<string>}
     */
    public function quote(User $user, Offer $offer, bool $useWallet = true): array
    {
        $version = $offer->currentVersion;
        $price = (int) ($version->price_minor ?? 0);
        $available = $this->wallets->consumerWallet($user, WalletUnit::EurCredit)->available;
        $walletPart = $useWallet ? min($available, $price) : 0;
        $paymentPart = $price - $walletPart;

        $blockers = array_keys(array_filter([
            'not_for_sale' => $offer->acquisition !== OfferAcquisition::WalletPurchase || $price <= 0,
            'offer_not_active' => $offer->status !== OfferStatus::Active,
            'webshop_not_active' => $offer->webshop()->value('status') !== WebshopStatus::Approved->value,
            'outside_validity' => ($version?->starts_at?->isFuture() ?? false) || ($version?->ends_at?->isPast() ?? false),
            'sold_out' => ! VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->exists(),
            'payments_unavailable' => $paymentPart > 0 && ! $this->mollie->available(),
            'email_not_verified' => $user->email_verified_at === null,
        ]));

        return ['price_minor' => $price, 'currency' => (string) ($version->currency ?? 'EUR'), 'wallet_available' => $available,
            'wallet_part_minor' => $walletPart, 'payment_part_minor' => $paymentPart, 'blockers' => $blockers];
    }

    public function start(User $user, Offer $offer, string $key, bool $useWallet = true): VoucherCheckout
    {
        if (($existing = $this->byKey($user, $key)) !== null) {
            return $existing;
        }

        $quote = $this->quote($user, $offer, $useWallet);
        if ($quote['blockers'] !== []) {
            throw ApiException::conflict('This voucher cannot be bought right now.', 'checkout_blocked', ['blockers' => $quote['blockers']]);
        }

        try {
            $checkout = DB::transaction(fn () => $this->open($user, $offer, $key, $quote));
        } catch (UniqueConstraintViolationException) {
            return $this->byKey($user, $key) ?? throw ApiException::conflict('Checkout conflict, retry.', 'checkout_conflict');
        }

        if ($checkout->state === 'pending_payment') {
            $this->createPayment($checkout, $offer);
        }

        return $checkout->refresh();
    }

    /** Mollie webhook: never trusts the request; asks Mollie. */
    public function handleWebhook(string $paymentId): void
    {
        $payment = VoucherCheckoutPayment::where('provider_ref', $paymentId)->first();
        if ($payment === null) {
            return; // unknown or forged ID: nothing to do, and nothing revealed
        }

        $this->sync(VoucherCheckout::findOrFail($payment->checkout_id));
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

    // ------------------------------------------------------------------ internals

    /**
     * @param  array{price_minor: int, currency: string, wallet_part_minor: int, payment_part_minor: int}  $quote
     */
    private function open(User $user, Offer $offer, string $key, array $quote): VoucherCheckout
    {
        $checkout = VoucherCheckout::create([
            'user_id' => $user->id, 'offer_id' => $offer->id, 'webshop_id' => $offer->webshop_id, 'idempotency_key' => $key, 'state' => 'pending_payment',
            'price_minor' => $quote['price_minor'], 'wallet_part_minor' => $quote['wallet_part_minor'], 'payment_part_minor' => $quote['payment_part_minor'],
            'currency' => $quote['currency'], 'expires_at' => now()->addMinutes((int) config('shopbonus.payments.checkout_ttl_minutes', 30)),
        ]);

        // Reserve the code (fails with offer_out_of_stock if the last one just went) and the wallet part.
        $voucher = $this->inventory->reserve($offer, VoucherOrigin::WalletPurchase, 'checkout:'.$checkout->public_id, actorUserId: $user->id);
        $reservation = $quote['wallet_part_minor'] === 0 ? null : $this->wallets->reserve(
            $this->wallets->consumerWallet($user, WalletUnit::EurCredit), $quote['wallet_part_minor'], 'checkout:'.$checkout->public_id,
            (int) config('shopbonus.payments.checkout_hard_limit_minutes', 180) + 30, ['source_type' => 'voucher_checkout', 'source_ref' => $checkout->public_id]);
        $checkout->forceFill(['voucher_id' => $voucher->id, 'wallet_reservation_id' => $reservation?->id])->save();

        $this->audit->record('checkout.started', category: AuditCategory::Financial, resource: $checkout, webshopId: $checkout->webshop_id, actorUserId: $user->id,
            metadata: ['price' => $quote['price_minor'], 'wallet' => $quote['wallet_part_minor'], 'payment' => $quote['payment_part_minor']]);

        return $quote['payment_part_minor'] === 0 ? $this->complete($checkout) : $checkout;
    }

    private function createPayment(VoucherCheckout $checkout, Offer $offer): void
    {
        try {
            $payment = $this->mollie->createPayment($checkout->payment_part_minor, $checkout->currency, 'ShopBonus voucher: '.$offer->currentVersion?->name,
                config('shopbonus.identity.frontend_url').'/checkout/'.$checkout->public_id, route('api.v1.payments.mollie.webhook'), ['checkout_id' => $checkout->public_id]);
        } catch (Throwable $e) {
            $this->fail($checkout, 'payment_creation_failed');
            throw $e instanceof ApiException ? $e : new ApiException('The payment could not be started.', 502, 'payment_provider_error');
        }

        VoucherCheckoutPayment::create(['checkout_id' => $checkout->id, 'provider' => 'mollie', 'provider_ref' => $payment['id'], 'amount_minor' => $checkout->payment_part_minor,
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

    /** @phpstan-impure */
    private function byKey(User $user, string $key): ?VoucherCheckout
    {
        return VoucherCheckout::where('user_id', $user->id)->where('idempotency_key', $key)->first();
    }
}
