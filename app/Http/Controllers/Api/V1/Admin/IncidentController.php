<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentOccurrence;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['new', 'investigating', 'resolved', 'ignored'])],
            'severity' => ['sometimes', Rule::in(['warning', 'error'])], 'service' => ['sometimes', 'string', 'max:100'],
            'method' => ['sometimes', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'request_category' => ['sometimes', Rule::in(['read', 'write'])], 'webshop_id' => ['sometimes', 'ulid'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'request_id' => ['sometimes', 'string', 'max:128'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = SecurityIncident::query();
        foreach (['status', 'severity', 'service'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        $query->when($data['method'] ?? null, fn ($q, $v) => $q->where('http_method', $v))
            ->when(($data['request_category'] ?? null) === 'read', fn ($q) => $q->whereIn('http_method', ['GET', 'HEAD']))
            ->when(($data['request_category'] ?? null) === 'write', fn ($q) => $q->whereIn('http_method', ['POST', 'PUT', 'PATCH', 'DELETE']))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('last_seen_at', '>=', $v.' 00:00:00'))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('first_seen_at', '<=', $v.' 23:59:59'));
        if (isset($data['webshop_id']) || isset($data['request_id'])) {
            $occurrences = SecurityIncidentOccurrence::select('incident_id')
                ->when($data['webshop_id'] ?? null, fn ($q, $v) => $q->where('webshop_id', Webshop::where('public_id', Str::lower($v))->value('id') ?? 0))
                ->when($data['request_id'] ?? null, fn ($q, $v) => $q->where('request_id', $v));
            $query->whereIn('id', $occurrences);
        }
        $page = $query->orderByDesc('last_seen_at')->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (SecurityIncident $i) => $this->present($i))->all(), 'meta' => [
            'current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $incident, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $model = $this->find($incident);
        $page = SecurityIncidentOccurrence::where('incident_id', $model->id)->orderByDesc('id')->paginate($data['per_page'] ?? 25);
        $audit->recordSafely('admin.incident_viewed', category: AuditCategory::Security, resource: $model);

        $history = AuditEvent::where('resource_type', SecurityIncident::class)->where('resource_id', (string) $model->id)
            ->where('action', 'admin.incident_updated')->orderByDesc('id')->limit(100)->get()->map(fn ($e) => [
                'request_id' => $e->request_id, 'actor_id' => $e->actor_user_id === null ? null : User::find($e->actor_user_id)?->public_id,
                'occurred_at' => $e->occurred_at->toIso8601String(), 'change' => $e->metadata])->all();

        return response()->json(['data' => $this->present($model), 'history' => $history, 'occurrences' => $page->getCollection()->map(fn (SecurityIncidentOccurrence $o) => [
            'id' => $o->public_id, 'request_id' => $o->request_id, 'actor_id' => $o->actor_user_id === null ? null : User::find($o->actor_user_id)?->public_id,
            'webshop_id' => $o->webshop_id === null ? null : Webshop::find($o->webshop_id)?->public_id, 'scope_public_id' => $o->scope_public_id,
            'error_code' => $o->error_code, 'monitoring_evidence' => $o->monitoring_evidence, 'occurred_at' => $o->occurred_at->toIso8601String()])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]]);
    }

    public function update(Request $request, string $incident, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'investigating', 'resolved', 'ignored'])],
            'assigned_to' => ['present', 'nullable', 'ulid'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $model = $this->find($incident);
        $assignee = ($data['assigned_to'] ?? null) === null ? null : User::where('public_id', Str::lower($data['assigned_to']))->first();
        if ($data['assigned_to'] !== null && ($assignee === null || $assignee->status !== UserStatus::Active || ! $assignee->isSuperAdmin())) {
            throw new ApiException('Assign incidents only to an active Super Admin.', 422, 'invalid_incident_assignee');
        }
        $model = DB::transaction(function () use ($model, $data, $assignee, $audit) {
            $model = SecurityIncident::whereKey($model->id)->lockForUpdate()->firstOrFail();
            $before = ['status' => $model->status, 'assigned_to' => $model->assigned_to === null ? null : User::find($model->assigned_to)?->public_id];
            $model->forceFill(['status' => $data['status'], 'assigned_to' => $assignee?->id])->save();
            $audit->record('admin.incident_updated', category: AuditCategory::Security, resource: $model,
                metadata: ['before' => $before, 'after' => ['status' => $model->status, 'assigned_to' => $assignee?->public_id], 'reason' => $data['reason']]);

            return $model;
        });

        return response()->json(['data' => $this->present($model)]);
    }

    /** @return array<string, mixed> */
    private function present(SecurityIncident $i): array
    {
        return ['id' => $i->public_id, 'category' => $i->category, 'severity' => $i->severity, 'service' => $i->service,
            'route' => $i->route, 'method' => $i->http_method, 'http_status' => $i->http_status, 'status' => $i->status,
            'assigned_to' => $i->assigned_to === null ? null : User::find($i->assigned_to)?->public_id, 'occurrences' => $i->occurrences,
            'first_seen_at' => $i->first_seen_at->toIso8601String(), 'last_seen_at' => $i->last_seen_at->toIso8601String()];
    }

    private function find(string $id): SecurityIncident
    {
        return SecurityIncident::where('public_id', Str::lower($id))->first() ?? throw new NotFoundHttpException;
    }
}
