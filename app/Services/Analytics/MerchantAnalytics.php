<?php

namespace App\Services\Analytics;

use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Models\ContactOpportunity;
use App\Models\Offer;
use App\Models\SponsorshipObligation;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Funding\MerchantFunding;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

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
    ];

    public function __construct(private readonly MerchantFunding $funding) {}

    /** @return array<string, int> */
    public function kpis(int $webshopId): array
    {
        return [
            'active_offers' => Offer::where('webshop_id', $webshopId)->where('status', OfferStatus::Active)->count(),
            'available_codes' => VoucherCode::where('webshop_id', $webshopId)->where('status', VoucherCodeStatus::Available)->count(),
            'issued_vouchers' => Voucher::where('webshop_id', $webshopId)->whereNotNull('issued_at')->count(),
            'claimed_vouchers' => VoucherClaim::where('webshop_id', $webshopId)->where('status', 'claimed')->count(),
            'redeemed_vouchers' => Voucher::where('webshop_id', $webshopId)->where('status', VoucherStatus::Redeemed)->count(),
        ];
    }

    /**
     * Offers table: available stock (not the imported quantity) and issued count.
     *
     * @return list<array<string, mixed>>
     */
    public function offers(int $webshopId, int $limit = 10): array
    {
        $offers = Offer::with('currentVersion')->where('webshop_id', $webshopId)->whereIn('status', [OfferStatus::Active, OfferStatus::Paused, OfferStatus::Draft])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderByDesc('id')->limit($limit)->get();
        $stock = VoucherCode::whereIn('offer_id', $offers->pluck('id'))->where('status', VoucherCodeStatus::Available)->groupBy('offer_id')->selectRaw('offer_id, COUNT(*) n')->pluck('n', 'offer_id');
        $issued = Voucher::whereIn('offer_id', $offers->pluck('id'))->whereNotNull('issued_at')->groupBy('offer_id')->selectRaw('offer_id, COUNT(*) n')->pluck('n', 'offer_id');
        $threshold = (int) config('shopbonus.notifications.low_inventory_threshold', 20);

        return $offers->map(function (Offer $o) use ($stock, $issued, $threshold) {
            $v = $o->currentVersion;
            $available = (int) ($stock[$o->id] ?? 0);
            $expired = $v?->ends_at !== null && $v->ends_at->isPast();

            return [
                'id' => $o->public_id, 'name' => $v?->name, 'summary' => $v?->summary(), 'type' => $v?->discount_type, 'acquisition' => $o->acquisition->value,
                'min_order_minor' => $v?->min_order_minor, 'available_codes' => $available, 'issued' => (int) ($issued[$o->id] ?? 0),
                'status' => $expired ? 'expired' : $o->status->value,
                'attention' => array_values(array_filter([$o->status === OfferStatus::Active && $available < $threshold ? 'low_inventory' : null, $expired ? 'expired' : null])),
            ];
        })->sortBy(fn ($row) => [$row['attention'] === [] ? 1 : 0])->values()->all();
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
            'banner_metrics' => ['status' => 'not_available', 'reason' => 'Banner impressions and clicks are collected by the banner backend (B14).'],
        ];
    }
}
