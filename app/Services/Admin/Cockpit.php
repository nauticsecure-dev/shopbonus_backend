<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\WebshopStatus;
use App\Models\AffiliateTransaction;
use App\Models\ApplicationError;
use App\Models\AuditEvent;
use App\Models\ContactOpportunity;
use App\Models\Offer;
use App\Models\OutboxMessage;
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
        return Cache::remember('cockpit:summary:'.$from->timestamp.':'.$to->timestamp, self::CACHE_SECONDS, fn () => [
            'generated_at' => now()->toIso8601String(),
            'cache_seconds' => self::CACHE_SECONDS,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'kpis' => $this->kpis($from, $to),
            'trends' => $this->trends($from, $to),
            'attention' => $this->attention(),
            'definitions' => self::DEFINITIONS,
        ]);
    }

    /** @return array<string, mixed> */
    private function kpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $in = fn ($query, string $column) => $query->whereBetween($column, [$from, $to]);
        $webshops = Webshop::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $consumers = User::where('onboarding_intent', 'consumer')->where('status', '!=', 'deleted');

        $affiliate = $in(AffiliateTransaction::query(), 'transaction_at')
            ->whereIn('status', ['pending', 'approved', 'rejected'])
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
                'outstanding' => Voucher::where('status', 'issued')->count(),
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
            'affiliate_commissions' => collect(['pending', 'approved', 'rejected'])->mapWithKeys(fn ($status) => [$status => [
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
            'new_consumers' => $bucket(User::where('onboarding_intent', 'consumer')->whereBetween('created_at', [$from, $to])->pluck('created_at')),
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
     * Short lists of records that need a human, each with its public ID.
     *
     * @return array<string, mixed>
     */
    private function attention(): array
    {
        return [
            'pending_approvals' => Webshop::where('status', WebshopStatus::PendingReview)->orderBy('review_requested_at')->limit(10)->get()
                ->map(fn (Webshop $w) => ['webshop_id' => $w->public_id, 'name' => $w->name, 'review_requested_at' => $w->review_requested_at?->toIso8601String()])->all(),
            'integration_failures' => WebshopConnection::with('webshop:id,public_id,name')->where('status', 'error')->latest('last_error_at')->limit(10)->get()
                ->map(fn (WebshopConnection $c) => ['connection_id' => $c->public_id, 'webshop_id' => $c->webshop->public_id, 'webshop' => $c->webshop->name,
                    'platform' => $c->platform, 'error_code' => $c->last_error_code, 'at' => $c->last_error_at?->toIso8601String()])->all(),
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
        ];
    }

    private const DEFINITIONS = [
        'webshops.active' => 'Status approved.',
        'webshops.pending' => 'Status draft, awaiting_verification, verification_failed or pending_review; pending_review is waiting for an admin.',
        'consumers.registered' => 'Accounts that signed up with the consumer intent and are not deleted.',
        'consumers.active_in_period' => 'Consumers who signed in during the period.',
        'offers.published' => 'Offers with status active.',
        'vouchers.issued_in_period' => 'Vouchers whose issued_at is in the period (reserved claims are not issued).',
        'vouchers.outstanding' => 'Issued, not yet redeemed, expired or cancelled.',
        'vouchers.available_codes' => 'Codes in inventory that are not reserved, issued, redeemed or invalid.',
        'orders.confirmed_paid_in_period' => 'Orders whose paid_at (provider-confirmed payment) is in the period.',
        'affiliate_commissions' => 'Network commissions by our status, summed per currency; pending, approved and rejected are never added together.',
        'wallets' => 'Consumer wallets per unit: credits issued, debits spent and expiries in the period; outstanding = available + reserved now; pending (not yet credited) is separate.',
        'incidents' => 'Counts of recorded technical faults and failures; access denials are audited 401/403 responses.',
    ];
}
