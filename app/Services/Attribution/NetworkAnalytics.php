<?php

namespace App\Services\Attribution;

use App\Models\NetworkJourney;
use App\Models\SponsorshipObligation;
use App\Models\Voucher;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Finance\FeeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cross-webshop network contribution and Super Admin overview (Trello #24 §5–6).
 * Never exposes other tenants' customer PII — aggregates and public shop IDs only.
 */
class NetworkAnalytics
{
    public function __construct(private readonly FeeCalculator $fees) {}

    /**
     * Aggregated network contribution for one merchant (referring and receiving).
     *
     * @return array<string, mixed>
     */
    public function merchantContribution(Webshop $shop, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $asReferrer = NetworkJourney::query()
            ->where('referring_webshop_id', $shop->id)
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('COUNT(*) journeys, COALESCE(SUM(order_amount_minor),0) gmv')
            ->first();

        $asReceiver = NetworkJourney::query()
            ->where('receiving_webshop_id', $shop->id)
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('COUNT(*) journeys, COALESCE(SUM(order_amount_minor),0) gmv')
            ->first();

        return [
            'status' => 'available',
            'as_referrer' => [
                'journeys' => (int) ($asReferrer?->journeys ?? 0),
                'network_gmv_minor' => (int) ($asReferrer?->gmv ?? 0),
            ],
            'as_receiver' => [
                'journeys' => (int) ($asReceiver?->journeys ?? 0),
                'network_gmv_minor' => (int) ($asReceiver?->gmv ?? 0),
            ],
            'settlement_note' => 'Cross-shop journeys are recorded for agreed settlement only; ShopBonus never silently deducts the receiving shop Wallet.',
            'definitions' => [
                'as_referrer' => 'Paid orders at other shops carrying a consented click ID from this shop.',
                'as_receiver' => 'Paid orders at this shop carrying a consented click ID from another shop.',
            ],
        ];
    }

    /**
     * Super Admin 100-shop network overview (Trello #24 §6).
     *
     * @return array<string, mixed>
     */
    public function platformOverview(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = (string) config('shopbonus.business_timezone');
        $shops = Webshop::query()->count();
        $viewable = (int) DB::table('banner_daily_stats')
            ->whereBetween('day', [$from->setTimezone($tz)->format('Y-m-d'), $to->setTimezone($tz)->format('Y-m-d')])
            ->sum('viewable_impressions');

        $members = (int) DB::table('users')->whereNotNull('email_verified_at')->count();
        $gmv = WebshopOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount_minor - refunded_minor),0) net, COUNT(*) n')
            ->first();

        $earned = Voucher::query()->whereNotNull('issued_at')->whereBetween('issued_at', [$from, $to])->count();
        $spent = Voucher::query()->whereNotNull('redeemed_at')->whereBetween('redeemed_at', [$from, $to])->count();

        $affiliate = DB::table('affiliate_transactions')
            ->whereBetween('transaction_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN status='approved' THEN commission_minor ELSE 0 END),0) confirmed,
                COALESCE(SUM(CASE WHEN status IN ('rejected','reversed') THEN commission_minor ELSE 0 END),0) reversed,
                COALESCE(SUM(CASE WHEN status='pending' THEN commission_minor ELSE 0 END),0) pending")
            ->first();

        $fees = SponsorshipObligation::query()
            ->where('status', 'settled')
            ->whereBetween('settled_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(fee_minor),0) fees, COALESCE(SUM(reward_minor),0) rewards')
            ->first();

        $walletLiabilities = (int) DB::table('wallets')->sum('reserved');

        $journeys = NetworkJourney::query()
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('COUNT(*) n, COALESCE(SUM(order_amount_minor),0) gmv')
            ->first();

        $topReferring = NetworkJourney::query()
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('referring_webshop_id, COUNT(*) n, COALESCE(SUM(order_amount_minor),0) gmv')
            ->groupBy('referring_webshop_id')
            ->orderByDesc('n')
            ->limit(10)
            ->get();

        $topReceiving = NetworkJourney::query()
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('receiving_webshop_id, COUNT(*) n, COALESCE(SUM(order_amount_minor),0) gmv')
            ->groupBy('receiving_webshop_id')
            ->orderByDesc('n')
            ->limit(10)
            ->get();

        $shopNames = Webshop::query()
            ->whereIn('id', $topReferring->pluck('referring_webshop_id')->merge($topReceiving->pluck('receiving_webshop_id'))->unique())
            ->get(['id', 'public_id', 'name'])
            ->keyBy('id');

        $mapShop = fn ($rows, string $idCol) => $rows->map(function ($r) use ($shopNames, $idCol) {
            $shop = $shopNames[(int) $r->{$idCol}] ?? null;

            return [
                'webshop_id' => $shop?->public_id,
                'name' => $shop?->name,
                'journeys' => (int) $r->n,
                'gmv_minor' => (int) $r->gmv,
            ];
        })->values()->all();

        $unmatched = WebshopOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->where('attribution_type', 'unattributed')
            ->count();

        $confirmed = (int) ($affiliate->confirmed ?? 0);
        $reversed = (int) ($affiliate->reversed ?? 0);

        return [
            'generated_at' => now()->toIso8601String(),
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'shops' => $shops,
            'viewable_exposure' => $viewable,
            'unique_verified_members' => $members,
            'verified_gmv' => [
                'paid_orders' => (int) ($gmv?->n ?? 0),
                'net_minor' => (int) ($gmv?->net ?? 0),
            ],
            'vouchers' => ['earned' => $earned, 'spent' => $spent],
            'external_affiliate' => [
                'confirmed_commission_minor' => $confirmed,
                'pending_commission_minor' => (int) ($affiliate->pending ?? 0),
                'reversed_commission_minor' => $reversed,
                'net_commission_minor' => $confirmed - $reversed,
                'note' => 'Network contracts differ from the direct 30% merchant fee; gross ≠ profit.',
            ],
            'direct_fees' => [
                'fee_rate_bps' => $this->fees->feeRateBps(),
                'settled_fee_minor' => (int) ($fees?->fees ?? 0),
                'settled_reward_minor' => (int) ($fees?->rewards ?? 0),
            ],
            'wallet_liabilities_reserved_minor' => $walletLiabilities,
            'network_journeys' => [
                'count' => (int) ($journeys?->n ?? 0),
                'gmv_minor' => (int) ($journeys?->gmv ?? 0),
                'top_referring' => $mapShop($topReferring, 'referring_webshop_id'),
                'top_receiving' => $mapShop($topReceiving, 'receiving_webshop_id'),
            ],
            'unmatched_paid_orders' => $unmatched,
            'limitations' => [
                'moneybird' => 'Invoice drafts only until Moneybird credentials are configured.',
                'cross_shop_settlement' => 'Journeys are recorded; Wallet deduction between shops requires an agreed settlement model.',
                'live_affiliates' => 'Daisycon/TradeTracker adapters are fixture-verified; live network accounts NOT VERIFIED.',
            ],
        ];
    }
}
