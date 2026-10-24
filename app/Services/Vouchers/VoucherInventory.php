<?php

namespace App\Services\Vouchers;

use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\OfferResource;
use App\Integrations\Data\PaymentStatus;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherEvent;
use App\Models\WebshopOrder;
use App\Services\Funding\MerchantFunding;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single voucher/code inventory (Trello #7, #23). Every state change of
 * a voucher or code goes through here, under row locks and conditional
 * updates, so a code is never given to two consumers or redeemed twice.
 *
 *   reserve:  code available → reserved, voucher created (reserved)
 *   issue:    voucher reserved → issued to a verified consumer
 *   release:  voucher reserved → cancelled, code back to available
 *   redeem:   voucher issued → redeemed, once, with a verified paid order
 *   expire:   unclaimed reservations released; issued vouchers expire
 */
class VoucherInventory
{
    private const PICK_ATTEMPTS = 5;

    /** Origins whose reward the merchant funds (Trello #24 §4). */
    private const SPONSORED = [VoucherOrigin::MerchantReward, VoucherOrigin::Answer24Claim];

    public function __construct(private readonly MerchantFunding $funding) {}

    /**
     * Idempotent by `$idempotencyKey` (unique per webshop): repeating a call
     * with the same key returns the same voucher instead of a second one.
     */
    public function reserve(Offer $offer, VoucherOrigin $origin, string $idempotencyKey, ?WebshopOrder $sourceOrder = null, ?int $actorUserId = null): Voucher
    {
        if (($existing = $this->byKey($offer->webshop_id, $idempotencyKey)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->reserveLocked($offer, $origin, $idempotencyKey, $sourceOrder, $actorUserId));
        } catch (UniqueConstraintViolationException) {
            // A concurrent call with the same key won the race.
            return $this->byKey($offer->webshop_id, $idempotencyKey) ?? throw ApiException::conflict('Reservation conflict, retry.', 'reservation_conflict');
        }
    }

    public function issue(Voucher $voucher, User $consumer): Voucher
    {
        if ($consumer->email_verified_at === null || ! $consumer->isActive()) {
            throw new ApiException('Only verified, active accounts can receive vouchers.', 403, 'consumer_not_verified');
        }

        return DB::transaction(function () use ($voucher, $consumer) {
            $v = Voucher::with('offerVersion')->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($v->status !== VoucherStatus::Reserved) {
                throw ApiException::conflict('This voucher cannot be issued in its current state.', 'voucher_not_reserved', ['status' => $v->status->value]);
            }

            if ($v->claim_expires_at !== null && $v->claim_expires_at->isPast()) {
                throw new ApiException('This voucher can no longer be claimed.', 410, 'claim_expired');
            }

            $version = $v->offerVersion;
            $expiry = $version->redemption_validity_days === null ? null : now()->addDays($version->redemption_validity_days);
            if ($version->ends_at !== null && ($expiry === null || $version->ends_at->lessThan($expiry))) {
                $expiry = $version->ends_at;
            }

            $v->forceFill(['status' => VoucherStatus::Issued, 'consumer_user_id' => $consumer->id, 'issued_at' => now(), 'expires_at' => $expiry])->save();
            $this->moveCode($v, [VoucherCodeStatus::Reserved], VoucherCodeStatus::Issued, ['issued_at' => now()]);
            $this->event($v, 'issued', VoucherStatus::Reserved, VoucherStatus::Issued, $consumer->id);

            return $v;
        });
    }

    public function release(Voucher $voucher, string $reason, ?int $actorUserId = null): Voucher
    {
        return DB::transaction(function () use ($voucher, $reason, $actorUserId) {
            $v = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($v->status !== VoucherStatus::Reserved) {
                throw ApiException::conflict('Only reserved vouchers can be released.', 'voucher_not_reserved', ['status' => $v->status->value]);
            }

            $v->forceFill(['status' => VoucherStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->moveCode($v, [VoucherCodeStatus::Reserved], VoucherCodeStatus::Available, ['reserved_at' => null]);
            $this->event($v, 'released', VoucherStatus::Reserved, VoucherStatus::Cancelled, $actorUserId, ['reason' => $reason]);
            $this->funding->release($v, $reason);

            return $v;
        });
    }

    /**
     * Records a verified redemption: the voucher's code was used in a paid
     * order of the same webshop. Exactly once.
     */
    public function redeem(Voucher $voucher, WebshopOrder $order): Voucher
    {
        if ($order->webshop_id !== $voucher->webshop_id || $order->payment_status !== PaymentStatus::Paid) {
            throw ApiException::conflict('Redemption needs a verified paid order of the same webshop.', 'redemption_order_invalid');
        }

        return $this->markRedeemed($voucher, ['redeemed_order_id' => $order->id, 'redemption_source' => 'order', 'redemption_reference' => $order->reference],
            null, ['source' => 'order', 'order_id' => $order->public_id]);
    }

    /**
     * Redemption confirmed by the merchant (Trello #20 "explicitly authorized
     * merchant verification workflow"), when no provider evidence exists.
     */
    public function redeemByMerchant(Voucher $voucher, int $actorUserId, ?string $reference): Voucher
    {
        if ($voucher->status === VoucherStatus::Issued && $voucher->expires_at !== null && $voucher->expires_at->isPast()) {
            throw ApiException::conflict('This voucher has expired.', 'voucher_expired');
        }

        return $this->markRedeemed($voucher, ['redemption_source' => 'merchant', 'redeemed_by' => $actorUserId, 'redemption_reference' => $reference],
            $actorUserId, ['source' => 'merchant', 'reference' => $reference]);
    }

    /**
     * Exactly once: conditional issued → redeemed.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $summary
     */
    private function markRedeemed(Voucher $voucher, array $fields, ?int $actorUserId, array $summary): Voucher
    {
        return DB::transaction(function () use ($voucher, $fields, $actorUserId, $summary) {
            $updated = Voucher::whereKey($voucher->id)->where('status', VoucherStatus::Issued)
                ->update(['status' => VoucherStatus::Redeemed->value, 'redeemed_at' => now(), 'updated_at' => now(), ...$fields]);

            if ($updated !== 1) {
                $status = Voucher::whereKey($voucher->id)->value('status');
                throw ApiException::conflict('This voucher was already redeemed or is not issued.', 'voucher_not_redeemable', ['status' => $status]);
            }

            $v = Voucher::findOrFail($voucher->id);
            $this->moveCode($v, [VoucherCodeStatus::Issued], VoucherCodeStatus::Redeemed, ['redeemed_at' => now()]);
            $this->event($v, 'redeemed', VoucherStatus::Issued, VoucherStatus::Redeemed, $actorUserId, $summary);
            $this->funding->settle($v);

            return $v;
        });
    }

    /**
     * Scheduled housekeeping. Returns [released, expired] counts.
     *
     * @return array{0: int, 1: int}
     */
    public function expire(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $released = 0;
        $expired = 0;

        Voucher::where('status', VoucherStatus::Reserved)->whereNotNull('claim_expires_at')->where('claim_expires_at', '<', $now)
            ->orderBy('id')->each(function (Voucher $v) use (&$released) {
                try {
                    $this->release($v, 'claim_expired');
                    $released++;
                } catch (ApiException) {
                    // Changed concurrently (e.g. claimed): nothing to do.
                }
            });

        Voucher::where('status', VoucherStatus::Issued)->whereNotNull('expires_at')->where('expires_at', '<', $now)
            ->orderBy('id')->each(function (Voucher $v) use (&$expired) {
                DB::transaction(function () use ($v, &$expired) {
                    if (Voucher::whereKey($v->id)->where('status', VoucherStatus::Issued)->update(['status' => VoucherStatus::Expired->value, 'updated_at' => now()]) === 1) {
                        $this->moveCode($v, [VoucherCodeStatus::Issued], VoucherCodeStatus::Expired, []);
                        $this->event($v, 'expired', VoucherStatus::Issued, VoucherStatus::Expired, null);
                        $this->funding->release($v, 'voucher_expired');
                        $expired++;
                    }
                });
            });

        return [$released, $expired];
    }

    // ------------------------------------------------------------------ internals

    private function reserveLocked(Offer $offer, VoucherOrigin $origin, string $key, ?WebshopOrder $sourceOrder, ?int $actorUserId): Voucher
    {
        // Locking the offer row serialises reservations per offer, which keeps the
        // allocation-limit count exact under concurrency. The conditional code
        // update in pickUnique() remains the final single-winner guarantee.
        $offer = Offer::with('webshop')->whereKey($offer->id)->lockForUpdate()->firstOrFail();
        $version = OfferVersion::whereKey($offer->current_version_id)->lockForUpdate()->firstOrFail();

        $this->assertIssuable($offer, $version);

        if (! $version->isLocked()) {
            $version->forceFill(['locked_at' => now()])->save();  // terms are now contractual
        }

        $code = $version->code_mode === 'reusable' ? $this->pickReusable($offer) : $this->pickUnique($offer);

        $voucher = Voucher::create([
            'webshop_id' => $offer->webshop_id,
            'offer_id' => $offer->id,
            'offer_version_id' => $version->id,
            'voucher_code_id' => $code->id,
            'origin' => $origin,
            'status' => VoucherStatus::Reserved,
            'source_order_id' => $sourceOrder?->id,
            'idempotency_key' => $key,
            'terms_snapshot' => OfferResource::terms($version),
            'reserved_at' => now(),
            'claim_expires_at' => $version->claim_validity_days === null ? null : now()->addDays($version->claim_validity_days),
        ]);

        $this->event($voucher, 'reserved', null, VoucherStatus::Reserved, $actorUserId, ['origin' => $origin->value, 'version' => $version->version]);

        if (in_array($origin, self::SPONSORED, true)) {
            $this->funding->reserveFor($voucher, $version); // throws 409 when unfunded: the whole reservation rolls back
        }

        return $voucher;
    }

    private function assertIssuable(Offer $offer, OfferVersion $version): void
    {
        if ($offer->status !== OfferStatus::Active) {
            throw ApiException::conflict('This offer is not active.', 'offer_not_active', ['status' => $offer->status->value]);
        }

        if (! $offer->webshop->status->isCommerciallyActive()) {
            throw ApiException::conflict('This webshop is not active.', 'workspace_not_active');
        }

        if (($version->starts_at !== null && $version->starts_at->isFuture()) || ($version->ends_at !== null && $version->ends_at->isPast())) {
            throw ApiException::conflict('This offer is outside its validity period.', 'offer_outside_validity');
        }

        if ($version->allocation_limit !== null) {
            $allocated = Voucher::where('offer_id', $offer->id)->where('status', '!=', VoucherStatus::Cancelled)->count();
            if ($allocated >= $version->allocation_limit) {
                throw ApiException::conflict('This offer has reached its allocation limit.', 'offer_allocation_exhausted');
            }
        }
    }

    /**
     * Atomically takes one available code. SKIP LOCKED lets concurrent
     * reservations pass each other instead of queueing on the same row; the
     * conditional update guarantees a single winner even without it.
     */
    private function pickUnique(Offer $offer): VoucherCode
    {
        $skipLocked = in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);

        for ($attempt = 0; $attempt < self::PICK_ATTEMPTS; $attempt++) {
            $query = VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->orderBy('id')->limit(1);
            $code = $skipLocked ? $query->lock('for update skip locked')->first() : $query->lockForUpdate()->first();

            if ($code === null) {
                break;
            }

            $taken = VoucherCode::whereKey($code->id)->where('status', VoucherCodeStatus::Available)
                ->update(['status' => VoucherCodeStatus::Reserved->value, 'reserved_at' => now(), 'updated_at' => now()]);

            if ($taken === 1) {
                return $code->refresh();
            }
        }

        throw ApiException::conflict('No codes are available for this offer.', 'offer_out_of_stock');
    }

    private function pickReusable(Offer $offer): VoucherCode
    {
        $code = VoucherCode::where('offer_id', $offer->id)->where('reusable', true)->where('status', VoucherCodeStatus::Available)->lockForUpdate()->first()
            ?? throw ApiException::conflict('No codes are available for this offer.', 'offer_out_of_stock');

        $code->increment('uses_count');

        return $code;
    }

    /**
     * @param  list<VoucherCodeStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function moveCode(Voucher $voucher, array $from, VoucherCodeStatus $to, array $extra): void
    {
        $code = VoucherCode::whereKey($voucher->voucher_code_id)->lockForUpdate()->firstOrFail();

        if ($code->reusable) {
            if ($to === VoucherCodeStatus::Available) {
                $code->decrement('uses_count');
            }

            return; // a shared code stays available for other vouchers
        }

        if (! in_array($code->status, $from, true)) {
            throw ApiException::conflict('The voucher code is not in the expected state.', 'code_state_conflict', ['status' => $code->status->value]);
        }

        $code->forceFill(['status' => $to, ...$extra])->save();
        VoucherEvent::create(['webshop_id' => $code->webshop_id, 'voucher_code_id' => $code->id, 'voucher_id' => $voucher->id,
            'type' => 'code_'.$to->value, 'from_status' => $from[0]->value, 'to_status' => $to->value]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function event(Voucher $v, string $type, ?VoucherStatus $from, VoucherStatus $to, ?int $actor, array $summary = []): void
    {
        VoucherEvent::create([
            'webshop_id' => $v->webshop_id, 'voucher_id' => $v->id, 'voucher_code_id' => $v->voucher_code_id,
            'type' => $type, 'from_status' => $from?->value, 'to_status' => $to->value, 'actor_user_id' => $actor, 'summary' => $summary ?: null,
        ]);
    }

    /**
     * Reads the database: a second call can see a row a concurrent request just inserted.
     *
     * @phpstan-impure
     */
    private function byKey(int $webshopId, string $key): ?Voucher
    {
        return Voucher::where('webshop_id', $webshopId)->where('idempotency_key', $key)->first();
    }
}
