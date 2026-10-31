<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\UserStatus;
use App\Enums\VoucherStatus;
use App\Enums\WebshopStatus;
use App\Models\AffiliateTransaction;
use App\Models\ApplicationError;
use App\Models\AuditEvent;
use App\Models\ContactOpportunity;
use App\Models\Offer;
use App\Models\OutboxMessage;
use App\Models\SupportReview;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCode;
use App\Models\VoucherDispute;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReconciliationFlag;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Super Admin cockpit figures (Trello #21 "Cockpit overview"), computed
 * from the real tables only. Every counter has one stated definition
 * (`definitions` in the response); pending and confirmed amounts are never
 * added together. Results are cached for 60 seconds per period, and
 * `generated_at` says how fresh they are.
 */
class Cockpit
{
    private const CACHE_SECONDS = 60;

    private const OPEN_DISPUTE = ['open', 'under_review', 'waiting_for_merchant'];

    /** Webshop statuses that count as "pending" (onboarding or review). */
    private const PENDING_WEBSHOP = ['draft', 'awaiting_verification', 'verification_failed', 'pending_review'];

    /** @return array<string, mixed> */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Cache::remember('cockpit:summary:'.$from->timestamp.':'.$to->timestamp, self::CACHE_SECONDS, function () use ($from, $to) {
            $warnings = [];
            $sources = ['webshops' => 'ok', 'consumers' => 'ok', 'vouchers' => 'ok', 'affiliate' => 'ok', 'wallets' => 'ok', 'support' => 'ok', 'integrations' => 'ok'];

            try {
                $kpis = $this->kpis($from, $to);
            } catch (Throwable $e) {
                report($e);
                $kpis = $this->emptyKpis();
                $warnings[] = ['code' => 'kpis_degraded', 'message' => 'Some KPI sources were unavailable.', 'request_id' => (string) Str::uuid()];
                $sources = array_map(fn () => 'degraded', $sources);
            }

            $span = max(1, $from->diffInSeconds($to));
            $prevTo = $from->subSecond();
            $prevFrom = $prevTo->subSeconds($span);
            $prev = $this->safeKpis($prevFrom, $prevTo, $warnings, $sources);

            $attention = $this->safeAttention($warnings, $sources);
            $channels = $this->safeAffiliateChannels($from, $to, $warnings, $sources);
            $cards = $this->cards($kpis, $prev, $attention, $from, $to);

            return [
                'generated_at' => now()->toIso8601String(),
                'cache_seconds' => self::CACHE_SECONDS,
                'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                'kpis' => $kpis,
                'cards' => $cards,
                'trends' => $this->safeTrends($from, $to, $warnings, $sources),
                'affiliate_channels' => $channels,
                'attention' => $attention,
                'sources' => $sources,
                'warnings' => $warnings,
                'definitions' => self::DEFINITIONS,
            ];
        });
    }

    /**
     * Platform-wide search across permitted record types.
     *
     * @return array{groups: list<array<string, mixed>>, query: string}
     */
    public function search(string $query, int $limitPerGroup = 5): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return ['query' => $q, 'groups' => []];
        }

        $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        $exact = mb_strtolower($q);
        $limit = max(1, min(10, $limitPerGroup));

        $webshops = Webshop::query()
            ->where(fn ($w) => $w->whereRaw('LOWER(name) like ?', [$like])
                ->orWhereRaw('LOWER(public_id) = ?', [$exact])
                ->orWhereRaw("LOWER(COALESCE(verified_domain, '')) like ?", [$like])
                ->orWhereHas('businessProfile', fn ($b) => $b
                    ->whereRaw("LOWER(COALESCE(website_domain, '')) like ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(website_url, '')) like ?", [$like])))
            ->orderByDesc('id')->limit($limit)->get(['public_id', 'name', 'status'])
            ->map(fn (Webshop $w) => [
                'type' => 'webshop',
                'id' => $w->public_id,
                'title' => $w->name,
                'subtitle' => $w->status->value,
                'href' => '/admin/webshops/'.$w->public_id,
            ])->all();

        $consumers = User::query()->consumers()
            ->where('status', '!=', UserStatus::Deleted)
            ->where(fn ($u) => $u->whereRaw('LOWER(email) like ?', [$like])
                ->orWhereRaw('LOWER(first_name) like ?', [$like])
                ->orWhereRaw('LOWER(public_id) = ?', [$exact]))
            ->orderByDesc('id')->limit($limit)->get(['public_id', 'email', 'first_name', 'status'])
            ->map(fn (User $u) => [
                'type' => 'consumer',
                'id' => $u->public_id,
                'title' => $u->first_name ?: 'Consument',
                'subtitle' => $this->maskEmail((string) $u->email),
                'href' => '/admin/users/'.$u->public_id,
            ])->all();

        $orders = WebshopOrder::query()
            ->where(fn ($o) => $o->whereRaw('LOWER(public_id) = ?', [$exact])
                ->orWhereRaw('LOWER(external_order_id) like ?', [$like])
                ->orWhereRaw("LOWER(COALESCE(reference, '')) like ?", [$like]))
            ->orderByDesc('id')->limit($limit)->get(['public_id', 'external_order_id', 'reference', 'webshop_id'])
            ->map(function (WebshopOrder $o) {
                $shop = Webshop::whereKey($o->webshop_id)->first(['public_id', 'name']);

                return [
                    'type' => 'order',
                    'id' => $o->public_id,
                    'title' => $o->external_order_id ?: ($o->reference ?: $o->public_id),
                    'subtitle' => $shop?->name,
                    'href' => '/admin/webshops/'.($shop?->public_id ?? '').'?order='.$o->public_id,
                ];
            })->all();

        $vouchers = Voucher::query()
            ->where(fn ($v) => $v->whereRaw('LOWER(public_id) = ?', [$exact])
                ->orWhereRaw('LOWER(redemption_reference) like ?', [$like])
                ->orWhereHas('code', fn ($c) => $c->whereRaw('LOWER(code) like ?', [$like])->orWhereRaw('LOWER(public_id) = ?', [$exact])))
            ->with(['code:id,public_id,code_last4', 'webshop:id,public_id,name'])
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Voucher $v) => [
                'type' => 'voucher',
                'id' => $v->public_id,
                'title' => $v->public_id,
                'subtitle' => ($v->webshop?->name ?? 'Voucher').' · '.$v->status->value.($v->code ? ' · ****'.$v->code->code_last4 : ''),
                'href' => '/admin/vouchers?id='.$v->public_id,
            ])->all();

        $affiliate = AffiliateTransaction::query()
            ->where(fn ($t) => $t->whereRaw('LOWER(public_id) = ?', [$exact])
                ->orWhereRaw('LOWER(external_id) like ?', [$like]))
            ->orderByDesc('id')->limit($limit)->get(['public_id', 'network', 'external_id', 'status', 'commission_minor', 'currency'])
            ->map(fn (AffiliateTransaction $t) => [
                'type' => 'affiliate_conversion',
                'id' => $t->public_id,
                'title' => $t->external_id,
                'subtitle' => ucfirst($t->network).' · '.$t->status,
                'href' => '/admin/affiliate?transaction='.$t->public_id,
            ])->all();

        $groups = array_values(array_filter([
            ['type' => 'webshops', 'label' => 'Webshops', 'results' => $webshops],
            ['type' => 'consumers', 'label' => 'Consumenten', 'results' => $consumers],
            ['type' => 'orders', 'label' => 'Orders', 'results' => $orders],
            ['type' => 'vouchers', 'label' => 'Vouchers', 'results' => $vouchers],
            ['type' => 'affiliate', 'label' => 'Affiliate', 'results' => $affiliate],
        ], fn ($g) => $g['results'] !== []));

        return ['query' => $q, 'groups' => $groups];
    }

    /**
     * Filtered voucher list for KPI / chart deep-links.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function vouchers(?string $status, ?CarbonImmutable $from, ?CarbonImmutable $to, ?string $publicId, int $perPage = 25): array
    {
        $query = Voucher::query()->with(['webshop:id,public_id,name', 'code:id,public_id,code_last4']);
        if ($publicId) {
            $query->where('public_id', strtolower($publicId));
        }
        if ($status) {
            $query->where('status', $status);
        }
        if ($from && $to) {
            $query->whereBetween('issued_at', [$from, $to]);
        }

        $page = $query->orderByDesc('id')->paginate($perPage);

        return [
            'data' => $page->getCollection()->map(fn (Voucher $v) => [
                'id' => $v->public_id,
                'status' => $v->status->value,
                'origin' => $v->origin->value,
                'webshop' => $v->webshop ? ['id' => $v->webshop->public_id, 'name' => $v->webshop->name] : null,
                'code_last4' => $v->code?->code_last4,
                'issued_at' => $v->issued_at?->toIso8601String(),
                'redeemed_at' => $v->redeemed_at?->toIso8601String(),
                'expires_at' => $v->expires_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function kpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $in = fn ($query, string $column) => $query->whereBetween($column, [$from, $to]);
        $webshops = Webshop::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $consumers = User::query()->consumers()->where('status', UserStatus::Active);

        $affiliate = $in(AffiliateTransaction::query(), 'transaction_at')
            ->whereIn('status', ['pending', 'approved', 'rejected', 'reversed'])
            ->selectRaw('status, currency, COUNT(*) n, COALESCE(SUM(commission_minor),0) total')->groupBy('status', 'currency')->get();

        $ledger = $in(WalletLedgerEntry::query()->join('wallets', 'wallets.id', '=', 'wallet_ledger_entries.wallet_id')
            ->where('wallets.kind', 'consumer_credit'), 'wallet_ledger_entries.created_at')
            ->whereIn('wallet_ledger_entries.type', ['credit', 'debit', 'expire'])
            ->selectRaw('wallets.unit, wallet_ledger_entries.type, COALESCE(SUM(wallet_ledger_entries.amount),0) total')
            ->groupBy('wallets.unit', 'wallet_ledger_entries.type')->toBase()->get();
        $outstanding = Wallet::where('kind', 'consumer_credit')->selectRaw('unit, COALESCE(SUM(available),0) a, COALESCE(SUM(reserved),0) r, COALESCE(SUM(pending),0) p')
            ->groupBy('unit')->toBase()->get();
        $units = $outstanding->pluck('unit')->merge($ledger->pluck('unit'))->unique()->sort()->values();

        $contacts = $in(ContactOpportunity::query(), 'created_at')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $activeVouchers = Voucher::where('status', VoucherStatus::Issued)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return [
            'webshops' => [
                'total' => $webshops->sum(),
                'active' => $webshops[WebshopStatus::Approved->value] ?? 0,
                'pending' => collect(self::PENDING_WEBSHOP)->sum(fn ($s) => $webshops[$s] ?? 0),
                'pending_review' => $webshops[WebshopStatus::PendingReview->value] ?? 0,
                'suspended' => $webshops[WebshopStatus::Suspended->value] ?? 0,
                'rejected' => $webshops[WebshopStatus::Rejected->value] ?? 0,
            ],
            'consumers' => [
                'registered' => (clone $consumers)->count(),
                'new_in_period' => $in(clone $consumers, 'created_at')->count(),
                'active_in_period' => $in(clone $consumers, 'last_login_at')->count(),
            ],
            'offers' => ['published' => Offer::where('status', 'active')->count()],
            'vouchers' => [
                'issued_in_period' => $in(Voucher::query(), 'issued_at')->count(),
                'redeemed_in_period' => $in(Voucher::query(), 'redeemed_at')->count(),
                'outstanding' => $activeVouchers,
                'available_codes' => VoucherCode::where('status', 'available')->count(),
            ],
            'orders' => [
                'received_in_period' => $in(WebshopOrder::query(), 'created_at')->count(),
                'confirmed_paid_in_period' => $in(WebshopOrder::query(), 'paid_at')->count(),
            ],
            'answer24' => [
                'requests_in_period' => $contacts->sum(),
                'by_status' => $contacts->all(),
            ],
            'affiliate_commissions' => collect(['pending', 'approved', 'rejected', 'reversed'])->mapWithKeys(fn ($status) => [$status => [
                'count' => (int) $affiliate->where('status', $status)->sum('n'),
                'by_currency' => $affiliate->where('status', $status)->mapWithKeys(fn ($r) => [(string) $r->getAttribute('currency') => (int) $r->getAttribute('total')])->all(),
            ]])->all(),
            'wallets' => $units->mapWithKeys(function ($unit) use ($ledger, $outstanding) {
                $sum = fn (string $type) => (int) $ledger->where('unit', $unit)->where('type', $type)->sum('total');
                $o = $outstanding->firstWhere('unit', $unit);

                return [(string) $unit => [
                    'issued_in_period' => $sum('credit'),
                    'spent_in_period' => -$sum('debit'),
                    'expired_in_period' => -$sum('expire'),
                    'available' => (int) ($o->a ?? 0),
                    'reserved' => (int) ($o->r ?? 0),
                    'outstanding' => (int) ($o->a ?? 0) + (int) ($o->r ?? 0),
                    'pending' => (int) ($o->p ?? 0),
                ]];
            })->all(),
            'disputes' => ['open' => VoucherDispute::whereIn('status', self::OPEN_DISPUTE)->count()],
            'incidents' => [
                'application_errors_in_period' => $in(ApplicationError::query(), 'occurred_at')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'failed_webhooks_in_period' => $in(WebhookEvent::query()->where('status', 'failed'), 'received_at')->count(),
                'failed_outbox' => OutboxMessage::where('status', 'failed')->count(),
                'open_reconciliation_flags' => WalletReconciliationFlag::where('status', 'open')->count(),
                'paid_unfulfilled_checkouts' => VoucherCheckout::where('state', 'paid_unfulfilled')->count(),
                'access_denials_in_period' => $in(AuditEvent::query()->where('outcome', AuditOutcome::Denied), 'occurred_at')->count(),
            ],
        ];
    }

    /**
     * Six headline cards for the Super Admin cockpit UI.
     *
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $attention
     * @return list<array<string, mixed>>
     */
    private function cards(array $kpis, array $prev, array $attention, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $approvedEur = (int) ($kpis['affiliate_commissions']['approved']['by_currency']['EUR'] ?? 0);
        $pendingEur = (int) ($kpis['affiliate_commissions']['pending']['by_currency']['EUR'] ?? 0);
        $prevApproved = (int) ($prev['affiliate_commissions']['approved']['by_currency']['EUR'] ?? 0);

        $wallet = $kpis['wallets']['eur_credit'] ?? ['outstanding' => 0, 'available' => 0, 'reserved' => 0, 'pending' => 0];
        $prevWallet = (int) (($prev['wallets']['eur_credit']['outstanding'] ?? 0));

        $openTotal = (int) ($attention['open_actions']['total'] ?? 0);
        $prevOpen = $prev['open_actions_total'] ?? null;

        $issuedDay = $from->toDateString();
        $issuedTo = $to->toDateString();

        return [
            [
                'key' => 'active_webshops',
                'label' => 'Actieve webshops',
                'value' => (int) $kpis['webshops']['active'],
                'display' => number_format((int) $kpis['webshops']['active'], 0, ',', '.'),
                'change_percent' => $this->changePercent((int) $kpis['webshops']['active'], (int) ($prev['webshops']['active'] ?? 0)),
                'helper' => 'Goedgekeurde workspaces',
                'href' => '/admin/webshops?status=approved',
                'tone' => 'blue',
                'icon' => 'store',
            ],
            [
                'key' => 'consumers',
                'label' => 'Consumenten',
                'value' => (int) $kpis['consumers']['registered'],
                'display' => number_format((int) $kpis['consumers']['registered'], 0, ',', '.'),
                'change_percent' => $this->changePercent((int) $kpis['consumers']['registered'], (int) ($prev['consumers']['registered'] ?? 0)),
                'helper' => 'Actieve consumentenaccounts',
                'href' => '/admin/users?role=consumer&status=active',
                'tone' => 'blue',
                'icon' => 'users',
            ],
            [
                'key' => 'active_vouchers',
                'label' => 'Actieve vouchers',
                'value' => (int) $kpis['vouchers']['outstanding'],
                'display' => number_format((int) $kpis['vouchers']['outstanding'], 0, ',', '.'),
                'change_percent' => $this->changePercent((int) $kpis['vouchers']['outstanding'], (int) ($prev['vouchers']['outstanding'] ?? 0)),
                'helper' => 'Uitgegeven en nog bruikbaar',
                'href' => '/admin/vouchers?status=issued',
                'tone' => 'blue',
                'icon' => 'ticket',
            ],
            [
                'key' => 'affiliate_commission',
                'label' => 'Affiliatecommissie',
                'value' => $approvedEur,
                'display' => $this->formatEur($approvedEur),
                'change_percent' => $this->changePercent($approvedEur, $prevApproved),
                'helper' => 'Bevestigd · pending '.$this->formatEur($pendingEur),
                'status' => 'confirmed',
                'breakdown' => [
                    'confirmed_minor' => $approvedEur,
                    'pending_minor' => $pendingEur,
                    'rejected_minor' => (int) ($kpis['affiliate_commissions']['rejected']['by_currency']['EUR'] ?? 0),
                    'reversed_minor' => (int) ($kpis['affiliate_commissions']['reversed']['by_currency']['EUR'] ?? 0),
                ],
                'href' => '/admin/affiliate?status=approved&from='.$issuedDay.'&to='.$issuedTo,
                'tone' => 'blue',
                'icon' => 'coins',
            ],
            [
                'key' => 'wallet_liability',
                'label' => 'Virtueel wallettegoed',
                'value' => (int) ($wallet['outstanding'] ?? 0),
                'display' => $this->formatEur((int) ($wallet['outstanding'] ?? 0)),
                'change_percent' => $this->changePercent((int) ($wallet['outstanding'] ?? 0), $prevWallet),
                'helper' => 'Beschikbaar '.$this->formatEur((int) ($wallet['available'] ?? 0)).' · gereserveerd '.$this->formatEur((int) ($wallet['reserved'] ?? 0)).' · geen winst',
                'breakdown' => [
                    'available_minor' => (int) ($wallet['available'] ?? 0),
                    'reserved_minor' => (int) ($wallet['reserved'] ?? 0),
                    'pending_minor' => (int) ($wallet['pending'] ?? 0),
                    'outstanding_minor' => (int) ($wallet['outstanding'] ?? 0),
                ],
                'href' => '/admin/wallets',
                'tone' => 'blue',
                'icon' => 'wallet',
            ],
            [
                'key' => 'open_actions',
                'label' => 'Openstaande acties',
                'value' => $openTotal,
                'display' => number_format($openTotal, 0, ',', '.'),
                'change_percent' => $this->changePercent($openTotal, $prevOpen),
                'helper' => 'Goedkeuringen, syncs en support',
                'href' => '/admin#actions',
                'tone' => $openTotal > 0 ? 'amber' : 'blue',
                'icon' => 'list',
            ],
        ];
    }

    /**
     * Daily counts in the business timezone.
     *
     * @return array<string, mixed>
     */
    private function trends(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = (string) config('shopbonus.business_timezone');
        $bucket = fn (Collection $rows) => $rows->countBy(fn ($ts) => CarbonImmutable::parse($ts, 'UTC')->setTimezone($tz)->format('Y-m-d'));
        $series = [
            'new_webshops' => $bucket(Webshop::whereBetween('created_at', [$from, $to])->pluck('created_at')),
            'new_consumers' => $bucket(User::query()->consumers()->whereBetween('created_at', [$from, $to])->pluck('created_at')),
            'paid_orders' => $bucket(WebshopOrder::whereBetween('paid_at', [$from, $to])->pluck('paid_at')),
            'vouchers_issued' => $bucket(Voucher::whereBetween('issued_at', [$from, $to])->pluck('issued_at')),
            'vouchers_redeemed' => $bucket(Voucher::whereBetween('redeemed_at', [$from, $to])->pluck('redeemed_at')),
        ];

        $days = [];
        foreach (CarbonPeriod::create($from->setTimezone($tz)->format('Y-m-d'), $to->setTimezone($tz)->format('Y-m-d')) as $day) {
            $key = $day->format('Y-m-d');
            $days[] = ['date' => $key] + array_map(fn (Collection $c) => (int) ($c[$key] ?? 0), $series);
        }

        return ['timezone' => $tz, 'days' => $days];
    }

    /**
     * Affiliate-driven activity by network for the donut chart.
     * Metric is conversion events (not commission amounts, not ShopBonus voucher inventory).
     *
     * @return array<string, mixed>
     */
    private function affiliateChannels(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = AffiliateTransaction::query()
            ->whereBetween('transaction_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('duplicate_of_id'))
            ->selectRaw('LOWER(network) as network, COUNT(*) as n')
            ->groupBy('network')
            ->orderByDesc('n')
            ->get();

        $total = (int) $rows->sum('n');
        $known = ['daisycon' => 'Daisycon', 'tradetracker' => 'TradeTracker'];
        $channels = [];
        $other = 0;

        foreach ($rows as $row) {
            $network = (string) $row->network;
            $count = (int) $row->n;
            if (isset($known[$network])) {
                $channels[] = [
                    'network' => $network,
                    'label' => $known[$network],
                    'count' => $count,
                    'percent' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                    'href' => '/admin/affiliate?network='.$network.'&from='.$from->toDateString().'&to='.$to->toDateString(),
                ];
            } else {
                $other += $count;
            }
        }

        if ($other > 0) {
            $channels[] = [
                'network' => 'other',
                'label' => 'Overig / onbekend',
                'count' => $other,
                'percent' => $total > 0 ? (int) round(($other / $total) * 100) : 0,
                'href' => '/admin/affiliate?from='.$from->toDateString().'&to='.$to->toDateString(),
            ];
        }

        // Keep percentages summing to 100 when possible by adjusting the largest slice.
        if ($channels !== [] && $total > 0) {
            $sumPct = array_sum(array_column($channels, 'percent'));
            if ($sumPct !== 100) {
                $channels[0]['percent'] += (100 - $sumPct);
            }
        }

        return [
            'metric' => 'conversions',
            'metric_label' => 'Affiliateconversies in de periode (geen commissiebedragen; geen vouchervoorraad).',
            'total' => $total,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'channels' => $channels,
        ];
    }

    /**
     * Short lists of records that need a human, each with its public ID.
     *
     * @return array<string, mixed>
     */
    private function attention(): array
    {
        $pendingApprovals = Webshop::where('status', WebshopStatus::PendingReview)->orderBy('review_requested_at')->limit(10)->get()
            ->map(fn (Webshop $w) => ['webshop_id' => $w->public_id, 'name' => $w->name, 'review_requested_at' => $w->review_requested_at?->toIso8601String()])->all();

        $integrationFailures = WebshopConnection::with('webshop:id,public_id,name')->where('status', 'error')->latest('last_error_at')->limit(10)->get()
            ->map(fn (WebshopConnection $c) => ['connection_id' => $c->public_id, 'webshop_id' => $c->webshop->public_id, 'webshop' => $c->webshop->name,
                'platform' => $c->platform, 'error_code' => $c->last_error_code, 'at' => $c->last_error_at?->toIso8601String()])->all();

        $pendingCount = Webshop::where('status', WebshopStatus::PendingReview)->count();
        $failedSyncCount = WebshopConnection::where('status', 'error')->count();
        $openSupportCount = SupportReview::whereNull('outcome')->count();

        return [
            'pending_approvals' => $pendingApprovals,
            'integration_failures' => $integrationFailures,
            'failed_jobs' => DB::table('failed_jobs')->orderByDesc('id')->limit(10)->get(['uuid', 'queue', 'payload', 'failed_at'])
                ->map(fn ($j) => ['uuid' => $j->uuid, 'queue' => $j->queue, 'job' => json_decode((string) $j->payload, true)['displayName'] ?? null, 'failed_at' => CarbonImmutable::parse($j->failed_at)->toIso8601String()])->all(),
            'reconciliation_flags' => WalletReconciliationFlag::where('status', 'open')->latest('id')->limit(10)->get()
                ->map(fn (WalletReconciliationFlag $f) => ['id' => $f->id, 'reason' => $f->reason, 'amount' => $f->amount, 'created_at' => $f->created_at->toIso8601String()])->all(),
            'paid_unfulfilled_checkouts' => VoucherCheckout::where('state', 'paid_unfulfilled')->latest('id')->limit(10)->pluck('public_id')->all(),
            'affiliate_problems' => AffiliateTransaction::whereIn('status', ['unmatched', 'disputed'])->orWhere('reward_status', 'reversal_flagged')->count(),
            'security_denials_24h' => AuditEvent::where('outcome', AuditOutcome::Denied)->where('occurred_at', '>=', now()->subDay())
                ->selectRaw('route, http_status, COUNT(*) n')->groupBy('route', 'http_status')->orderByDesc('n')->limit(10)->get()
                ->map(fn ($r) => ['route' => $r->getAttribute('route'), 'http_status' => (int) $r->getAttribute('http_status'), 'count' => (int) $r->getAttribute('n')])->all(),
            'recent_admin_actions' => AuditEvent::where('category', AuditCategory::Admin)->latest('id')->limit(10)->get()
                ->map(fn (AuditEvent $e) => ['action' => $e->action, 'outcome' => $e->outcome->value, 'occurred_at' => $e->occurred_at->toIso8601String()])->all(),
            'open_actions' => [
                'pending_approvals' => [
                    'count' => $pendingCount,
                    'severity' => $pendingCount > 0 ? 'high' : 'none',
                    'href' => '/admin/webshops?status=pending_review',
                    'title' => 'webshops wachten op goedkeuring',
                    'detail' => 'Bekijk en keur nieuwe webshops goed.',
                ],
                'failed_syncs' => [
                    'count' => $failedSyncCount,
                    'severity' => $failedSyncCount > 0 ? 'medium' : 'none',
                    'href' => '/admin/integrations?status=error',
                    'title' => 'mislukte syncs',
                    'detail' => 'Controleer mislukte imports of connectorfouten.',
                ],
                'open_support' => [
                    'count' => $openSupportCount,
                    'severity' => $openSupportCount > 0 ? 'high' : 'none',
                    'href' => '/admin/support?outcome=open',
                    'title' => 'supportcases',
                    'detail' => 'Nieuwe of openstaande supportcases.',
                ],
                'total' => $pendingCount + $failedSyncCount + $openSupportCount,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyKpis(): array
    {
        return [
            'webshops' => ['total' => 0, 'active' => 0, 'pending' => 0, 'pending_review' => 0, 'suspended' => 0, 'rejected' => 0],
            'consumers' => ['registered' => 0, 'new_in_period' => 0, 'active_in_period' => 0],
            'offers' => ['published' => 0],
            'vouchers' => ['issued_in_period' => 0, 'redeemed_in_period' => 0, 'outstanding' => 0, 'available_codes' => 0],
            'orders' => ['received_in_period' => 0, 'confirmed_paid_in_period' => 0],
            'answer24' => ['requests_in_period' => 0, 'by_status' => []],
            'affiliate_commissions' => collect(['pending', 'approved', 'rejected', 'reversed'])->mapWithKeys(fn ($s) => [$s => ['count' => 0, 'by_currency' => []]])->all(),
            'wallets' => [],
            'disputes' => ['open' => 0],
            'incidents' => [
                'application_errors_in_period' => 0, 'failed_jobs' => 0, 'failed_webhooks_in_period' => 0,
                'failed_outbox' => 0, 'open_reconciliation_flags' => 0, 'paid_unfulfilled_checkouts' => 0, 'access_denials_in_period' => 0,
            ],
        ];
    }

    /** @param  list<array<string, mixed>>  $warnings
     * @param  array<string, string>  $sources
     * @return array<string, mixed>
     */
    private function safeKpis(CarbonImmutable $from, CarbonImmutable $to, array &$warnings, array &$sources): array
    {
        try {
            $kpis = $this->kpis($from, $to);

            // kpis() counts point-in-time totals as of now; the comparison needs them as of $to.
            $kpis['webshops']['active'] = Webshop::where('approved_at', '<=', $to)->whereIn('status', [WebshopStatus::Approved, WebshopStatus::Suspended])
                ->where(fn ($q) => $q->whereNull('suspended_at')->orWhere('suspended_at', '>', $to))->count();
            $kpis['consumers']['registered'] = User::query()->consumers()->where('status', UserStatus::Active)->where('created_at', '<=', $to)->count();
            $kpis['vouchers']['outstanding'] = Voucher::where('issued_at', '<=', $to)->whereIn('status', [VoucherStatus::Issued, VoucherStatus::Redeemed, VoucherStatus::Expired])
                ->where(fn ($q) => $q->whereNull('redeemed_at')->orWhere('redeemed_at', '>', $to))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $to))->count();
            // Ledger amounts are the signed effect on available + reserved, so their running sum is the balance.
            $kpis['wallets']['eur_credit']['outstanding'] = (int) WalletLedgerEntry::join('wallets', 'wallets.id', '=', 'wallet_ledger_entries.wallet_id')
                ->where('wallets.kind', 'consumer_credit')->where('wallets.unit', 'eur_credit')->where('wallet_ledger_entries.created_at', '<=', $to)->sum('wallet_ledger_entries.amount');
            // Open actions have no history: no comparison rather than a made-up one.
            $kpis['open_actions_total'] = null;

            return $kpis;
        } catch (Throwable $e) {
            report($e);
            $warnings[] = ['code' => 'comparison_degraded', 'message' => 'Period comparison partially unavailable.', 'request_id' => (string) Str::uuid()];
            $sources['webshops'] = 'degraded';

            return $this->emptyKpis() + ['open_actions_total' => null];
        }
    }

    /** @param  list<array<string, mixed>>  $warnings
     * @param  array<string, string>  $sources
     * @return array<string, mixed>
     */
    private function safeAttention(array &$warnings, array &$sources): array
    {
        try {
            return $this->attention();
        } catch (Throwable $e) {
            report($e);
            $warnings[] = ['code' => 'attention_degraded', 'message' => 'Action Required panel partially unavailable.', 'request_id' => (string) Str::uuid()];
            $sources['support'] = 'degraded';
            $sources['integrations'] = 'degraded';

            return [
                'pending_approvals' => [],
                'integration_failures' => [],
                'failed_jobs' => [],
                'reconciliation_flags' => [],
                'paid_unfulfilled_checkouts' => [],
                'affiliate_problems' => 0,
                'security_denials_24h' => [],
                'recent_admin_actions' => [],
                'open_actions' => [
                    'pending_approvals' => ['count' => 0, 'severity' => 'none', 'href' => '/admin/webshops?status=pending_review', 'title' => 'webshops wachten op goedkeuring', 'detail' => ''],
                    'failed_syncs' => ['count' => 0, 'severity' => 'none', 'href' => '/admin/integrations?status=error', 'title' => 'mislukte syncs', 'detail' => ''],
                    'open_support' => ['count' => 0, 'severity' => 'none', 'href' => '/admin/support?outcome=open', 'title' => 'supportcases', 'detail' => ''],
                    'total' => 0,
                ],
            ];
        }
    }

    /** @param  list<array<string, mixed>>  $warnings
     * @param  array<string, string>  $sources
     * @return array<string, mixed>
     */
    private function safeAffiliateChannels(CarbonImmutable $from, CarbonImmutable $to, array &$warnings, array &$sources): array
    {
        try {
            return $this->affiliateChannels($from, $to);
        } catch (Throwable $e) {
            report($e);
            $warnings[] = ['code' => 'affiliate_degraded', 'message' => 'Affiliate channel chart unavailable.', 'request_id' => (string) Str::uuid()];
            $sources['affiliate'] = 'degraded';

            return [
                'metric' => 'conversions',
                'metric_label' => 'Affiliateconversies in de periode (geen commissiebedragen; geen vouchervoorraad).',
                'total' => 0,
                'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                'channels' => [],
                'degraded' => true,
            ];
        }
    }

    /** @param  list<array<string, mixed>>  $warnings
     * @param  array<string, string>  $sources
     * @return array<string, mixed>
     */
    private function safeTrends(CarbonImmutable $from, CarbonImmutable $to, array &$warnings, array &$sources): array
    {
        try {
            return $this->trends($from, $to);
        } catch (Throwable $e) {
            report($e);
            $warnings[] = ['code' => 'trends_degraded', 'message' => 'Voucher activity chart unavailable.', 'request_id' => (string) Str::uuid()];
            $sources['vouchers'] = 'degraded';

            return ['timezone' => (string) config('shopbonus.business_timezone'), 'days' => [], 'degraded' => true];
        }
    }

    private function changePercent(int $current, ?int $previous): ?float
    {
        if ($previous === null) {
            return null;
        }
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function formatEur(int $minor): string
    {
        return '€ '.number_format($minor / 100, 2, ',', '.');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }
        $keep = max(1, min(2, strlen($local)));

        return substr($local, 0, $keep).str_repeat('*', max(1, strlen($local) - $keep)).'@'.$domain;
    }

    private const DEFINITIONS = [
        'webshops.active' => 'Status approved (commercially active workspaces).',
        'webshops.pending' => 'Status draft, awaiting_verification, verification_failed or pending_review; pending_review is waiting for an admin.',
        'consumers.registered' => 'Consumer-intent accounts with status active (deleted/suspended excluded).',
        'consumers.active_in_period' => 'Active consumers who signed in during the period.',
        'offers.published' => 'Offers with status active.',
        'vouchers.issued_in_period' => 'Vouchers whose issued_at is in the period (reserved claims are not issued).',
        'vouchers.outstanding' => 'Status issued and not past expires_at; usable vouchers only.',
        'vouchers.available_codes' => 'Codes in inventory that are not reserved, issued, redeemed or invalid.',
        'orders.confirmed_paid_in_period' => 'Orders whose paid_at (provider-confirmed payment) is in the period.',
        'affiliate_commissions' => 'Network commissions by our status, summed per currency; pending, approved, rejected and reversed are never added together. Headline card shows confirmed (approved) only.',
        'affiliate_channels' => 'Count of non-duplicate affiliate conversions per network in the period. Not commission amounts and not ShopBonus voucher inventory.',
        'wallets' => 'Consumer wallets per unit: credits issued, debits spent and expiries in the period; outstanding = available + reserved now (liability, not profit); pending (not yet credited) is separate.',
        'open_actions' => 'Sum of unique pending webshop reviews, errored integrations and unresolved support reviews.',
        'incidents' => 'Counts of recorded technical faults and failures; access denials are audited 401/403 responses.',
    ];
}
