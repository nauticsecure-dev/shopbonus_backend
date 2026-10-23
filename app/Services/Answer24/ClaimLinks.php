<?php

namespace App\Services\Answer24;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Models\ContactOpportunity;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherClaimReminder;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Vouchers\VoucherInventory;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Answer24 free voucher (Trello #23): signed handoff → reserved code →
 * short single-use claim link → verified consumer claims once; unclaimed
 * entitlement expires with the claim window (90 days by default) and the
 * code returns to the inventory. Reminders are Answer24's to send; Shopbonus
 * only decides whether a day-30/60/80 reminder is still appropriate, once.
 */
class ClaimLinks
{
    /** States in which Answer24 had permitted engagement with the customer. */
    public const HANDOFF_STATES = ['accepted', 'attempted', 'completed'];

    private const TOKEN_LENGTH = 24; // ~143 bits from a 62-character alphabet

    public function __construct(
        private readonly VoucherInventory $inventory,
        private readonly OrderEligibility $eligibility,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Idempotent per opportunity: a repeated handoff returns the same claim
     * (and link). Returns [claim, token, created].
     *
     * @return array{0: VoucherClaim, 1: string, 2: bool}
     */
    public function handoff(ContactOpportunity $opportunity): array
    {
        if (($existing = VoucherClaim::where('opportunity_id', $opportunity->id)->first()) !== null) {
            return [$existing, $existing->token, false];
        }

        if (! in_array($opportunity->status, self::HANDOFF_STATES, true)) {
            throw ApiException::conflict('A voucher can only be handed off after the customer accepted the contact.', 'handoff_not_permitted', ['status' => $opportunity->status]);
        }

        $offer = $opportunity->offer_id === null ? null : Offer::find($opportunity->offer_id);
        if ($offer === null) {
            throw ApiException::conflict('This contact request has no offer to hand off.', 'handoff_no_offer');
        }

        $sourceOrder = WebshopOrder::whereIn('id', $opportunity->links()->where('status', 'active')->pluck('order_id'))->orderBy('placed_at')->orderBy('id')->first()
            ?? throw ApiException::conflict('This contact request has no eligible order left.', 'handoff_no_order');

        $voucher = $this->inventory->reserve($offer, VoucherOrigin::Answer24Claim, 'answer24-handoff:'.$opportunity->public_id, $sourceOrder);
        $token = Str::random(self::TOKEN_LENGTH);
        $expiresAt = $voucher->claim_expires_at ?? now()->addDays((int) config('shopbonus.offers.default_claim_validity_days', 90));

        try {
            $claim = DB::transaction(function () use ($opportunity, $voucher, $token, $expiresAt) {
                $claim = VoucherClaim::create([
                    'webshop_id' => $opportunity->webshop_id, 'voucher_id' => $voucher->id, 'opportunity_id' => $opportunity->id,
                    'token_hash' => VoucherClaim::hashToken($token), 'token' => $token, 'status' => 'open', 'expires_at' => $expiresAt,
                ]);
                $this->audit->record('answer24.handoff', category: AuditCategory::Voucher, resource: $claim, webshopId: $opportunity->webshop_id,
                    metadata: ['opportunity' => $opportunity->public_id, 'voucher' => $voucher->public_id]);

                return $claim;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent handoff for the same opportunity won the race.
            $claim = VoucherClaim::where('opportunity_id', $opportunity->id)->firstOrFail();

            return [$claim, $claim->token, false];
        }

        return [$claim, $token, true];
    }

    public static function url(string $token): string
    {
        return config('shopbonus.identity.frontend_url').'/claim/'.$token;
    }

    /**
     * Finds an open, unexpired claim by its token or explains why not.
     */
    public function findOpen(string $token): VoucherClaim
    {
        $claim = VoucherClaim::with(['voucher', 'webshop'])->where('token_hash', VoucherClaim::hashToken($token))->first()
            ?? throw new ApiException('This claim link is not valid.', 404, 'claim_not_found');

        $this->assertOpen($claim);

        return $claim;
    }

    /**
     * The consumer claims the reserved voucher. Only a verified account whose
     * email is the order's email may claim it, and only once.
     */
    public function accept(string $token, User $consumer): Voucher
    {
        $claim = VoucherClaim::with('voucher')->where('token_hash', VoucherClaim::hashToken($token))->first()
            ?? throw new ApiException('This claim link is not valid.', 404, 'claim_not_found');

        if ($claim->status === 'claimed' && $claim->claimed_by === $consumer->id) {
            return $claim->voucher; // repeated accept by the same person: same voucher
        }

        $this->assertOpen($claim);

        $order = $claim->voucher->source_order_id === null ? null : WebshopOrder::find($claim->voucher->source_order_id);
        if (config('shopbonus.answer24.claim_requires_email_match', true)
            && ($order === null || $order->customer_email_hash === null || ! hash_equals($order->customer_email_hash, (string) WebshopOrder::emailHash($consumer->email)))) {
            // Audited before throwing (never inside a transaction that rolls back).
            $this->audit->recordSafely('answer24.claim_email_mismatch', AuditOutcome::Denied, AuditCategory::Voucher, $claim, webshopId: $claim->webshop_id, httpStatus: 403);

            throw new ApiException('Sign in with the email address used for the order to claim this voucher.', 403, 'claim_email_mismatch');
        }

        return DB::transaction(function () use ($claim, $consumer) {
            $locked = VoucherClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);

            $voucher = $this->inventory->issue($claim->voucher, $consumer);
            $locked->forceFill(['status' => 'claimed', 'claimed_at' => now(), 'claimed_by' => $consumer->id])->save();

            $this->claimEvent($locked, 'answer24.claim_completed');
            $this->audit->record('answer24.claim_completed', category: AuditCategory::Voucher, resource: $locked, webshopId: $locked->webshop_id,
                metadata: ['voucher' => $voucher->public_id]);

            return $voucher;
        });
    }

    /**
     * Reminder decision for Answer24 (Trello #10 §6): day 30, 60 or 80 of the
     * claim window, only within that day's slot, never twice, never after the
     * voucher was claimed/expired/cancelled, never for a suppressed customer.
     *
     * @return array{send: bool, reason: string|null}
     */
    public function reminder(VoucherClaim $claim, int $day, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $days = array_values(config('shopbonus.answer24.reminder_days', [30, 60, 80]));
        $next = $days[array_search($day, $days, true) + 1] ?? null;

        return DB::transaction(function () use ($claim, $day, $next, $now) {
            $claim = VoucherClaim::with('voucher')->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $order = $claim->voucher->source_order_id === null ? null : WebshopOrder::find($claim->voucher->source_order_id);

            $reason = match (true) {
                $claim->status === 'claimed' => 'claimed',
                $claim->status === 'cancelled' => 'cancelled',
                $claim->status === 'expired' || $claim->expires_at->lessThanOrEqualTo($now) || $claim->voucher->status !== VoucherStatus::Reserved => 'expired',
                $now->lessThan($claim->created_at->addDays($day)) => 'not_due',
                $next !== null && $now->greaterThanOrEqualTo($claim->created_at->addDays($next)) => 'superseded',
                $order !== null && $this->eligibility->isSuppressed($claim->webshop_id, $order->customer_email, PhoneNumber::normalize($order->customer_phone)) => 'suppressed',
                default => null,
            };

            if ($reason === null) {
                try {
                    DB::transaction(fn () => VoucherClaimReminder::create(['claim_id' => $claim->id, 'day' => $day, 'granted_at' => now()]));
                } catch (UniqueConstraintViolationException) {
                    $reason = 'already_sent';
                }
            }

            return ['send' => $reason === null, 'reason' => $reason];
        });
    }

    /**
     * Scheduled: open claims past their deadline (or whose reservation was
     * released) become expired, and Answer24 is told. Returns the count.
     */
    public function expire(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $expired = 0;

        VoucherClaim::where('status', 'open')
            ->where(fn ($q) => $q->where('expires_at', '<=', $now)->orWhereHas('voucher', fn ($v) => $v->where('status', '!=', VoucherStatus::Reserved)))
            ->orderBy('id')->each(function (VoucherClaim $claim) use (&$expired) {
                DB::transaction(function () use ($claim, &$expired) {
                    if (VoucherClaim::whereKey($claim->id)->where('status', 'open')->update(['status' => 'expired', 'updated_at' => now()]) === 1) {
                        $this->claimEvent($claim->refresh(), 'answer24.claim_expired');
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    private function assertOpen(VoucherClaim $claim): void
    {
        match (true) {
            $claim->status === 'claimed' => throw new ApiException('This voucher was already claimed.', 410, 'claim_already_used'),
            $claim->status === 'cancelled' => throw new ApiException('This claim link was cancelled.', 410, 'claim_cancelled'),
            $claim->status === 'expired', $claim->expires_at->isPast(), $claim->voucher->status !== VoucherStatus::Reserved => throw new ApiException('This claim link has expired.', 410, 'claim_expired'),
            default => null,
        };
    }

    private function claimEvent(VoucherClaim $claim, string $type): void
    {
        if ($claim->opportunity_id === null) {
            return;
        }

        $opportunity = ContactOpportunity::find($claim->opportunity_id);
        $this->outbox->record($type, $type.':'.$claim->public_id, [
            'contract' => 'shopbonus-answer24-v1',
            'claim_id' => $claim->public_id,
            'opportunity_id' => $opportunity?->public_id,
            'remote_reference' => $opportunity?->remote_reference,
            'status' => $claim->status,
            'at' => now()->toIso8601String(),
        ], $claim->webshop_id, 'voucher_claim', $claim->id);
    }
}
