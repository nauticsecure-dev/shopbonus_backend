<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminApprovalRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Admin\WalletAdjustments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WalletAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = AdminApprovalRequest::query()->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $this->present($r))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request, WalletAdjustments $adjustments): JsonResponse
    {
        $data = $request->validate(['wallet_id' => ['required', 'ulid'], 'consumer_id' => ['required', 'ulid'], 'source_entry_id' => ['required', 'ulid'],
            'amount' => ['required', 'integer', 'not_in:0', 'between:-2147483647,2147483647'], 'reason' => ['required', 'string', 'min:5', 'max:500'],
            'verification_note' => ['required', 'string', 'min:5', 'max:500'], 'request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,64}$/']]);
        $wallet = Wallet::where('public_id', Str::lower($data['wallet_id']))->first() ?? throw new NotFoundHttpException;
        $consumer = User::where('public_id', Str::lower($data['consumer_id']))->first() ?? throw new NotFoundHttpException;
        $source = WalletLedgerEntry::where('public_id', Str::lower($data['source_entry_id']))->first() ?? throw new NotFoundHttpException;
        $approval = $adjustments->request($request->user(), $wallet, $consumer, $source, $data['amount'], $data['reason'], $data['verification_note'], $data['request_key']);

        return response()->json(['data' => $this->present($approval)], 201);
    }

    public function review(Request $request, string $approval, WalletAdjustments $adjustments): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['required', 'string', 'min:5', 'max:500']]);
        $model = AdminApprovalRequest::where('public_id', Str::lower($approval))->first() ?? throw new NotFoundHttpException;

        return response()->json(['data' => $this->present($adjustments->review($model, $request->user(), $data['decision'], $data['note']))]);
    }

    /** @return array<string, mixed> */
    private function present(AdminApprovalRequest $r): array
    {
        return ['id' => $r->public_id, 'wallet_id' => Wallet::findOrFail($r->wallet_id)->public_id, 'consumer_id' => User::findOrFail($r->consumer_id)->public_id,
            'source_entry_id' => WalletLedgerEntry::findOrFail($r->source_entry_id)->public_id, 'amount' => $r->amount, 'unit' => $r->unit, 'reason' => $r->reason,
            'verification_note' => $r->verification_note, 'policy_version' => $r->policy_version, 'status' => $r->status,
            'requested_by' => User::findOrFail($r->requested_by)->public_id, 'reviewed_by' => $r->reviewed_by === null ? null : User::findOrFail($r->reviewed_by)->public_id,
            'posted_entry_id' => $r->posted_entry_id === null ? null : WalletLedgerEntry::findOrFail($r->posted_entry_id)->public_id,
            'review_note' => $r->review_note, 'reviewed_at' => $r->reviewed_at?->toIso8601String()];
    }
}
