<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FundingInvoice;
use App\Models\FundingTopUp;
use App\Models\SponsorshipObligation;
use App\Models\Voucher;
use App\Models\WalletLedgerEntry;
use App\Services\Finance\FeeCalculator;
use App\Services\Funding\MerchantFunding;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The webshop's reward funding (Trello #24 §4): balances, top-ups,
 * sponsorship obligations and invoice statements. Funding is spent only on
 * sponsored rewards and their fee; there is no withdrawal.
 */
class FundingController extends Controller
{
    public function __construct(private readonly MerchantFunding $funding) {}

    public function show(CurrentWorkspace $current, FeeCalculator $fees): JsonResponse
    {
        $webshopId = $current->webshop()->id;
        $wallet = $this->funding->wallet($webshopId);
        $sums = SponsorshipObligation::where('webshop_id', $webshopId)->selectRaw('status, COALESCE(SUM(total_minor),0) t, COUNT(*) n')->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [$r->getAttribute('status') => ['amount_minor' => (int) $r->getAttribute('t'), 'count' => (int) $r->getAttribute('n')]]);

        return response()->json(['data' => [
            'currency' => 'EUR',
            'available_minor' => $wallet->available,
            'reserved_minor' => $wallet->reserved,
            'settled_minor' => $sums['settled']['amount_minor'] ?? 0,
            'released_minor' => $sums['released']['amount_minor'] ?? 0,
            'topped_up_minor' => (int) FundingTopUp::where('webshop_id', $webshopId)->where('state', 'paid')->sum('amount_minor'),
            'obligations' => ['reserved' => $sums['reserved']['count'] ?? 0, 'settled' => $sums['settled']['count'] ?? 0, 'released' => $sums['released']['count'] ?? 0],
            'fee_rate_bps' => $fees->feeRateBps(),
            'fee_basis' => ['fixed' => 'reward', 'percentage' => config('shopbonus.funding.percentage_basis')],
            'funding_required' => $this->funding->required(),
            'withdrawable' => false,
        ]]);
    }

    public function transactions(CurrentWorkspace $current): JsonResponse
    {
        $wallet = $this->funding->wallet($current->webshop());
        $page = WalletLedgerEntry::where('wallet_id', $wallet->id)->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (WalletLedgerEntry $e) => ['id' => $e->public_id, 'type' => $e->type->value, 'amount_minor' => $e->amount,
                'available_after_minor' => $e->available_after, 'reserved_after_minor' => $e->reserved_after,
                'source' => ['type' => $e->source_type, 'ref' => $e->source_ref], 'description' => $e->reason, 'created_at' => $e->created_at->toIso8601String()])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function obligations(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['reserved', 'settled', 'released'])]]);
        $rows = SponsorshipObligation::where('webshop_id', $current->webshop()->id)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')->limit(200)->get();
        $vouchers = Voucher::whereIn('id', $rows->pluck('voucher_id'))->pluck('public_id', 'id');

        return response()->json(['data' => $rows->map(fn (SponsorshipObligation $o) => [
            'id' => $o->public_id, 'voucher_id' => $vouchers[$o->voucher_id] ?? null, 'status' => $o->status, 'basis' => $o->basis,
            'reward_minor' => $o->reward_minor, 'fee_minor' => $o->fee_minor, 'total_minor' => $o->total_minor,
            'created_at' => $o->created_at->toIso8601String(), 'settled_at' => $o->settled_at?->toIso8601String(), 'released_at' => $o->released_at?->toIso8601String(),
        ])->all()]);
    }

    public function topUp(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:'.config('shopbonus.funding.min_top_up_minor'), 'max:'.config('shopbonus.funding.max_top_up_minor')]]);
        $topUp = $this->funding->startTopUp($current->webshop(), (int) $data['amount_minor'], $current->membership()->user);

        return response()->json(['data' => self::topUpView($topUp)], 201);
    }

    public function showTopUp(CurrentWorkspace $current, string $topUp): JsonResponse
    {
        $t = FundingTopUp::where('public_id', $topUp)->where('webshop_id', $current->webshop()->id)->first() ?? throw new ApiException('Top-up not found.', 404, 'not_found');

        return response()->json(['data' => self::topUpView($t)]);
    }

    public function invoices(CurrentWorkspace $current): JsonResponse
    {
        return response()->json(['data' => FundingInvoice::where('webshop_id', $current->webshop()->id)->orderByDesc('period')->get()->map(fn (FundingInvoice $i) => self::invoiceView($i))->all()]);
    }

    /** @return array<string, mixed> */
    public static function invoiceView(FundingInvoice $i): array
    {
        return ['id' => $i->public_id, 'period' => $i->period, 'reward_minor' => $i->reward_minor, 'fee_minor' => $i->fee_minor, 'total_minor' => $i->reward_minor + $i->fee_minor,
            'lines' => $i->lines, 'status' => $i->status, 'moneybird' => $i->external_ref === null ? 'not_connected' : $i->external_ref];
    }

    /** @return array<string, mixed> */
    private static function topUpView(FundingTopUp $t): array
    {
        return ['id' => $t->public_id, 'state' => $t->state, 'amount_minor' => $t->amount_minor, 'currency' => $t->currency,
            'checkout_url' => $t->state === 'pending' ? $t->checkout_url : null, 'paid_at' => $t->paid_at?->toIso8601String()];
    }
}
