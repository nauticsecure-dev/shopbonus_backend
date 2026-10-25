<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncidentDiagnostic;
use App\Models\IncidentDiagnosticResult;
use App\Models\IncidentDiagnosticReview;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Admin\Diagnostics\IncidentDiagnostics;
use App\Services\Admin\Diagnostics\OpenAiDiagnostics;
use App\Services\Admin\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IncidentDiagnosticController extends Controller
{
    public function index(Request $request, string $incident, OpenAiDiagnostics $provider): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $model = $this->incident($incident);
        $page = IncidentDiagnostic::where('incident_id', $model->id)->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (IncidentDiagnostic $r) => $this->present($r))->all(),
            'availability' => ['configured' => $provider->configured(), 'provider' => 'openai', 'effective_daily_request_limit' => app(PlatformSettings::class)->diagnosticDailyLimit()],
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request, string $incident, IncidentDiagnostics $diagnostics): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,64}$/']]);
        $run = $diagnostics->request($this->incident($incident), $request->user(), $data['request_key']);

        return response()->json(['data' => $this->present($run->fresh())], 202);
    }

    public function show(string $diagnostic): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($diagnostic))]);
    }

    public function review(Request $request, string $diagnostic, IncidentDiagnostics $diagnostics): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'dismissed'])], 'note' => ['required', 'string', 'min:5', 'max:500']]);
        $run = $this->find($diagnostic);
        $diagnostics->review($run, $request->user(), $data['decision'], $data['note']);

        return response()->json(['data' => $this->present($run)]);
    }

    /** @return array<string, mixed> */
    private function present(IncidentDiagnostic $run): array
    {
        $incident = SecurityIncident::findOrFail($run->incident_id);

        return ['id' => $run->public_id, 'incident_id' => $incident->public_id, 'status' => $run->status, 'provider' => 'openai', 'model' => $run->model,
            'context' => $run->context, 'context_hash' => $run->context_hash, 'context_stale' => ($run->context['evidence']['E5']['value'] ?? null) !== $incident->occurrences,
            'requested_by' => User::findOrFail($run->requested_by)->public_id, 'created_at' => $run->created_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(), 'failure_code' => $run->failure_code,
            'analysis' => IncidentDiagnosticResult::where('diagnostic_id', $run->id)->first()?->analysis,
            'human_review_required' => true, 'advisory_only' => true,
            'reviews' => IncidentDiagnosticReview::where('diagnostic_id', $run->id)->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->public_id, 'reviewed_by' => User::findOrFail($r->getAttribute('reviewed_by'))->public_id, 'decision' => $r->getAttribute('decision'),
                'note' => $r->getAttribute('note'), 'created_at' => $r->created_at->toIso8601String()])->all()];
    }

    private function incident(string $id): SecurityIncident
    {
        return SecurityIncident::where('public_id', Str::lower($id))->first() ?? throw new NotFoundHttpException;
    }

    private function find(string $id): IncidentDiagnostic
    {
        return IncidentDiagnostic::where('public_id', Str::lower($id))->first() ?? throw new NotFoundHttpException;
    }
}
