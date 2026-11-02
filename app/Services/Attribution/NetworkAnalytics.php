<?php

namespace App\Services\Attribution;

use App\Models\Voucher;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Finance\FeeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
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
        $asReferrer = $this->journeys()
            ->where('referring_webshop_id', $shop->id)
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('o.currency, COUNT(*) journeys, SUM(CASE WHEN o.amount_minor > o.refunded_minor THEN o.amount_minor - o.refunded_minor ELSE 0 END) gmv')
            ->groupBy('o.currency')->get();

        $asReceiver = $this->journeys()
            ->where('receiving_webshop_id', $shop->id)
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('o.currency, COUNT(*) journeys, SUM(CASE WHEN o.amount_minor > o.refunded_minor THEN o.amount_minor - o.refunded_minor ELSE 0 END) gmv')
            ->groupBy('o.currency')->get();

        $contribution = fn ($rows) => [
            'journeys' => (int) $rows->sum('journeys'),
            'currency' => $rows->count() === 1 ? $rows->first()->currency : null,
            'network_gmv_minor' => $rows->count() > 1 ? null : (int) $rows->sum('gmv'),
            'by_currency' => $rows->mapWithKeys(fn ($r) => [$r->currency => ['journeys' => (int) $r->journeys, 'network_gmv_minor' => (int) $r->gmv]])->all(),
        ];

        return [
            'status' => 'available',
            'as_referrer' => $contribution($asReferrer),
            'as_receiver' => $contribution($asReceiver),
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
        $gmv = DB::table('webshop_orders')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('currency, COALESCE(SUM(amount_minor - refunded_minor),0) net, COUNT(*) n')
            ->groupBy('currency')->get();

        $earned = Voucher::query()->whereNotNull('issued_at')->whereBetween('issued_at', [$from, $to])->count();
        $spent = Voucher::query()->whereNotNull('redeemed_at')->whereBetween('redeemed_at', [$from, $to])->count();

        $affiliate = DB::table('affiliate_transactions')
            ->whereBetween('transaction_at', [$from, $to])
            ->selectRaw("currency, COALESCE(SUM(CASE WHEN status='approved' THEN commission_minor ELSE 0 END),0) confirmed,
                COALESCE(SUM(CASE WHEN status IN ('rejected','reversed') THEN commission_minor ELSE 0 END),0) reversed,
                COALESCE(SUM(CASE WHEN status='pending' THEN commission_minor ELSE 0 END),0) pending")
            ->groupBy('currency')->get();

        $fees = DB::table('sponsorship_obligations')
            ->where('status', 'settled')
            ->whereBetween('settled_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(fee_minor),0) fees, COALESCE(SUM(reward_minor),0) rewards')
            ->first();

        $walletLiabilities = DB::table('wallets')->selectRaw('kind, unit, SUM(available) available, SUM(reserved) reserved, SUM(pending) pending')
            ->groupBy('kind', 'unit')->get()->map(fn ($r) => [
                'kind' => $r->kind, 'unit' => $r->unit, 'available' => (int) $r->available,
                'reserved' => (int) $r->reserved, 'pending' => (int) $r->pending,
                'outstanding' => (int) $r->available + (int) $r->reserved,
            ])->all();

        $journeys = $this->journeys()
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('o.currency, COUNT(*) n, COALESCE(SUM(o.amount_minor - o.refunded_minor),0) gmv')
            ->groupBy('o.currency')->get();

        $topReferring = $this->journeys()
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('referring_webshop_id, o.currency, COUNT(*) n, COALESCE(SUM(o.amount_minor - o.refunded_minor),0) gmv')
            ->groupBy('referring_webshop_id', 'o.currency')
            ->orderByDesc('n')
            ->limit(10)
            ->get();

        $topReceiving = $this->journeys()
            ->whereBetween('attributed_at', [$from, $to])
            ->selectRaw('receiving_webshop_id, o.currency, COUNT(*) n, COALESCE(SUM(o.amount_minor - o.refunded_minor),0) gmv')
            ->groupBy('receiving_webshop_id', 'o.currency')
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
                'currency' => $r->currency,
            ];
        })->values()->all();

        $unmatched = WebshopOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->where('attribution_type', 'unattributed')
            ->count();

        $affiliateAmounts = $affiliate->mapWithKeys(fn ($r) => [$r->currency => [
            'confirmed_commission_minor' => (int) $r->confirmed,
            'pending_commission_minor' => (int) $r->pending,
            'reversed_commission_minor' => (int) $r->reversed,
            'net_commission_minor' => (int) $r->confirmed - (int) $r->reversed,
        ]]);

        return [
            'generated_at' => now()->toIso8601String(),
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'shops' => $shops,
            'viewable_exposure' => $viewable,
            'unique_verified_members' => $members,
            'verified_gmv' => [
                'paid_orders' => (int) $gmv->sum('n'),
                'currency' => $gmv->count() === 1 ? $gmv->first()->currency : null,
                'net_minor' => $gmv->count() > 1 ? null : (int) $gmv->sum('net'),
                'by_currency' => $gmv->mapWithKeys(fn ($r) => [$r->currency => ['paid_orders' => (int) $r->n, 'net_minor' => (int) $r->net]])->all(),
            ],
            'vouchers' => ['earned' => $earned, 'spent' => $spent],
            'external_affiliate' => [
                'currency' => $affiliate->count() === 1 ? $affiliate->first()->currency : null,
                'confirmed_commission_minor' => $affiliate->count() > 1 ? null : (int) $affiliate->sum('confirmed'),
                'pending_commission_minor' => $affiliate->count() > 1 ? null : (int) $affiliate->sum('pending'),
                'reversed_commission_minor' => $affiliate->count() > 1 ? null : (int) $affiliate->sum('reversed'),
                'net_commission_minor' => $affiliate->count() > 1 ? null : (int) $affiliate->sum('confirmed') - (int) $affiliate->sum('reversed'),
                'by_currency' => $affiliateAmounts->all(),
                'note' => 'Network contracts differ from the direct 30% merchant fee; gross ≠ profit.',
            ],
            'direct_fees' => [
                'currency' => 'EUR',
                'fee_rate_bps' => $this->fees->feeRateBps(),
                'settled_fee_minor' => (int) ($fees->fees ?? 0),
                'settled_reward_minor' => (int) ($fees->rewards ?? 0),
            ],
            'wallet_liabilities_reserved_minor' => null,
            'wallet_liabilities' => $walletLiabilities,
            'invoice_reconciliation' => [
                'status' => 'provider_not_verified',
                'provider_issued_verified' => false,
                'internal_drafts' => DB::table('funding_invoices')->where('status', 'draft')->count(),
                'externally_referenced' => DB::table('funding_invoices')->whereNotNull('external_ref')->count(),
                'note' => 'An internal draft or external reference is not proof of provider issuance, delivery or payment reconciliation.',
            ],
            'network_journeys' => [
                'count' => (int) $journeys->sum('n'),
                'currency' => $journeys->count() === 1 ? $journeys->first()->currency : null,
                'gmv_minor' => $journeys->count() > 1 ? null : (int) $journeys->sum('gmv'),
                'by_currency' => $journeys->mapWithKeys(fn ($r) => [$r->currency => ['count' => (int) $r->n, 'gmv_minor' => (int) $r->gmv]])->all(),
                'top_referring' => $mapShop($topReferring, 'referring_webshop_id'),
                'top_receiving' => $mapShop($topReceiving, 'receiving_webshop_id'),
            ],
            'unmatched_paid_orders' => $unmatched,
            'limitations' => [
                'moneybird' => 'Only internal drafts are available; provider issuance, delivery and reconciliation are not implemented or verified.',
                'cross_shop_settlement' => 'Journeys are recorded; Wallet deduction between shops requires an agreed settlement model.',
                'live_affiliates' => 'Daisycon/TradeTracker adapters are fixture-verified; live network accounts NOT VERIFIED.',
            ],
        ];
    }

    private function journeys(): Builder
    {
        // Journey rows preserve the evidence snapshot; orders own current refunds.
        return DB::table('network_journeys')->joinSub(DB::table('webshop_orders')->select('id', 'currency', 'amount_minor', 'refunded_minor', 'paid_at'), 'o', 'o.id', '=', 'network_journeys.order_id')
            ->whereNotNull('o.paid_at');
    }
}
