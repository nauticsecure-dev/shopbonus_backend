<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReconciliationFlag;
use App\Services\Admin\WalletOversight;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WalletController extends Controller
{
    public function index(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::enum(WalletKind::class)],
            'unit' => ['sometimes', Rule::enum(WalletUnit::class)],
            'consumer_id' => ['sometimes', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Wallet::query()->when($data['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($data['unit'] ?? null, fn ($q, $v) => $q->where('unit', $v))
            ->when($data['consumer_id'] ?? null, fn ($q, $v) => $q->where('owner_type', 'user')->where('owner_id', User::where('public_id', Str::lower($v))->value('id') ?? 0))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 25);
        $audit->recordSafely('admin.wallets_viewed', category: AuditCategory::Financial);

        return $this->page($page, fn (Wallet $w) => ['id' => $w->public_id, 'kind' => $w->kind->value, 'unit' => $w->unit->value,
            'available' => $w->available, 'reserved' => $w->reserved, 'pending' => $w->pending]);
    }

    public function ledger(Request $request, string $wallet, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $model = Wallet::where('public_id', Str::lower($wallet))->first() ?? throw new NotFoundHttpException;
        $audit->recordSafely('admin.wallet_ledger_viewed', category: AuditCategory::Financial, resource: $model);
        $page = $model->entries()->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return $this->page($page, fn (WalletLedgerEntry $e) => ['id' => $e->public_id, 'type' => $e->type->value,
            'unit' => $model->unit->value, 'amount' => $e->amount, 'available_delta' => $e->available_delta,
            'reserved_delta' => $e->reserved_delta, 'available_after' => $e->available_after, 'reserved_after' => $e->reserved_after,
            'reason' => $e->reason, 'origin' => $e->origin, 'source_type' => $e->source_type, 'created_at' => $e->created_at->toIso8601String()]);
    }

    public function flags(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'resolved'])], 'wallet_id' => ['sometimes', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = WalletReconciliationFlag::query()->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['wallet_id'] ?? null, fn ($q, $v) => $q->where('wallet_id', Wallet::where('public_id', Str::lower($v))->value('id') ?? 0))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return $this->page($page, fn (WalletReconciliationFlag $f) => $this->flag($f));
    }

    public function resolveFlag(Request $request, string $flag, WalletOversight $oversight): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $model = WalletReconciliationFlag::where('public_id', Str::lower($flag))->first() ?? throw new NotFoundHttpException;

        return response()->json(['data' => $this->flag($oversight->resolve($model, $request->user(), $data['reason']))]);
    }

    /** @return array<string, mixed> */
    private function flag(WalletReconciliationFlag $flag): array
    {
        $wallet = Wallet::findOrFail($flag->wallet_id);

        return ['id' => $flag->public_id, 'wallet_id' => $wallet->public_id, 'unit' => $wallet->unit->value,
            'reason' => $flag->reason, 'amount' => $flag->amount, 'status' => $flag->status,
            'resolved_at' => $flag->resolved_at?->toIso8601String(), 'resolution_reason' => $flag->resolution_reason,
            'resolved_by' => $flag->resolved_by === null ? null : User::find($flag->resolved_by)?->public_id];
    }

    /** @param LengthAwarePaginator<int, mixed> $page */
    private function page(LengthAwarePaginator $page, callable $present): JsonResponse
    {
        return response()->json(['data' => $page->getCollection()->map($present)->all(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]]);
    }
}
