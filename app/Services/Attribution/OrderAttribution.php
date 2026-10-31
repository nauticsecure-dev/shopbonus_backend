<?php

namespace App\Services\Attribution;

use App\Integrations\Data\PaymentStatus;
use App\Models\AttributionLink;
use App\Models\NetworkJourney;
use App\Models\Voucher;
use App\Models\WebshopOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Labels paid orders as click-, voucher-, view-associated or unattributed
 * (Trello #24 §2). Priority: click > voucher > view > unattributed.
 * Cross-shop click IDs create a network journey with settlement=recorded only.
 * Attribution ≠ causality; holdout lift stays in BannerExperiments.
 */
class OrderAttribution
{
    public const TYPE_CLICK = 'click';

    public const TYPE_VOUCHER = 'voucher';

    public const TYPE_VIEW = 'view';

    public const TYPE_UNATTRIBUTED = 'unattributed';

    public function __construct(
        private readonly PromotionEvents $promotion,
    ) {}

    /**
     * Resolve and persist attribution after order upsert. Safe under retries:
     * existing attribution_link wins; refunds adjust amounts on the order once.
     *
     * @param  list<string>  $couponCodes
     */
    public function resolve(WebshopOrder $order, array $couponCodes = []): void
    {
        if (! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
            return;
        }

        $lookback = (int) config('shopbonus.banners.attribution_days', 30);
        $priorType = $order->attribution_type ?? self::TYPE_UNATTRIBUTED;

        $click = $this->resolveClick($order, $lookback);
        $voucher = $click === null ? $this->resolveVoucher($order, $couponCodes) : null;
        $view = ($click === null && $voucher === null) ? $this->resolveView($order) : null;

        [$type, $method, $clickId, $voucherId, $assignmentId, $referringShopId] = match (true) {
            $click !== null => [self::TYPE_CLICK, self::TYPE_CLICK, $click->click_id, null, $click->banner_assignment_id ?? null, (int) $click->webshop_id !== (int) $order->webshop_id ? (int) $click->webshop_id : null],
            $voucher !== null => [self::TYPE_VOUCHER, self::TYPE_VOUCHER, null, $voucher->id, null, null],
            $view !== null => [self::TYPE_VIEW, self::TYPE_VIEW, null, null, $view, null],
            default => [self::TYPE_UNATTRIBUTED, null, null, null, null, null],
        };

        $isRepeat = $this->isRepeat($order);

        $order->forceFill([
            'attribution_type' => $type,
            'referring_webshop_id' => $referringShopId,
            'is_repeat_order' => $isRepeat,
        ])->save();

        if ($method !== null) {
            AttributionLink::query()->firstOrCreate(
                ['order_id' => $order->id],
                [
                    'public_id' => (string) Str::ulid(),
                    'webshop_id' => $order->webshop_id,
                    'method' => $method,
                    'click_id' => $clickId,
                    'voucher_id' => $voucherId,
                    'banner_assignment_id' => $assignmentId,
                    'referring_webshop_id' => $referringShopId,
                    'lookback_days' => $lookback,
                    'linked_at' => now(),
                ],
            );
        }

        if ($referringShopId !== null && $clickId !== null) {
            $this->recordNetworkJourney($order, $referringShopId, $clickId, $click->category ?? null);
        }

        if ($order->payment_status === PaymentStatus::Paid && $priorType === self::TYPE_UNATTRIBUTED) {
            $this->promotion->record(
                $order->webshop_id,
                'order_paid',
                'adapter',
                'webshop_order',
                $order->public_id,
                ['attribution_type' => $type, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency],
                'order_paid:'.$order->public_id,
            );
        }

        if ($isRepeat) {
            $this->promotion->record(
                $order->webshop_id,
                'repeat_order',
                'adapter',
                'webshop_order',
                $order->public_id,
                ['attribution_type' => $type],
                'repeat_order:'.$order->public_id,
            );
        }

        if (in_array($order->payment_status, [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)
            && ($order->refunded_minor ?? 0) > 0) {
            $this->promotion->record(
                $order->webshop_id,
                'order_refunded',
                'adapter',
                'webshop_order',
                $order->public_id,
                ['refunded_minor' => $order->refunded_minor, 'amount_minor' => $order->amount_minor],
                'order_refunded:'.$order->public_id.':'.$order->refunded_minor,
            );
        }
    }

    /**
     * @return object{click_id: string, webshop_id: int, banner_assignment_id: ?int, category: ?string}|null
     */
    private function resolveClick(WebshopOrder $order, int $lookback): ?object
    {
        $clickId = $order->attribution_click_id;
        if ($clickId === null || $clickId === '') {
            return null;
        }

        $paidAt = $order->paid_at ?? now();
        $row = DB::table('banner_clicks')
            ->where('click_id', $clickId)
            ->where('created_at', '<=', $paidAt)
            ->where('expires_at', '>=', $paidAt)
            ->first(['click_id', 'webshop_id', 'banner_assignment_id', 'campaign_id']);

        if ($row === null || $lookback < 1) {
            return null;
        }

        $category = DB::table('banner_events')
            ->where('campaign_id', $row->campaign_id)
            ->where('type', 'cta_click')
            ->where('consent', true)
            ->orderByDesc('id')
            ->value('category');

        return (object) [
            'click_id' => $row->click_id,
            'webshop_id' => (int) $row->webshop_id,
            'banner_assignment_id' => $row->banner_assignment_id !== null ? (int) $row->banner_assignment_id : null,
            'category' => $category,
        ];
    }

    /** @param list<string> $couponCodes */
    private function resolveVoucher(WebshopOrder $order, array $couponCodes): ?Voucher
    {
        $codes = array_values(array_filter(array_map(fn ($c) => mb_strtoupper(trim((string) $c)), $couponCodes)));
        if ($codes === []) {
            // Already-bound redeemed voucher on this order.
            return Voucher::query()
                ->where('webshop_id', $order->webshop_id)
                ->where('redeemed_order_id', $order->id)
                ->orderBy('id')
                ->first();
        }

        return Voucher::query()
            ->where('webshop_id', $order->webshop_id)
            ->whereNotNull('issued_at')
            ->whereHas('code', fn ($q) => $q->whereIn('code', $codes))
            ->orderBy('id')
            ->first();
    }

    private function resolveView(WebshopOrder $order): ?int
    {
        $assignmentId = $order->getAttribute('banner_assignment_id');
        if ($assignmentId === null) {
            return null;
        }

        $seen = DB::table('banner_events')
            ->where('banner_assignment_id', $assignmentId)
            ->where('type', 'viewable_impression')
            ->exists();

        return $seen ? (int) $assignmentId : null;
    }

    private function isRepeat(WebshopOrder $order): bool
    {
        if ($order->customer_key === null || $order->paid_at === null) {
            return false;
        }

        return WebshopOrder::query()
            ->where('webshop_id', $order->webshop_id)
            ->where('customer_key', $order->customer_key)
            ->whereNotNull('paid_at')
            ->where('id', '!=', $order->id)
            ->where('paid_at', '<', $order->paid_at)
            ->exists();
    }

    private function recordNetworkJourney(WebshopOrder $order, int $referringShopId, string $clickId, ?string $category): void
    {
        if ($referringShopId === (int) $order->webshop_id) {
            return;
        }

        NetworkJourney::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'public_id' => (string) Str::ulid(),
                'referring_webshop_id' => $referringShopId,
                'receiving_webshop_id' => $order->webshop_id,
                'click_id' => $clickId,
                'category' => $category,
                'order_amount_minor' => max(0, (int) $order->amount_minor - (int) $order->refunded_minor),
                'currency' => $order->currency ?? 'EUR',
                'settlement_status' => 'recorded',
                'attributed_at' => $order->paid_at ?? now(),
            ],
        );
    }
}
