<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Consumer\CheckoutController;
use App\Http\Controllers\Api\V1\Consumer\DisputeController as ConsumerDisputeController;
use App\Http\Controllers\Controller;
use App\Models\VoucherCheckout;
use App\Models\VoucherDispute;
use App\Services\Audit\AuditLogger;
use App\Services\Disputes\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Super Admin dispute review and refunds (Trello #20, #21). Mutations need
 * step-up; every compensation is applied once and audited.
 */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'under_review', 'waiting_for_merchant', 'resolved', 'rejected'])]]);

        return response()->json(['data' => VoucherDispute::when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))->orderByDesc('id')->limit(200)->get()
            ->map(fn (VoucherDispute $d) => ConsumerDisputeController::present($d))->all()]);
    }

    public function show(string $dispute): JsonResponse
    {
        return response()->json(['data' => ConsumerDisputeController::present($this->find($dispute), withTimeline: true)]);
    }

    /** Private screenshot download for review; audited, never cached, never rendered inline. */
    public function evidence(string $dispute, string $file): Response
    {
        $d = $this->find($dispute);
        [$meta, $bytes] = $this->disputes->evidence($d, $file) ?? throw new ApiException('Screenshot not found.', 404, 'not_found');
        $this->audit->record('admin.dispute_evidence_downloaded', category: AuditCategory::Admin, resource: $d, webshopId: $d->webshop_id, metadata: ['file_id' => $file]);

        return response($bytes, 200, [
            'Content-Type' => $meta['mime'],
            'Content-Disposition' => 'attachment; filename="'.$meta['name'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function status(Request $request, string $dispute): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['under_review', 'waiting_for_merchant'])], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => ConsumerDisputeController::present($this->disputes->setStatus($this->find($dispute), $data['status'], $request->user()->id, $data['note'] ?? null), withTimeline: true)]);
    }

    public function resolve(Request $request, string $dispute): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['resolved', 'rejected'])],
            'compensation' => ['required', Rule::in(['none', 'wallet_credit', 'replacement', 'refund'])],
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $d = $this->disputes->resolve($this->find($dispute), $data['outcome'], $data['compensation'], $data['amount_minor'] ?? null, $data['note'], $request->user()->id);

        return response()->json(['data' => ConsumerDisputeController::present($d, withTimeline: true)]);
    }

    public function checkouts(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['sometimes', Rule::in(['pending_payment', 'completed', 'failed', 'expired', 'paid_unfulfilled', 'refunded'])]]);

        return response()->json(['data' => VoucherCheckout::when(isset($data['state']), fn ($q) => $q->where('state', $data['state']))->orderByDesc('id')->limit(200)->get()
            ->map(fn (VoucherCheckout $c) => CheckoutController::present($c))->all()]);
    }

    public function refundCheckout(Request $request, string $checkout): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        $c = VoucherCheckout::where('public_id', $checkout)->first() ?? throw new ApiException('Checkout not found.', 404, 'not_found');

        return response()->json(['data' => CheckoutController::present($this->disputes->refundUnfulfilled($c, $request->user()->id, $data['note']))]);
    }

    private function find(string $id): VoucherDispute
    {
        return VoucherDispute::where('public_id', $id)->first() ?? throw new ApiException('Report not found.', 404, 'not_found');
    }
}
