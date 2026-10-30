<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Models\AffiliateTransaction;
use App\Models\FundingTopUp;
use App\Models\VoucherCheckout;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinancialOversightController extends Controller
{
    public function show(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['category' => ['required', Rule::in(['affiliate_mismatches', 'failed_issuance', 'payments', 'funding_payments', 'redemption_signals'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $size = $data['per_page'] ?? 25;
        $page = match ($data['category']) {
            'affiliate_mismatches' => AffiliateTransaction::whereNotNull('review_reason')->orderByDesc('id')->paginate($size)->through(fn (AffiliateTransaction $t) => [
                'id' => $t->public_id, 'network' => $t->network, 'status' => $t->status, 'network_status' => $t->network_status,
                'review_reason' => $t->review_reason, 'commission_minor' => $t->commission_minor, 'currency' => $t->currency, 'reward_status' => $t->reward_status]),
            'failed_issuance' => VoucherCheckout::whereIn('state', ['failed', 'paid_unfulfilled', 'refund_pending'])->orderByDesc('id')->paginate($size)->through(fn (VoucherCheckout $c) => [
                'id' => $c->public_id, 'state' => $c->state, 'failure_reason' => $c->failure_reason, 'price_minor' => $c->price_minor, 'currency' => $c->currency]),
            'payments' => VoucherCheckout::whereHas('payment')->with('payment')->orderByDesc('id')->paginate($size)->through(fn (VoucherCheckout $c) => [
                'id' => $c->public_id, 'state' => $c->state, 'provider' => $c->payment?->provider, 'provider_ref' => $c->payment?->provider_ref,
                'payment_status' => $c->payment?->status, 'amount_minor' => $c->payment?->amount_minor, 'currency' => $c->payment?->currency,
                'refunded_minor' => $c->payment?->refunded_minor, 'refund_ref' => $c->payment?->refund_ref,
                'test_mode' => $c->payment?->test_mode, 'verified_at' => $c->payment?->verified_at?->toIso8601String()]),
            'funding_payments' => FundingTopUp::orderByDesc('id')->paginate($size)->through(fn (FundingTopUp $t) => [
                'id' => $t->public_id, 'state' => $t->state, 'provider' => 'mollie', 'provider_ref' => $t->provider_ref,
                'amount_minor' => $t->amount_minor, 'currency' => $t->currency, 'paid_at' => $t->paid_at?->toIso8601String()]),
            'redemption_signals' => DB::table('audit_events')->join('vouchers', 'audit_events.resource_id', '=', DB::raw('CAST(vouchers.id AS CHAR)'))
                ->where('audit_events.resource_type', 'App\\Models\\Voucher')->where('audit_events.action', 'voucher.redemption_denied')
                ->select('vouchers.public_id')->selectRaw('COUNT(*) AS attempts, MAX(audit_events.occurred_at) AS last_attempt_at')
                ->groupBy('vouchers.public_id')->orderByDesc('last_attempt_at')->paginate($size)->through(fn ($r) => [
                    'id' => $r->public_id, 'denied_attempts' => (int) $r->attempts, 'last_attempt_at' => $r->last_attempt_at]),
            default => throw new \LogicException('Unexpected validated oversight category.'),
        };
        $audit->recordSafely('admin.financial_oversight_viewed', category: AuditCategory::Financial, metadata: ['category' => $data['category']]);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
            'definitions' => ['failed_issuance' => 'Failed or paid-unfulfilled voucher checkouts; failure_reason distinguishes payment and inventory failures.',
                'redemption_signals' => 'Denied redemption transitions recorded since B16c; signals for investigation, not confirmed fraud.',
                'related_workflows' => '/admin/affiliate/transactions, /admin/checkouts, /admin/disputes; no payouts or new provider calls.']]);
    }
}
