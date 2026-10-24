<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\MerchantNotification;
use App\Models\Offer;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Funding\MerchantFunding;
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

        foreach (Offer::with('currentVersion')->where('webshop_id', $w->id)->where('status', OfferStatus::Active)->get() as $offer) {
            $name = (string) $offer->currentVersion?->name;
            $left = VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->count();
            if ($left < $threshold) {
                // One per offer per inventory level band, so restocking and running low again notifies again.
                $n += $this->notify($w, 'low_inventory', $left === 0 ? 'critical' : 'warning', $left === 0 ? 'Out of codes' : 'Code inventory running low',
                    "{$name}: {$left} codes remaining.", 'offer', $offer->public_id, "low_inventory:{$offer->id}:".($left === 0 ? 'empty' : 'low').':'.$this->restockEpoch($offer->id));
            }
            $ends = $offer->currentVersion?->ends_at;
            if ($ends !== null && $ends->isFuture() && $ends->lessThan($now->addDays((int) config('shopbonus.notifications.expiry_warning_days', 7)))) {
                $n += $this->notify($w, 'offer_expiring', 'info', 'Offer ends soon', "{$name} ends on ".$ends->setTimezone($tz)->format('d-m-Y').'.', 'offer', $offer->public_id, "offer_expiring:{$offer->id}:".$ends->getTimestamp());
            }
            if ($offer->acquisition === OfferAcquisition::MerchantReward && $offer->currentVersion !== null && ! $this->funding->canFund($w, $offer->currentVersion)) {
                $n += $this->notify($w, 'low_funding', 'critical', 'Rewards paused: funding needed', "Top up your reward funding: {$name} needs ".number_format($this->funding->costOf($offer->currentVersion)['total_minor'] / 100, 2, ',', '.').' per voucher.',
                    'funding', $w->public_id, "low_funding:{$offer->id}:{$today}");
            }
        }

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

    private function notify(Webshop $w, string $type, string $severity, string $title, string $body, ?string $subjectType, ?string $subjectRef, string $key, bool $refresh = false): int
    {
        $existing = MerchantNotification::where('webshop_id', $w->id)->where('dedupe_key', $key)->first();
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
