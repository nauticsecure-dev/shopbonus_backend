<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\CentralNotification;
use App\Models\MerchantNotification;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\VoucherCodeBatch;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Funding\MerchantFunding;
use App\Services\Notifications\NotificationInbox;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Vouchers\PlatformCouponVerification;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Generates merchant notifications from the authoritative state (Trello #8
 * §6). Each condition has a deduplication key, so a repeated run never
 * notifies twice for the same situation; thresholds are configurable.
 */
class MerchantNotifications
{
    public function __construct(private readonly MerchantFunding $funding) {}

    /** Returns the number of new notifications. */
    public function generate(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        Webshop::where('status', WebshopStatus::Approved)->orderBy('id')->each(function (Webshop $w) use ($now, &$created) {
            $created += $this->forWebshop($w, $now);
        });

        return $created;
    }

    public function forWebshop(Webshop $w, CarbonImmutable $now): int
    {
        $n = 0;
        $threshold = (int) config('shopbonus.notifications.low_inventory_threshold', 20);
        $tz = (string) config('shopbonus.business_timezone');
        $today = $now->setTimezone($tz)->format('Y-m-d');

        $this->resolveRecoveredInventoryAlerts($w, $now);

        $verificationFailures = VoucherCode::where('webshop_id', $w->id)
            ->whereIn('platform_validation', [PlatformCouponVerification::MISMATCH, PlatformCouponVerification::SYNC_ERROR])
            ->whereIn('status', [VoucherCodeStatus::Available, VoucherCodeStatus::Reserved, VoucherCodeStatus::Issued]);
        MerchantNotification::where('webshop_id', $w->id)->where('type', 'offer_verification')->each(function ($notice) use ($verificationFailures, $now) {
            $offerId = Offer::where('webshop_id', $notice->webshop_id)->where('public_id', $notice->subject_ref)->value('id');
            if ($offerId === null || ! (clone $verificationFailures)->where('offer_id', $offerId)->exists()) {
                $notice->forceFill(['resolved_at' => $now])->save();
            }
        });
        foreach ((clone $verificationFailures)->selectRaw('offer_id, COUNT(*) as n, MAX(updated_at) as last_failure')->groupBy('offer_id')->get() as $failure) {
            $offerRef = Offer::whereKey($failure->offer_id)->value('public_id');
            $n += $this->notify($w, 'offer_verification', 'warning', 'Offer code verification failed',
                $failure->getAttribute('n').' code(s) have a provider mismatch or verification error. Review the offer inventory.',
                'offer', $offerRef, 'offer_verification:'.$failure->offer_id.':'.$failure->getAttribute('last_failure'));
        }

        foreach (Offer::with('currentVersion')->where('webshop_id', $w->id)->where('status', OfferStatus::Active)->get() as $offer) {
            if ($offer->currentVersion?->ends_at?->lessThan($now)) {
                continue;
            }
            $name = (string) $offer->currentVersion?->name;
            $left = VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->count();
            if ($left < $threshold) {
                // One per offer per inventory level band, so restocking and running low again notifies again.
                $n += $this->notify($w, 'low_inventory', $left === 0 ? 'critical' : 'warning', $left === 0 ? 'Out of codes' : 'Code inventory running low',
                    "{$name}: {$left} codes remaining.", 'offer', $offer->public_id, "low_inventory:{$offer->id}:".($left === 0 ? 'empty' : 'low').':'.$this->restockEpoch($offer->id));
            }
            $ends = $offer->currentVersion?->ends_at;
            if ($ends !== null && $ends->isFuture() && $ends->lessThan($now->addDays(app(NotificationPolicy::class)->offerExpiryDays()))) {
                $n += $this->notify($w, 'offer_expiring', 'info', 'Offer ends soon', "{$name} ends on ".$ends->setTimezone($tz)->format('d-m-Y').'.', 'offer', $offer->public_id, "offer_expiring:{$offer->id}:".$ends->getTimestamp());
            }
            if ($offer->acquisition === OfferAcquisition::MerchantReward && $offer->currentVersion !== null && ! $this->funding->canFund($w, $offer->currentVersion)) {
                $n += $this->notify($w, 'low_funding', 'critical', 'Rewards paused: funding needed', "Top up your reward funding: {$name} needs ".number_format($this->funding->costOf($offer->currentVersion)['total_minor'] / 100, 2, ',', '.').' per voucher.',
                    'funding', $w->public_id, "low_funding:{$offer->id}:{$today}");
            }
        }

        VoucherCodeBatch::where('webshop_id', $w->id)->where('created_at', '>=', $now->subDays(7))->each(function ($batch) use ($w, &$n) {
            $rejected = (int) ($batch->totals['invalid'] ?? 0) + (int) ($batch->totals['duplicate_in_upload'] ?? 0) + (int) ($batch->totals['duplicate_existing'] ?? 0) + (int) ($batch->totals['duplicate_at_commit'] ?? 0);
            if ($rejected > 0) {
                $offerRef = Offer::whereKey($batch->offer_id)->value('public_id');
                $n += $this->notify($w, 'invalid_batch', 'warning', 'Code import needs attention', "{$rejected} code(s) rejected in the import. Review the batch in offer inventory.", 'offer', $offerRef, 'invalid_batch:'.$batch->public_id);
            }
        });

        foreach (WebshopConnection::where('webshop_id', $w->id)->where('status', ConnectionStatus::Error)->get() as $c) {
            $n += $this->notify($w, 'connector_error', 'critical', 'Webshop connection error', 'Orders from '.$c->platform->value.' are not arriving. Check the connection.', 'connection', $c->public_id, "connector_error:{$c->id}:{$today}");
        }

        $claims = VoucherClaim::where('webshop_id', $w->id)->where('claimed_at', '>=', $now->setTimezone($tz)->startOfDay()->utc())->count();
        if ($claims > 0) {
            // Daily digest; the body is refreshed as more claims come in.
            $n += $this->notify($w, 'new_claims', 'info', 'New voucher claims', "{$claims} voucher(s) claimed today.", null, null, "new_claims:{$today}", refresh: true);
        }

        return $n;
    }

