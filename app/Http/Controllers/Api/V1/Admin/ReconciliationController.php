<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FinancialReconciliationCheck;
use App\Models\FinancialReconciliationRun;
use App\Services\Audit\AuditLogger;
use App\Services\Reliability\FinancialReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReconciliationController extends Controller
{
    public function index(Request $request, FinancialReconciliation $service): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = FinancialReconciliationRun::orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $service->summary($r, false)), 'meta' => ['page' => $page->currentPage(), 'total' => $page->total()]], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(Request $request, FinancialReconciliation $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $run = DB::transaction(function () use ($data, $request, $service, $audit) {
            $run = $service->start('admin:'.$request->user()->id.':'.strtolower($data['request_key']));
            if ($run->wasRecentlyCreated) {
                $audit->record('finance.reconciliation_requested', category: AuditCategory::Financial, resource: $run);
            }

            return $run;
        }, 3);

        return response()->json(['data' => $service->summary($run)], 202, ['Cache-Control' => 'no-store']);
    }

    public function show(Request $request, string $run, FinancialReconciliation $service): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['matched', 'discrepancy', 'unknown'])]]);
        $model = FinancialReconciliationRun::where('public_id', strtolower($run))->firstOrFail();
        $page = FinancialReconciliationCheck::where('run_id', $model->id)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $service->summary($model), 'checks' => $page->getCollection()->map(fn ($c) => [
            'id' => $c->public_id, 'source_type' => $c->source_type, 'source_ref' => $c->source_ref, 'status' => $c->status, 'evidence' => $c->evidence, 'checked_at' => $c->created_at]),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total()],
            'corrections' => 'Use existing reviewed wallet adjustment, affiliate review and checkout/dispute workflows. This report never posts corrections.'], 200, ['Cache-Control' => 'no-store']);
    }

    public function resume(Request $request, string $run, FinancialReconciliation $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $model = FinancialReconciliationRun::where('public_id', strtolower($run))->firstOrFail();
        DB::transaction(function () use ($model, $service, $audit, $data) {
            $current = FinancialReconciliationRun::whereKey($model->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'failed') {
                throw ApiException::conflict('Only failed reconciliation runs can be resumed.', 'reconciliation_not_failed');
            }
            $service->resume($current);
            $audit->record('finance.reconciliation_resumed', category: AuditCategory::Financial, resource: $current, metadata: ['reason' => $data['reason']]);
        }, 3);

        return response()->json(['data' => $service->summary($model)], 202, ['Cache-Control' => 'no-store']);
    }
}
