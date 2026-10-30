<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Models\AuditEvent;
use App\Models\BannerCampaign;
use App\Models\ContactOpportunity;
use App\Models\Offer;
use App\Models\SponsorshipObligation;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\VoucherDispute;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Attribution\NetworkAnalytics;
use App\Services\Attribution\OrderAttribution;
use App\Services\Funding\MerchantFunding;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Merchant dashboard figures (Trello #8, #24 §6), always read from the
 * authoritative tables and always scoped to one webshop. Each KPI has one
 * definition, returned alongside it, used everywhere.
 */
class MerchantAnalytics
{
    public const DEFINITIONS = [
        'active_offers' => 'Offers with status active (draft, paused and ended excluded).',
        'available_codes' => 'Codes with status available, i.e. free for allocation (reserved, issued, redeemed, invalid and expired excluded).',
        'issued_vouchers' => 'Vouchers ever issued to a consumer (issued_at set), including those since redeemed or expired.',
        'claimed_vouchers' => 'Answer24 claim links accepted by a verified consumer.',
        'redeemed_vouchers' => 'Vouchers with a verified redemption (paid order containing the code, or merchant verification).',
        'scheduled_offers' => 'Offers with status scheduled (not yet opened).',
        'paused_offers' => 'Offers with status paused.',
        'open_disputes' => 'Voucher reports for this webshop that are not resolved or rejected.',
    ];

    public function __construct(
        private readonly MerchantFunding $funding,
        private readonly NetworkAnalytics $network,
    ) {}

    /** @return array<string, int> */
    public function kpis(int $webshopId): array
    {
        return [
            'active_offers' => Offer::where('webshop_id', $webshopId)->where('status', OfferStatus::Active)->count(),
            'available_codes' => VoucherCode::where('webshop_id', $webshopId)->where('status', VoucherCodeStatus::Available)->count(),
            'issued_vouchers' => Voucher::where('webshop_id', $webshopId)->whereNotNull('issued_at')->count(),
            'claimed_vouchers' => VoucherClaim::where('webshop_id', $webshopId)->where('status', 'claimed')->count(),
            'redeemed_vouchers' => Voucher::where('webshop_id', $webshopId)->where('status', VoucherStatus::Redeemed)->count(),
            'scheduled_offers' => Offer::where('webshop_id', $webshopId)->where('status', OfferStatus::Scheduled)->count(),
            'paused_offers' => Offer::where('webshop_id', $webshopId)->where('status', OfferStatus::Paused)->count(),
            'open_disputes' => VoucherDispute::where('webshop_id', $webshopId)->whereNotIn('status', VoucherDispute::CLOSED)->count(),
        ];
    }

    /** @return array{status: string, total: int, connected: int, attention: int, last_error_at: string|null} */
    public function connectionHealth(int $webshopId): array
    {
        $rows = WebshopConnection::where('webshop_id', $webshopId)->get(['status', 'last_error_at']);
        if ($rows->isEmpty()) {
            return ['status' => 'none', 'total' => 0, 'connected' => 0, 'attention' => 0, 'last_error_at' => null];
        }

        $attention = $rows->filter(fn (WebshopConnection $c) => in_array($c->status, [ConnectionStatus::Error, ConnectionStatus::Limited], true))->count();
        $connected = $rows->filter(fn (WebshopConnection $c) => $c->status === ConnectionStatus::Connected)->count();
        $lastError = $rows->max('last_error_at');
        $status = match (true) {
            $attention > 0 => 'attention',
            $connected > 0 => 'healthy',
            $rows->contains(fn (WebshopConnection $c) => $c->status === ConnectionStatus::SetupRequired) => 'setup_required',
            default => 'disconnected',
        };

        return [
            'status' => $status,
            'total' => $rows->count(),
            'connected' => $connected,
            'attention' => $attention,
            'last_error_at' => $lastError?->toIso8601String(),
        ];
    }

    /** @return list<array{action: string, at: string, resource_type: string|null}> */
    public function recentActivity(int $webshopId, int $limit = 8): array
    {
        return AuditEvent::where('webshop_id', $webshopId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AuditEvent $event) => [
                'action' => $event->action,
                'at' => $event->occurred_at->toIso8601String(),
                'resource_type' => $event->resource_type,
            ])->all();
    }

    /** @return list<array{key: string, available: bool}> */
    public function quickActions(int $webshopId): array
    {
        $hasOffer = Offer::where('webshop_id', $webshopId)->exists();
        $connected = WebshopConnection::where('webshop_id', $webshopId)->where('status', ConnectionStatus::Connected)->exists();

        return [
            ['key' => 'create_offer', 'available' => true],
            ['key' => 'import_codes', 'available' => $hasOffer],
            ['key' => 'connect_store', 'available' => ! $connected],
            ['key' => 'top_up_funding', 'available' => $this->funding->wallet($webshopId)->available === 0],
            ['key' => 'review_vouchers', 'available' => Voucher::where('webshop_id', $webshopId)->exists()],
        ];
    }

    public function isEmpty(int $webshopId): bool
    {
        return ! Offer::where('webshop_id', $webshopId)->exists() && ! Voucher::where('webshop_id', $webshopId)->exists();
    }

    /**
     * Offers table: available stock (not the imported quantity) and issued count.
     * Paused/expired/ended offers are discoverable via $status (never silently dropped when status=all).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function offers(int $webshopId, int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        $statuses = match ($status) {
            'active' => [OfferStatus::Active],
            'paused' => [OfferStatus::Paused],
            'draft' => [OfferStatus::Draft],
            'ended', 'expired' => [OfferStatus::Ended],
            'scheduled' => [OfferStatus::Scheduled],
            'archived' => [OfferStatus::Archived],
            default => [OfferStatus::Active, OfferStatus::Paused, OfferStatus::Draft, OfferStatus::Ended, OfferStatus::Scheduled, OfferStatus::Archived],
        };

        $query = Offer::with('currentVersion')->where('webshop_id', $webshopId)->whereIn('status', $statuses)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'draft' THEN 2 WHEN 'scheduled' THEN 3 WHEN 'ended' THEN 4 ELSE 5 END")->orderByDesc('id');
        $total = (clone $query)->count();
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $offers = $query->forPage($page, $perPage)->get();
        $stock = VoucherCode::whereIn('offer_id', $offers->pluck('id'))->where('status', VoucherCodeStatus::Available)->groupBy('offer_id')->selectRaw('offer_id, COUNT(*) n')->pluck('n', 'offer_id');
        $issued = Voucher::whereIn('offer_id', $offers->pluck('id'))->whereNotNull('issued_at')->groupBy('offer_id')->selectRaw('offer_id, COUNT(*) n')->pluck('n', 'offer_id');
        $threshold = (int) config('shopbonus.notifications.low_inventory_threshold', 20);

        $data = $offers->map(function (Offer $o) use ($stock, $issued, $threshold) {
            $v = $o->currentVersion;
            $available = (int) ($stock[$o->id] ?? 0);
            $o->setAttribute('available_codes_count', $available);
            $expired = $o->status === OfferStatus::Ended || ($v?->ends_at !== null && $v->ends_at->isPast());

            return [
                'id' => $o->public_id, 'name' => $v?->name, 'summary' => $v?->summary(), 'type' => $v?->discount_type, 'acquisition' => $o->acquisition->value,
                'min_order_minor' => $v?->min_order_minor, 'available_codes' => $available, 'issued' => (int) ($issued[$o->id] ?? 0),
                'status' => $expired && $o->status !== OfferStatus::Ended ? 'expired' : ($expired ? 'expired' : $o->status->value),
                'lifecycle_status' => $o->lifecycleStatus(),
                'attention' => array_values(array_filter([$o->status === OfferStatus::Active && $available < $threshold ? 'low_inventory' : null, $expired ? 'expired' : null])),
            ];
        })->sortBy(fn ($row) => [$row['attention'] === [] ? 1 : 0])->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * Daily claims, issuance and redemptions in the business timezone, zero-filled.
     *
     * @return array{timezone: string, from: string, to: string, days: list<array{date: string, claims: int, issued: int, redeemed: int}>, totals: array<string, int>}
     */
    public function lifecycleSeries(int $webshopId, CarbonImmutable $fromDay, CarbonImmutable $toDay): array
    {
        $tz = (string) config('shopbonus.business_timezone');
        $start = $fromDay->setTimezone($tz)->startOfDay();
        $end = $toDay->setTimezone($tz)->endOfDay();
        $bucket = fn (Collection $rows) => $rows->countBy(fn ($ts) => CarbonImmutable::parse($ts, 'UTC')->setTimezone($tz)->format('Y-m-d'));

        $claims = $bucket(VoucherClaim::where('webshop_id', $webshopId)->whereBetween('claimed_at', [$start->utc(), $end->utc()])->pluck('claimed_at'));
        $issued = $bucket(Voucher::where('webshop_id', $webshopId)->whereBetween('issued_at', [$start->utc(), $end->utc()])->pluck('issued_at'));
        $redeemed = $bucket(Voucher::where('webshop_id', $webshopId)->whereBetween('redeemed_at', [$start->utc(), $end->utc()])->pluck('redeemed_at'));

        $days = [];
        foreach (CarbonPeriod::create($start->format('Y-m-d'), $end->format('Y-m-d')) as $day) {
            $key = $day->format('Y-m-d');
            $days[] = ['date' => $key, 'claims' => (int) ($claims[$key] ?? 0), 'issued' => (int) ($issued[$key] ?? 0), 'redeemed' => (int) ($redeemed[$key] ?? 0)];
        }

        return ['timezone' => $tz, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'days' => $days,
            'totals' => ['claims' => array_sum(array_column($days, 'claims')), 'issued' => array_sum(array_column($days, 'issued')), 'redeemed' => array_sum(array_column($days, 'redeemed'))]];
    }

    /**
     * Commercial overview for a date range (paid-order based; card #24 "success-page visit ≠ paid order").
     *
     * @return array<string, mixed>
     */
    public function overview(Webshop $webshop, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = WebshopOrder::where('webshop_id', $webshop->id)->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);
        $byCurrency = (clone $orders)->selectRaw('currency, COUNT(*) n, COALESCE(SUM(amount_minor),0) gross, COALESCE(SUM(refunded_minor),0) refunded')->groupBy('currency')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->getAttribute('currency') => [
                'paid_orders' => (int) $r->getAttribute('n'), 'gross_minor' => (int) $r->getAttribute('gross'), 'refunded_minor' => (int) $r->getAttribute('refunded'),
                'net_minor' => (int) $r->getAttribute('gross') - (int) $r->getAttribute('refunded'),
                'average_order_minor' => (int) $r->getAttribute('n') === 0 ? 0 : intdiv((int) $r->getAttribute('gross'), (int) $r->getAttribute('n')),
            ]]);

        $vouchers = fn (string $column) => Voucher::where('webshop_id', $webshop->id)->whereBetween($column, [$from, $to])->count();
        $issued = $vouchers('issued_at');
        $redeemed = $vouchers('redeemed_at');
        $settled = SponsorshipObligation::where('webshop_id', $webshop->id)->where('status', 'settled')->whereBetween('settled_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(reward_minor),0) r, COALESCE(SUM(fee_minor),0) f')->first();
        $funnel = ContactOpportunity::where('webshop_id', $webshop->id)->whereBetween('created_at', [$from, $to])->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status');

        return [
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'orders' => $byCurrency->all(),
            'vouchers' => ['reserved' => $vouchers('reserved_at'), 'issued' => $issued, 'redeemed' => $redeemed,
                'redemption_rate' => $issued === 0 ? null : round($redeemed / $issued, 4)],
            'reward_cost' => ['currency' => 'EUR', 'reward_minor' => (int) $settled?->getAttribute('r'), 'fee_minor' => (int) $settled?->getAttribute('f'),
                'total_minor' => (int) $settled?->getAttribute('r') + (int) $settled?->getAttribute('f')],
            'answer24' => ['opportunities' => array_map('intval', $funnel->all()), 'claims' => VoucherClaim::where('webshop_id', $webshop->id)->whereBetween('claimed_at', [$from, $to])->count()],
            'funding' => ['available_minor' => $this->funding->wallet($webshop)->available, 'reserved_minor' => $this->funding->wallet($webshop)->reserved],
            'banner_metrics' => $this->bannerMetrics($webshop->id, $from, $to),
            'attribution' => $this->attributionBreakdown($webshop->id, $from, $to),
            'repeats' => [
                'repeat_paid_orders' => (clone $orders)->where('is_repeat_order', true)->count(),
                'definition' => 'Paid orders whose customer_key already had an earlier paid order in this shop.',
            ],
            'network' => $this->network->merchantContribution($webshop, $from, $to),
            'attribution_priority' => config('shopbonus.banners.attribution_priority'),
            'note' => 'Attributed revenue is association evidence, not incremental lift. Use A/B + holdout for lift.',
        ];
    }

    /**
     * Permission-filtered drill-down of attributed paid orders (no cross-tenant PII).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function attributionOrders(Webshop $webshop, CarbonImmutable $from, CarbonImmutable $to, ?string $type, int $perPage = 25, int $page = 1): array
    {
        $query = WebshopOrder::query()
            ->where('webshop_id', $webshop->id)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->when($type !== null, fn ($q) => $q->where('attribution_type', $type))
            ->orderByDesc('paid_at');

        $total = (clone $query)->count();
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $rows = $query->forPage($page, $perPage)->get([
            'public_id', 'external_order_id', 'reference', 'currency', 'amount_minor', 'refunded_minor',
            'payment_status', 'attribution_type', 'attribution_click_id', 'is_repeat_order', 'paid_at',
        ]);

        return [
            'data' => $rows->map(fn (WebshopOrder $o) => [
                'id' => $o->public_id,
                'external_order_id' => $o->external_order_id,
                'reference' => $o->reference,
                'currency' => $o->currency,
                'amount_minor' => $o->amount_minor,
                'refunded_minor' => $o->refunded_minor,
                'net_minor' => (int) $o->amount_minor - (int) $o->refunded_minor,
                'payment_status' => $o->payment_status->value,
                'attribution_type' => $o->attribution_type,
                'has_click_id' => $o->attribution_click_id !== null,
                'is_repeat_order' => $o->is_repeat_order,
                'paid_at' => $o->paid_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributionBreakdown(int $webshopId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = WebshopOrder::query()
            ->where('webshop_id', $webshopId)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('attribution_type, COUNT(*) n, COALESCE(SUM(amount_minor - refunded_minor),0) revenue')
            ->groupBy('attribution_type')
            ->get()
            ->keyBy('attribution_type');

        $bucket = fn (string $type) => [
            'orders' => (int) ($rows[$type]->n ?? 0),
            'net_revenue_minor' => (int) ($rows[$type]->revenue ?? 0),
        ];

        return [
            'click' => $bucket(OrderAttribution::TYPE_CLICK),
            'voucher' => $bucket(OrderAttribution::TYPE_VOUCHER),
            'view' => $bucket(OrderAttribution::TYPE_VIEW),
            'unattributed' => $bucket(OrderAttribution::TYPE_UNATTRIBUTED),
            'confidence_note' => 'click > voucher > view > unattributed; success-page visits never count as paid.',
        ];
    }

    /**
     * Totals over all banners of the webshop (B14 daily rollups); CTR = clicks / viewable.
     *
     * @return array<string, mixed>
     */
    private function bannerMetrics(int $webshopId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = (string) config('shopbonus.business_timezone');
        $campaigns = BannerCampaign::where('webshop_id', $webshopId)->pluck('id');
        if ($campaigns->isEmpty()) {
            return ['status' => 'no_banners'];
        }
        $r = DB::table('banner_daily_stats')->whereIn('campaign_id', $campaigns)
            ->whereBetween('day', [$from->setTimezone($tz)->format('Y-m-d'), $to->setTimezone($tz)->format('Y-m-d')])
            ->selectRaw('COALESCE(SUM(eligible),0) e, COALESCE(SUM(viewable_impressions),0) v, COALESCE(SUM(cta_clicks),0) k, COALESCE(SUM(dismissals),0) d')->first();

        return ['status' => 'available', 'eligible' => (int) $r->e, 'viewable_impressions' => (int) $r->v, 'cta_clicks' => (int) $r->k, 'dismissals' => (int) $r->d,
            'ctr' => (int) $r->v === 0 ? null : round((int) $r->k / (int) $r->v, 4)];
    }
}
