<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\QaComponent;
use App\Models\QaEndpoint;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaInventorySnapshot;
use App\Models\QaSchedule;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Qa\QaFindings;
use App\Services\Qa\QaInventory;
use App\Services\Qa\QaRuns;
use App\Services\Qa\QaStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\Yaml\Yaml;

/**
 * Quality Center API (Trello #37). Super Admin configures schedules; QA testers
 * (and Super Admin) scan, import runs, approve tests, record evidence and
 * findings and retest; developers only see findings assigned to them and the
 * evidence of those findings, and record fix commits.
 */
class QaController extends Controller
{
    private const FILTERS = ['new', 'changed', 'removed', 'undocumented', 'untested', 'failed', 'blocked', 'stale', 'unreferenced', 'passed'];

    public function __construct(private readonly QaStatus $status, private readonly AuditLogger $audit) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->status->summary()]);
    }

    public function endpoints(Request $request): JsonResponse
    {
        $data = $request->validate(['filter' => ['sometimes', Rule::in(self::FILTERS)], 'q' => ['sometimes', 'string', 'max:100'],
            'access' => ['sometimes', Rule::in(['public', 'guest', 'authenticated', 'verified', 'admin', 'integration'])], ...$this->paging()]);
        $filter = $data['filter'] ?? null;
        $snapshot = QaInventorySnapshot::latest('id')->first();
        $rows = QaEndpoint::query()->when($filter === 'removed', fn ($q) => $q->whereNotNull('removed_at'), fn ($q) => $q->whereNull('removed_at'))
            ->when($data['access'] ?? null, fn ($q, $v) => $q->where('access', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('path', 'like', '%'.addcslashes($v, '%_\\').'%')->orWhere('action', 'like', '%'.addcslashes($v, '%_\\').'%')))
            ->when($filter === 'undocumented', fn ($q) => $q->where('documented', false))
            ->orderBy('path')->orderBy('method')->get();
        if (in_array($filter, ['new', 'changed'], true)) {
            $keys = $snapshot?->diff['endpoints'][$filter === 'new' ? 'added' : 'changed'] ?? [];
            $rows = $rows->filter(fn ($e) => in_array($e->method.' '.$e->path, $keys, true));
        }
        if ($filter === 'unreferenced') {
            $rows = $rows->filter(fn ($e) => QaStatus::unreferenced($e));
        }
        $status = $this->status->forEndpoints($rows);
        $want = ['untested' => 'untested', 'failed' => 'fail', 'blocked' => 'blocked', 'stale' => 'stale', 'passed' => 'pass'][$filter] ?? null;
        if ($want !== null) {
            $rows = $rows->filter(fn ($e) => $status[$e->id]['status'] === $want);
        }

        return $this->page($rows->values(), $data, fn ($e) => $this->endpoint($e, $status[$e->id]));
    }

    public function showEndpoint(string $endpoint): JsonResponse
    {
        $e = QaEndpoint::where('public_id', $endpoint)->firstOrFail();
        $tests = QaTestCase::whereIn('id', DB::table('qa_test_coverage')->where('target_type', 'endpoint')->where('target_id', $e->id)->pluck('test_case_id'))->orderBy('key')->get();
        $latest = QaTestResult::whereIn('id', QaTestResult::selectRaw('max(id)')->whereIn('test_case_id', $tests->pluck('id'))->groupBy('test_case_id'))->get()->keyBy('test_case_id');

        return response()->json(['data' => $this->endpoint($e, $this->status->forEndpoints(new Collection([$e]))[$e->id]) + [
            'test_cases' => $tests->map(fn (QaTestCase $t) => $this->testCase($t) + ['latest_result' => ($r = $latest->get($t->id)) === null ? null : QaRuns::resultArray($r) + ['run_id' => QaTestRun::find($r->run_id)?->public_id, 'created_at' => $r->created_at?->toIso8601String()]])->all(),
        ]]);
    }

    public function components(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['sometimes', Rule::in(QaComponent::KINDS)], 'removed' => ['sometimes', 'boolean'], ...$this->paging()]);
        $rows = QaComponent::query()->when($data['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when((bool) ($data['removed'] ?? false), fn ($q) => $q->whereNotNull('removed_at'), fn ($q) => $q->whereNull('removed_at'))->orderBy('kind')->orderBy('name')->get();
        $covered = DB::table('qa_test_coverage as c')->join('qa_test_cases as t', 't.id', '=', 'c.test_case_id')->where('t.status', 'approved')
            ->where('c.target_type', 'component')->pluck('c.target_id')->flip();

        return $this->page($rows, $data, fn (QaComponent $c) => ['id' => $c->public_id, 'kind' => $c->kind, 'name' => $c->name, 'tested' => $covered->has($c->id),
            'first_seen_sha' => $c->first_seen_sha, 'changed_sha' => $c->changed_sha, 'changed_at' => $c->changed_at->toIso8601String(), 'removed_at' => $c->removed_at?->toIso8601String()]);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $data = $request->validate($this->paging());

        return $this->page(QaInventorySnapshot::latest('id')->limit(500)->get(), $data, fn ($s) => $this->snapshot($s));
    }

    public function scan(Request $request, QaInventory $inventory): JsonResponse
    {
        $data = $request->validate(['git_sha' => ['required', 'string', 'regex:/^[0-9a-f]{7,64}$/']]);

        return response()->json(['data' => $this->snapshot($inventory->scan($data['git_sha'], $request->user()->id))], 201);
    }

    /** The frontend build reports which routes it calls; others become UNREFERENCED — REVIEW (never deleted). */
    public function frontendUsage(Request $request): JsonResponse
    {
        $data = $request->validate(['routes' => ['present', 'array', 'max:2000'], 'routes.*' => ['string', 'max:300', 'regex:#^(GET|POST|PUT|PATCH|DELETE) /api/v1/\S*$#']]);
        $listed = array_flip($data['routes']);
        $known = 0;
        DB::transaction(function () use ($listed, &$known) {
            foreach (QaEndpoint::whereNull('removed_at')->lockForUpdate()->get() as $e) {
                $hit = isset($listed[$e->method.' '.$e->path]);
                $known += $hit ? 1 : 0;
                $e->forceFill(['frontend_referenced' => $hit])->save();
            }
        });
        $unknown = count($listed) - $known;
        $this->audit->record('qa.frontend_usage_recorded', category: AuditCategory::Admin, actorUserId: $request->user()->id, metadata: ['routes' => count($listed), 'unknown' => $unknown]);
        $all = QaEndpoint::whereNull('removed_at')->get();

        return response()->json(['data' => ['referenced' => $known, 'unknown_routes' => $unknown,
            'unreferenced_review' => $all->filter(fn ($e) => QaStatus::unreferenced($e))->count(),
            'external_clients' => $all->filter(fn ($e) => $e->frontend_referenced === false && QaStatus::externalClient($e))->count()]]);
    }

    public function testCases(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['draft', 'approved', 'retired'])], 'source' => ['sometimes', Rule::in(QaTestCase::SOURCES)],
            'q' => ['sometimes', 'string', 'max:100'], ...$this->paging()]);
        $q = QaTestCase::query()->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($data['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where('key', 'like', '%'.addcslashes($v, '%_\\').'%'))->orderBy('key');

        return $this->paginate($q, $data, fn ($t) => $this->testCase($t));
    }

    public function storeTestCase(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:250', 'regex:/^[\x21-\x7E][\x20-\x7E]*$/', Rule::unique('qa_test_cases', 'key')],
            'title' => ['required', 'string', 'max:250'], 'scenario' => ['nullable', 'string', 'regex:/^(0[1-9]|1\d|2[0-5]|E2E-(0[1-9]|10))$/'],
            'source' => ['required', Rule::in(['phpunit', 'postman', 'browser', 'manual'])]]);
        $case = QaTestCase::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);
        $this->audit->record('qa.test_case_created', category: AuditCategory::Admin, actorUserId: $request->user()->id, metadata: ['test_case' => $case->public_id, 'key' => $case->key]);

        return response()->json(['data' => $this->testCase($case)], 201);
    }

    /** Human approval (AI-proposed and imported cases start as drafts and do not count until approved). */
    public function approveTestCases(Request $request): JsonResponse
    {
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:1000'], 'keys.*' => ['string', 'max:250', 'distinct']]);
        $cases = QaTestCase::whereIn('key', $data['keys'])->where('status', 'draft')->get();
        QaTestCase::whereIn('id', $cases->pluck('id'))->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->audit->record('qa.test_cases_approved', category: AuditCategory::Admin, actorUserId: $request->user()->id, metadata: ['count' => $cases->count()]);

        return response()->json(['data' => ['approved' => $cases->count(), 'skipped' => count($data['keys']) - $cases->count()]]);
    }

    public function setCoverage(Request $request, string $testCase): JsonResponse
    {
        $case = QaTestCase::where('public_id', $testCase)->firstOrFail();
        $data = $request->validate(['endpoints' => ['present', 'array', 'max:200'], 'endpoints.*' => ['string', 'distinct'],
            'components' => ['sometimes', 'array', 'max:200'], 'components.*' => ['string', 'distinct']]);
        $endpoints = QaEndpoint::whereIn('public_id', $data['endpoints'])->whereNull('removed_at')->pluck('id');
        $components = QaComponent::whereIn('public_id', $data['components'] ?? [])->whereNull('removed_at')->pluck('id');
        if ($endpoints->count() !== count($data['endpoints']) || $components->count() !== count($data['components'] ?? [])) {
            throw new ApiException('Unknown or removed endpoint or component.', 422, 'validation_failed', ['errors' => ['endpoints' => ['Every id must be a current endpoint or component.']]]);
        }
        DB::transaction(function () use ($case, $endpoints, $components) {
            DB::table('qa_test_coverage')->where('test_case_id', $case->id)->delete();
            DB::table('qa_test_coverage')->insert(array_merge(
                $endpoints->map(fn ($id) => ['test_case_id' => $case->id, 'target_type' => 'endpoint', 'target_id' => $id, 'created_at' => now(), 'updated_at' => now()])->all(),
                $components->map(fn ($id) => ['test_case_id' => $case->id, 'target_type' => 'component', 'target_id' => $id, 'created_at' => now(), 'updated_at' => now()])->all()));
        });
        $this->audit->record('qa.coverage_updated', category: AuditCategory::Admin, actorUserId: $request->user()->id,
            metadata: ['test_case' => $case->public_id, 'endpoints' => $endpoints->count(), 'components' => $components->count()]);

        return response()->json(['data' => $this->testCase($case) + ['endpoints' => $data['endpoints'], 'components' => $data['components'] ?? []]]);
    }

    public function importCoverage(Request $request, QaRuns $runs): JsonResponse
    {
        $data = $request->validate(['map' => ['required', 'array', 'max:5000'], 'map.*' => ['array', 'max:200'], 'map.*.*' => ['string', 'max:300']]);

        return response()->json(['data' => $runs->importCoverage($data['map'], $request->user()->id)]);
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate(['environment' => ['sometimes', Rule::in(QaSchedule::ENVIRONMENTS)], 'source' => ['sometimes', Rule::in(['junit', 'probe'])], ...$this->paging()]);
        $q = QaTestRun::query()->when($data['environment'] ?? null, fn ($q, $v) => $q->where('environment', $v))
            ->when($data['source'] ?? null, fn ($q, $v) => $q->where('source', $v))->latest('id');

        return $this->paginate($q, $data, fn ($r) => QaRuns::runArray($r));
    }

    public function showRun(Request $request, string $run): JsonResponse
    {
        $r = QaTestRun::where('public_id', $run)->firstOrFail();
        $data = $request->validate(['status' => ['sometimes', Rule::in(QaTestResult::STATUSES)], ...$this->paging()]);
        $results = QaTestResult::where('run_id', $r->id)->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderBy('id');
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? 25);
        $total = (clone $results)->count();

        return response()->json(['data' => QaRuns::runArray($r) + [
            'results' => $results->forPage($page, $size)->get()->map(fn ($x) => QaRuns::resultArray($x))->all(),
            'evidence' => $r->evidence()->orderBy('id')->get()->map(fn ($e) => $this->evidence($e))->all(),
        ], 'meta' => ['current_page' => $page, 'total' => $total, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))]]);
    }

    public function storeRun(Request $request, QaRuns $runs): JsonResponse
    {
        $data = $request->validate(['report' => ['required', 'file', 'max:'.(int) config('qa.max_report_kb'), 'mimetypes:application/xml,text/xml,text/plain'],
            'suite' => ['required', Rule::in(QaSchedule::SUITES)], 'environment' => ['required', Rule::in(QaSchedule::ENVIRONMENTS)],
            'git_sha' => ['required', 'string', 'regex:/^[0-9a-f]{7,64}$/'], 'mocked' => ['sometimes', 'boolean'],
            'source' => ['sometimes', Rule::in(['phpunit', 'postman', 'browser'])], 'schedule_id' => ['sometimes', 'string'],
            'executed_at' => ['sometimes', 'date', 'before_or_equal:now', 'after:-90 days']]);
        $schedule = isset($data['schedule_id']) ? QaSchedule::where('public_id', $data['schedule_id'])->firstOrFail() : null;
        $run = $runs->importJunit((string) $data['report']->get(), ['suite' => $data['suite'], 'environment' => $data['environment'], 'git_sha' => $data['git_sha'],
            'mocked' => (bool) ($data['mocked'] ?? false), 'source' => $data['source'] ?? 'phpunit', 'schedule_id' => $schedule?->id, 'executed_at' => $data['executed_at'] ?? null], $request->user()->id);
        $schedule?->forceFill(['last_run_at' => now()])->save();

        return response()->json(['data' => QaRuns::runArray($run)], 201);
    }

    public function export(Request $request, QaRuns $runs, string $run): Response
    {
        $r = QaTestRun::where('public_id', $run)->firstOrFail();
        $data = $request->validate(['format' => ['required', Rule::in(['json', 'junit', 'html'])]]);
        [$body, $mime, $name] = $runs->export($r, $data['format']);
        $this->audit->record('qa.run_exported', category: AuditCategory::Admin, actorUserId: $request->user()->id, metadata: ['run' => $r->public_id, 'format' => $data['format']]);

        return response($body, 200, ['Content-Type' => $mime.'; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function storeEvidence(Request $request, QaRuns $runs, string $run): JsonResponse
    {
        $r = QaTestRun::where('public_id', $run)->firstOrFail();
        $data = $request->validate(['file' => ['required', 'file', 'max:'.(int) config('qa.max_evidence_kb')], 'result_id' => ['sometimes', 'string'],
            'request_id' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/']]);
        $result = isset($data['result_id']) ? QaTestResult::where('run_id', $r->id)->where('public_id', $data['result_id'])->first()
            ?? throw new ApiException('That result is not part of this run.', 422, 'validation_failed', ['errors' => ['result_id' => ['That result is not part of this run.']]]) : null;
        $evidence = $runs->storeEvidence($r, $data['file'], $result, $data['request_id'] ?? null, $request->user()->id);

        return response()->json(['data' => $this->evidence($evidence)], $evidence->wasRecentlyCreated ? 201 : 200);
    }

    public function downloadEvidence(Request $request, QaRuns $runs, string $evidence): Response
    {
        $e = QaEvidence::where('public_id', $evidence)->firstOrFail();
        $user = $request->user();
        if (! $this->canSeeAll($user) && ! QaFinding::where('owner_id', $user->id)->where(fn ($q) => $q->where('run_id', $e->run_id)->orWhere('retest_run_id', $e->run_id))->exists()) {
            throw new ApiException('Evidence not found.', 404, 'not_found');
        }
        $bytes = $runs->evidenceBytes($e);
        $this->audit->record('qa.evidence_downloaded', category: AuditCategory::Admin, actorUserId: $user->id, metadata: ['evidence' => $e->public_id]);

        return response($bytes, 200, ['Content-Type' => $e->mime, 'Content-Disposition' => 'attachment; filename="'.$e->name.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function findings(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(QaFinding::STATUSES)], 'severity' => ['sometimes', Rule::in(QaFinding::SEVERITIES)], ...$this->paging()]);
        $q = $this->visibleFindings($request->user())->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))->orderBy('severity')->latest('id');

        return $this->paginate($q, $data, fn ($f) => $this->finding($f));
    }

    public function showFinding(Request $request, QaFindings $findings, string $finding): JsonResponse
    {
        $f = $this->findFinding($request->user(), $finding);
        $evidence = QaEvidence::whereIn('run_id', array_filter([$f->run_id, $f->retest_run_id]) ?: [0])->orderBy('id')->get();

        return response()->json(['data' => $this->finding($f, true) + ['evidence' => $evidence->map(fn ($e) => $this->evidence($e))->all(), 'regression' => $findings->regression($f)]]);
    }

    public function storeFinding(Request $request, QaFindings $findings): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'severity' => ['required', Rule::in(QaFinding::SEVERITIES)],
            'reproduction' => ['nullable', 'string', 'max:5000'], 'affected_routes' => ['sometimes', 'array', 'max:50'], 'affected_routes.*' => ['string', 'max:300'],
            'test_keys' => ['sometimes', 'array', 'max:50'], 'test_keys.*' => ['string', 'max:250'], 'run_id' => ['sometimes', 'string'], 'owner_id' => ['sometimes', 'string']]);
        $run = isset($data['run_id']) ? QaTestRun::where('public_id', $data['run_id'])->first()
            ?? throw new ApiException('Unknown run.', 422, 'validation_failed', ['errors' => ['run_id' => ['Unknown run.']]]) : null;
        $owner = isset($data['owner_id']) ? User::where('public_id', $data['owner_id'])->first()
            ?? throw new ApiException('Unknown user.', 422, 'validation_failed', ['errors' => ['owner_id' => ['Unknown user.']]]) : null;
        $f = $findings->create(['title' => $data['title'], 'severity' => $data['severity'], 'reproduction' => $data['reproduction'] ?? null,
            'affected_routes' => $data['affected_routes'] ?? [], 'test_keys' => $data['test_keys'] ?? [], 'run' => $run, 'owner' => $owner], $request->user());

        return response()->json(['data' => $this->finding($f, true)], 201);
    }

    public function fixFinding(Request $request, QaFindings $findings, string $finding): JsonResponse
    {
        $f = $this->findFinding($request->user(), $finding);
        $data = $request->validate(['fix_commit' => ['required', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/']]);

        return response()->json(['data' => $this->finding($findings->fix($f, $data['fix_commit'], $request->user()), true)]);
    }

    public function retestFinding(Request $request, QaFindings $findings, string $finding): JsonResponse
    {
        $f = $this->findFinding($request->user(), $finding);
        $data = $request->validate(['run_id' => ['required', 'string'], 'passed' => ['sometimes', 'boolean'], 'build_includes_fix' => ['sometimes', 'boolean']]);
        $run = QaTestRun::where('public_id', $data['run_id'])->first() ?? throw new ApiException('Unknown run.', 422, 'validation_failed', ['errors' => ['run_id' => ['Unknown run.']]]);

        return response()->json(['data' => $this->finding($findings->retest($f, $run, isset($data['passed']) ? (bool) $data['passed'] : null, $request->user(),
            (bool) ($data['build_includes_fix'] ?? false)), true)]);
    }

    public function analyzeFinding(Request $request, QaFindings $findings, string $finding): JsonResponse
    {
        $f = $this->findFinding($request->user(), $finding);

        return response()->json(['data' => $this->finding($findings->analyze($f, $request->user()), true)]);
    }

    public function schedules(): JsonResponse
    {
        return response()->json(['data' => QaSchedule::orderBy('name')->get()->map(fn ($s) => $this->schedule($s))->all()]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'suite' => ['required', Rule::in(QaSchedule::SUITES)],
            'environment' => ['required', Rule::in(QaSchedule::ENVIRONMENTS)], 'trigger' => ['required', Rule::in(QaSchedule::TRIGGERS)],
            'timeout_minutes' => ['sometimes', 'integer', 'min:1', 'max:720']]);
        $this->guardSchedule($data['suite'], $data['environment']);
        $s = QaSchedule::create($data + ['created_by' => $request->user()->id]);
        $this->audit->record('qa.schedule_created', category: AuditCategory::Admin, actorUserId: $request->user()->id, metadata: ['schedule' => $s->public_id] + $data);

        return response()->json(['data' => $this->schedule($s)], 201);
    }

    public function updateSchedule(Request $request, string $schedule): JsonResponse
    {
        $s = QaSchedule::where('public_id', $schedule)->firstOrFail();
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'suite' => ['sometimes', Rule::in(QaSchedule::SUITES)],
            'trigger' => ['sometimes', Rule::in(QaSchedule::TRIGGERS)], 'timeout_minutes' => ['sometimes', 'integer', 'min:1', 'max:720'], 'active' => ['sometimes', 'boolean']]);
        $this->guardSchedule($data['suite'] ?? $s->suite, $s->environment);
        $s->fill($data)->save();
        $this->audit->record('qa.schedule_updated', category: AuditCategory::Admin, actorUserId: $request->user()->id, metadata: ['schedule' => $s->public_id] + $data);

        return response()->json(['data' => $this->schedule($s)]);
    }

    /** The actual versioned OpenAPI contract for the self-hosted Swagger/Stoplight portal; never public. */
    public function openapi(Request $request): JsonResponse
    {
        $this->audit->record('qa.openapi_viewed', category: AuditCategory::Admin, actorUserId: $request->user()->id);

        return response()->json(Yaml::parseFile(base_path('docs/api/openapi.yaml')), 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'],
            JSON_UNESCAPED_SLASHES);
    }

    /** No financial or destructive production runs: production only gets the read-only safe suite. */
    private function guardSchedule(string $suite, string $environment): void
    {
        if ($environment === 'production' && $suite !== 'safe') {
            throw new ApiException('Production schedules can only run the read-only safe suite.', 422, 'validation_failed', ['errors' => ['suite' => ['Production schedules can only run the read-only safe suite.']]]);
        }
    }

    private function canSeeAll(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPlatformRole(PlatformRole::QaTester);
    }

    /** @return Builder<QaFinding> */
    private function visibleFindings(User $user): Builder
    {
        return QaFinding::query()->when(! $this->canSeeAll($user), fn ($q) => $q->where('owner_id', $user->id));
    }

    private function findFinding(User $user, string $id): QaFinding
    {
        return $this->visibleFindings($user)->where('public_id', $id)->first() ?? throw new ApiException('Finding not found.', 404, 'not_found');
    }

    /** @return array<string, list<string>> */
    private function paging(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Support\Collection<int, TModel>  $rows
     * @param  array<string, mixed>  $data
     */
    private function page(\Illuminate\Support\Collection $rows, array $data, callable $map): JsonResponse
    {
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? 25);

        return response()->json(['data' => $rows->forPage($page, $size)->map($map)->values()->all(), 'meta' => ['current_page' => $page, 'total' => $rows->count(),
            'per_page' => $size, 'last_page' => max(1, (int) ceil($rows->count() / $size))]]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @param  array<string, mixed>  $data
     */
    private function paginate(Builder $q, array $data, callable $map): JsonResponse
    {
        $p = $q->paginate((int) ($data['per_page'] ?? 25), page: (int) ($data['page'] ?? 1));

        return response()->json(['data' => collect($p->items())->map($map)->values()->all(), 'meta' => ['current_page' => $p->currentPage(), 'total' => $p->total(),
            'per_page' => $p->perPage(), 'last_page' => $p->lastPage()]]);
    }

    /**
     * @param  array{status: string, mocked: bool, tests: int, last_run_at: string|null, provenance: string|null, evidence_missing: bool}  $status
     * @return array<string, mixed>
     */
    private function endpoint(QaEndpoint $e, array $status): array
    {
        return ['id' => $e->public_id, 'method' => $e->method, 'path' => $e->path, 'name' => $e->name, 'handler' => $e->action, 'summary' => $e->summary,
            'access' => $e->access, 'permission' => $e->permission, 'scope' => $e->scope, 'documented' => $e->documented,
            'status' => $e->removed_at !== null ? 'removed' : $status['status'], 'mocked' => $status['mocked'], 'tests' => $status['tests'], 'last_run_at' => $status['last_run_at'], 'provenance' => $status['provenance'], 'evidence_missing' => $status['evidence_missing'],
            'frontend_referenced' => $e->frontend_referenced, 'external_client' => QaStatus::externalClient($e),
            'review' => QaStatus::unreferenced($e) ? 'UNREFERENCED — REVIEW' : null,
            'first_seen_sha' => $e->first_seen_sha, 'changed_sha' => $e->changed_sha, 'changed_at' => $e->changed_at->toIso8601String(), 'removed_at' => $e->removed_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function snapshot(QaInventorySnapshot $s): array
    {
        return ['id' => $s->public_id, 'git_sha' => $s->git_sha, 'totals' => $s->totals,
            'diff' => ['endpoints' => $s->diff['endpoints'], 'components' => $s->diff['components'], 'undocumented' => $s->diff['undocumented']],
            'created_at' => $s->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function testCase(QaTestCase $t): array
    {
        return ['id' => $t->public_id, 'key' => $t->key, 'title' => $t->title, 'scenario' => $t->scenario, 'source' => $t->source, 'status' => $t->status,
            'version' => $t->version, 'approved_at' => $t->approved_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function evidence(QaEvidence $e): array
    {
        return ['id' => $e->public_id, 'name' => $e->name, 'mime' => $e->mime, 'size' => $e->size, 'sha256' => $e->sha256, 'request_id' => $e->request_id,
            'retain_until' => $e->retain_until->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function finding(QaFinding $f, bool $detail = false): array
    {
        $users = User::whereIn('id', array_filter([$f->owner_id, $f->fixed_by, $f->retested_by]))->pluck('public_id', 'id');
        $runs = QaTestRun::whereIn('id', array_filter([$f->run_id, $f->retest_run_id]))->pluck('public_id', 'id');

        return ['id' => $f->public_id, 'title' => $f->title, 'severity' => $f->severity, 'status' => $f->status,
            'affected_routes' => $f->affected_routes, 'test_keys' => $f->test_keys, 'run_id' => $runs[$f->run_id] ?? null,
            'owner_id' => $users[$f->owner_id] ?? null, 'fix_commit' => $f->fix_commit, 'fixed_by' => $users[$f->fixed_by] ?? null, 'fixed_at' => $f->fixed_at?->toIso8601String(),
            'retest_run_id' => $runs[$f->retest_run_id] ?? null, 'retested_by' => $users[$f->retested_by] ?? null, 'ai_status' => $f->ai_status,
            'created_at' => $f->created_at?->toIso8601String(), 'updated_at' => $f->updated_at?->toIso8601String()]
            + ($detail ? ['reproduction' => $f->reproduction, 'ai_analysis' => $f->ai_analysis === null ? null : $f->ai_analysis + ['advisory' => true]] : []);
    }

    /** @return array<string, mixed> */
    private function schedule(QaSchedule $s): array
    {
        return ['id' => $s->public_id, 'name' => $s->name, 'suite' => $s->suite, 'environment' => $s->environment, 'trigger' => $s->trigger,
            'timeout_minutes' => $s->timeout_minutes, 'active' => $s->active, 'last_run_at' => $s->last_run_at?->toIso8601String(),
            'executed_by' => $s->environment === 'production' && $s->suite === 'safe' ? 'scheduler' : 'ci_runner'];
    }
}