    /** Reconcile on dashboard reads too, so restocking is visible before the scheduled generator. */
    public function resolveRecoveredInventoryAlerts(Webshop $w, CarbonImmutable $now): void
    {
        $threshold = (int) config('shopbonus.notifications.low_inventory_threshold', 20);
        // Keep historical records for deduplication, but remove recovered conditions
        // from both overview and central inbox counts.
        MerchantNotification::where('webshop_id', $w->id)->where('type', 'low_inventory')->each(function ($notice) use ($threshold, $now) {
            $offer = Offer::where('webshop_id', $notice->webshop_id)->where('public_id', $notice->subject_ref)->first();
            $left = $offer === null ? 0 : VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->count();
            if ($offer === null || $offer->status !== OfferStatus::Active || $offer->currentVersion?->ends_at?->lessThan($now) || $left >= $threshold) {
                $notice->forceFill(['resolved_at' => $now])->save();
                $inbox = app(NotificationInbox::class);
                User::whereIn('id', CentralNotification::where('legacy_notification_id', $notice->id)
                    ->where('notifiable_type', (new User)->getMorphClass())->select('notifiable_id'))
                    ->each(fn ($user) => $inbox->signal($user, 'NotificationCountUpdated'));
            }
        });

    }

    private function notify(Webshop $w, string $type, string $severity, string $title, string $body, ?string $subjectType, ?string $subjectRef, string $key, bool $refresh = false): int
    {
        $existing = MerchantNotification::withoutGlobalScope('unresolved')->where('webshop_id', $w->id)->where('dedupe_key', $key)->first();
        if ($existing !== null) {
            if ($refresh && $existing->body !== $body) {
                $existing->forceFill(['body' => $body, 'read_at' => null, 'read_by' => null])->save();
            }

            return 0;
        }

        try {
            MerchantNotification::create(['webshop_id' => $w->id, 'type' => $type, 'severity' => $severity, 'title' => $title, 'body' => mb_substr($body, 0, 500),
                'subject_type' => $subjectType, 'subject_ref' => $subjectRef, 'dedupe_key' => $key]);

            return 1;
        } catch (UniqueConstraintViolationException) {
            return 0;
        }
    }

    /** Changes when codes are added to the offer, so a refilled offer can warn again later. */
    private function restockEpoch(int $offerId): int
    {
        return (int) VoucherCode::where('offer_id', $offerId)->count();
    }
}
