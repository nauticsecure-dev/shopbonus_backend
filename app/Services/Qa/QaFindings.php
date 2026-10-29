<?php

namespace App\Services\Qa;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\QaEndpoint;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Defect workflow (Trello #37 §6, §8, E2E-09): a QA user records a finding,
 * the assigned developer records the fix commit, and a different QA user
 * retests it with an imported run; the outcome is derived from that run's
 * results, not chosen by hand. Related failures share a fingerprint and are
 * grouped into one open finding.
 *
 * The AI analysis is advisory: it reads redacted results only, its output is
 * restricted to the routes, tests and evidence it was given, it is stored for
 * review and it never changes status, severity or any other record.
 */
class QaFindings
{
    private const PRIORITY_PATTERN = '#wallet|checkout|payment|funding|voucher|code|claim|ledger|finance|webhook|integration|workspaces/\{#';

    public function __construct(private readonly AuditLogger $audit, private readonly QaRuns $runs) {}

    /**
     * @param  array{title: string, severity: string, reproduction?: string|null, affected_routes?: list<string>, test_keys?: list<string>, run?: QaTestRun|null, owner?: User|null}  $data
     */
    public function create(array $data, User $actor): QaFinding
    {
        $routes = collect($data['affected_routes'] ?? [])->unique()->sort()->values()->all();
        $tests = collect($data['test_keys'] ?? [])->unique()->sort()->values()->all();
        $owner = $data['owner'] ?? null;
        if ($owner !== null && ! $owner->hasPlatformRole(PlatformRole::Developer) && ! $owner->isSuperAdmin()) {
            throw new ApiException('Findings can only be assigned to a developer.', 422, 'validation_failed', ['errors' => ['owner_id' => ['This user is not a developer.']]]);
        }
        $known = QaEndpoint::whereNull('removed_at')->get()->map(fn ($e) => $e->method.' '.$e->path)->all();
        if (($unknown = array_values(array_diff($routes, $known))) !== []) {
            throw new ApiException('Unknown routes.', 422, 'validation_failed', ['errors' => ['affected_routes' => ['Unknown: '.implode(', ', array_slice($unknown, 0, 5))]]]);
        }
        $fingerprint = hash('sha256', json_encode($routes === [] && $tests === [] ? [Str::lower(trim($data['title']))] : [$routes, $tests]));

        $finding = DB::transaction(function () use ($data, $routes, $tests, $owner, $actor, $fingerprint) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $open = QaFinding::where('fingerprint', $fingerprint)->whereIn('status', ['open', 'fixed', 'retest_failed'])->first();
            if ($open !== null) {
                throw ApiException::conflict('An unresolved finding already covers these routes and tests.', 'finding_exists', ['finding' => $open->public_id]);
            }

            return QaFinding::create(['fingerprint' => $fingerprint, 'title' => $data['title'], 'severity' => $data['severity'], 'status' => 'open',
                'reproduction' => $data['reproduction'] ?? null, 'affected_routes' => $routes, 'test_keys' => $tests,
                'run_id' => ($data['run'] ?? null)?->id, 'owner_id' => $owner?->id, 'created_by' => $actor->id]);
        });
        $this->audit->record('qa.finding_created', category: AuditCategory::Admin, actorUserId: $actor->id,
            metadata: ['finding' => $finding->public_id, 'severity' => $finding->severity, 'owner' => $owner?->public_id]);

        return $finding;
    }

    public function fix(QaFinding $finding, string $commit, User $actor): QaFinding
    {
        return DB::transaction(function () use ($finding, $commit, $actor) {
            $f = QaFinding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            if (! $actor->isSuperAdmin() && $f->owner_id !== $actor->id) {
                throw new ApiException('Finding not found.', 404, 'not_found');
            }
            if (! in_array($f->status, ['open', 'retest_failed'], true)) {
                throw ApiException::conflict('Only open or failed-retest findings can be marked fixed.', 'finding_not_open', ['status' => $f->status]);
            }
            $f->forceFill(['status' => 'fixed', 'fix_commit' => strtolower($commit), 'fixed_by' => $actor->id, 'fixed_at' => now()])->save();
            $this->audit->record('qa.finding_fixed', category: AuditCategory::Admin, actorUserId: $actor->id, metadata: ['finding' => $f->public_id, 'fix_commit' => $f->fix_commit]);

            return $f;
        });
    }

    /**
     * Independent retest: a different user than the one who fixed it, with a run
     * imported after the fix. Every linked test must PASS in that run (executed,
     * not mocked); a finding without linked tests needs an explicit verdict.
     */
    public function retest(QaFinding $finding, QaTestRun $run, ?bool $verdict, User $actor): QaFinding
    {
        return DB::transaction(function () use ($finding, $run, $verdict, $actor) {
            $f = QaFinding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            if ($f->status !== 'fixed') {
                throw ApiException::conflict('Only fixed findings can be retested.', 'finding_not_fixed', ['status' => $f->status]);
            }
            if ($actor->id === $f->fixed_by || $actor->id === $f->owner_id) {
                throw new ApiException('The retest must be done by someone other than the developer who fixed it.', 403, 'retest_not_independent');
            }
            if ($run->created_at->lessThanOrEqualTo($f->fixed_at)) { // second precision: a same-second run may predate the fix
                throw ApiException::conflict('Use a run imported after the fix was recorded.', 'retest_run_outdated');
            }
            $results = QaTestResult::where('run_id', $run->id)->whereIn('key', $f->test_keys === [] ? [''] : $f->test_keys)->get()->keyBy('key');
            if ($f->test_keys === []) {
                if ($verdict === null) {
                    throw new ApiException('This finding has no linked tests; give an explicit verdict.', 422, 'validation_failed', ['errors' => ['passed' => ['Required when the finding has no linked tests.']]]);
                }
                $passed = $verdict;
            } else {
                $passed = collect($f->test_keys)->every(fn ($k) => ($r = $results->get($k)) !== null && $r->status === 'pass' && ! $r->mocked);
            }
            $f->forceFill(['status' => $passed ? 'retest_passed' : 'retest_failed', 'retest_run_id' => $run->id, 'retested_by' => $actor->id])->save();
            $this->audit->record('qa.finding_retested', category: AuditCategory::Admin, actorUserId: $actor->id,
                metadata: ['finding' => $f->public_id, 'run' => $run->public_id, 'passed' => $passed]);

            return $f;
        });
    }

    /**
     * Regression plan: approved tests covering the affected routes or other routes
     * of the same controllers, money/tenant/webhook paths first.
     *
     * @return list<array{key: string, title: string, reason: string, priority: bool}>
     */
    public function regression(QaFinding $f): array
    {
        $affected = QaEndpoint::get()->filter(fn ($e) => in_array($e->method.' '.$e->path, $f->affected_routes, true));
        $controllers = $affected->map(fn ($e) => Str::before((string) $e->action, '@'))->filter()->unique()->all();
        $related = QaEndpoint::whereNull('removed_at')->get()->filter(fn ($e) => $affected->contains('id', $e->id) || in_array(Str::before((string) $e->action, '@'), $controllers, true));
        $byEndpoint = DB::table('qa_test_coverage')->where('target_type', 'endpoint')->whereIn('target_id', $related->pluck('id')->all() ?: [0])->get()->groupBy('test_case_id');
        $cases = QaTestCase::whereIn('id', $byEndpoint->keys()->all() ?: [0])->where('status', 'approved')->get();

        return $cases->map(function (QaTestCase $c) use ($byEndpoint, $related, $affected) {
            $targets = $related->whereIn('id', $byEndpoint[$c->id]->pluck('target_id')->all());
            $direct = $targets->contains(fn ($e) => $affected->contains('id', $e->id));

            return ['key' => $c->key, 'title' => $c->title, 'reason' => $direct ? 'covers an affected route' : 'covers the same controller',
                'priority' => $targets->contains(fn ($e) => preg_match(self::PRIORITY_PATTERN, $e->path) === 1)];
        })->sortBy(fn ($r) => [! $r['priority'], $r['reason'] !== 'covers an affected route', $r['key']])->values()->take(100)->all();
    }

    public function analyze(QaFinding $finding, User $actor): QaFinding
    {
        if (! config('qa.ai_enabled') || (string) config('qa.api_key') === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', (string) config('qa.model'))) {
            throw new ApiException('AI analysis is not configured.', 503, 'qa_ai_not_configured');
        }
        DB::transaction(function () use ($finding) {
            $f = QaFinding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            if ($f->ai_status === 'processing' && $f->updated_at->greaterThan(now()->subMinutes(5))) {
                throw ApiException::conflict('An analysis is already running for this finding.', 'analysis_in_progress');
            }
            $day = now()->utc()->toDateString();
            DB::table('qa_ai_quotas')->upsert([['day' => $day, 'requests' => 0]], ['day'], ['day']);
            $quota = DB::table('qa_ai_quotas')->where('day', $day)->lockForUpdate()->first();
            if ($quota->requests >= max(0, min(500, (int) config('qa.daily_limit')))) {
                $retry = max(1, (int) ceil(now()->utc()->diffInSeconds(now()->utc()->addDay()->startOfDay())));
                throw new ApiException('The daily AI analysis limit is reached.', 429, 'qa_ai_daily_limit', ['retry_after_seconds' => $retry], ['Retry-After' => (string) $retry]);
            }
            DB::table('qa_ai_quotas')->where('day', $day)->increment('requests');
            $f->forceFill(['ai_status' => 'processing'])->save();
        });

        $finding->refresh();
        try {
            $analysis = $this->generate($finding);
            $finding->forceFill(['ai_status' => 'proposed', 'ai_analysis' => $analysis])->save();
            foreach ($analysis['proposed_tests'] as $i => $title) {
                QaTestCase::firstOrCreate(['key' => 'AI-'.$finding->public_id.'-'.($i + 1)], ['title' => Str::limit($title, 250), 'source' => 'ai', 'status' => 'draft']);
            }
        } catch (Throwable) {
            $finding->forceFill(['ai_status' => 'failed'])->save();
            throw new ApiException('The analysis could not be produced safely. Nothing was changed.', 503, 'qa_analysis_failed');
        }
        $this->audit->record('qa.finding_analyzed', category: AuditCategory::Admin, actorUserId: $actor->id,
            metadata: ['finding' => $finding->public_id, 'confidence' => $analysis['confidence'], 'proposed_tests' => count($analysis['proposed_tests'])]);

        return $finding;
    }

    /** @return array<string, mixed> */
    private function generate(QaFinding $f): array
    {
        $runIds = array_filter([$f->run_id, $f->retest_run_id]);
        $results = QaTestResult::whereIn('run_id', $runIds ?: [0])->whereIn('status', ['fail', 'blocked', 'not_implemented'])
            ->when($f->test_keys !== [], fn ($q) => $q->whereIn('key', $f->test_keys))->orderBy('id')->limit(30)->get();
        $evidence = QaEvidence::whereIn('run_id', $runIds ?: [0])->limit(30)->get(['public_id', 'name', 'mime', 'sha256', 'request_id']);
        $endpoints = QaEndpoint::whereNull('removed_at')->get()->filter(fn ($e) => in_array($e->method.' '.$e->path, $f->affected_routes, true));
        $input = [
            'finding' => ['title' => $f->title, 'severity' => $f->severity, 'reproduction' => $this->runs->redact((string) $f->reproduction)],
            'routes' => $endpoints->map(fn ($e) => ['route' => $e->method.' '.$e->path, 'handler' => $e->action, 'access' => $e->access, 'permission' => $e->permission])->values()->all(),
            'results' => $results->map(fn ($r) => ['test_id' => $r->key, 'status' => $r->status, 'assertions' => $r->assertions, 'mocked' => $r->mocked, 'message' => $this->runs->redact((string) $r->message)])->all(),
            'evidence' => $evidence->map(fn ($e) => ['evidence_id' => $e->public_id, 'name' => $e->name, 'type' => $e->mime, 'request_id' => $e->request_id])->all(),
        ];
        $str = ['type' => 'string'];
        $list = ['type' => 'array', 'items' => $str];
        $schema = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['affected_routes', 'test_ids', 'severity', 'evidence_ids', 'expected', 'actual', 'hypothesis', 'proposed_tests', 'confidence', 'needs_review'],
            'properties' => ['affected_routes' => $list, 'test_ids' => $list, 'severity' => ['type' => 'string', 'enum' => QaFinding::SEVERITIES], 'evidence_ids' => $list,
                'expected' => $str, 'actual' => $str, 'hypothesis' => $str, 'proposed_tests' => $list, 'confidence' => ['type' => 'number'], 'needs_review' => ['type' => 'boolean']]];
        $instructions = 'You are the ShopBonus QA failure investigator and regression planner. Using only the supplied redacted test results, routes and evidence,'
            .' explain expected versus actual behaviour, the likely cause and which routes are affected, and propose up to 5 additional test scenarios'
            .' (one sentence each) with payments, wallets, codes, tenant isolation and webhooks first. Only reference routes, test_ids and evidence_ids that'
            .' appear in the input. Do not mark anything as passed, do not suggest shell commands and do not invent facts. Confidence is 0 to 1.';

        $response = Http::withToken((string) config('qa.api_key'))->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(60)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('qa.model'), 'store' => false, 'max_output_tokens' => 3000, 'instructions' => $instructions,
                'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'qa_finding_analysis', 'strict' => true, 'schema' => $schema]],
            ]);
        if (! $response->successful() || strlen($response->body()) > 262144 || $response->json('status') !== 'completed') {
            throw new RuntimeException('Provider failed.');
        }
        $texts = [];
        foreach ($response->json('output', []) as $output) {
            if (($output['type'] ?? '') === 'reasoning') {
                continue;
            }
            if (($output['type'] ?? '') !== 'message' || ($output['role'] ?? '') !== 'assistant') {
                throw new RuntimeException('Invalid provider output.');
            }
            foreach ($output['content'] ?? [] as $item) {
                if (($item['type'] ?? '') !== 'output_text') {
                    throw new RuntimeException('Invalid provider content.');
                }
                $texts[] = $item['text'];
            }
        }
        $v = count($texts) === 1 ? json_decode($texts[0], true, flags: JSON_THROW_ON_ERROR) : null;
        $lists = ['affected_routes' => array_column($input['routes'], 'route'), 'test_ids' => array_column($input['results'], 'test_id'), 'evidence_ids' => array_column($input['evidence'], 'evidence_id')];
        if (! is_array($v) || ! in_array($v['severity'] ?? null, QaFinding::SEVERITIES, true) || ! is_numeric($v['confidence'] ?? null)) {
            throw new RuntimeException('Invalid analysis.');
        }
        foreach ($lists as $field => $allowed) {
            if (! is_array($v[$field] ?? null) || array_diff($v[$field], $allowed) !== []) {
                throw new RuntimeException('Reference outside the input.');
            }
        }
        foreach (['expected', 'actual', 'hypothesis'] as $field) {
            if (! is_string($v[$field] ?? null) || mb_strlen($v[$field]) > 2000) {
                throw new RuntimeException('Invalid text.');
            }
        }
        if (! is_array($v['proposed_tests'] ?? null) || count($v['proposed_tests']) > 5 || collect($v['proposed_tests'])->contains(fn ($t) => ! is_string($t) || trim($t) === '' || mb_strlen($t) > 250)) {
            throw new RuntimeException('Invalid proposed tests.');
        }
        foreach (array_merge([$v['expected'], $v['actual'], $v['hypothesis']], $v['proposed_tests']) as $text) {
            if (str_contains($text, (string) config('qa.api_key'))) {
                throw new RuntimeException('Secret in output.');
            }
        }

        return ['affected_routes' => array_values($v['affected_routes']), 'test_ids' => array_values($v['test_ids']), 'severity' => $v['severity'],
            'evidence_ids' => array_values($v['evidence_ids']), 'expected' => $v['expected'], 'actual' => $v['actual'], 'hypothesis' => $v['hypothesis'],
            'proposed_tests' => array_values($v['proposed_tests']), 'confidence' => max(0, min(1, round((float) $v['confidence'], 2))),
            'needs_review' => true, 'model' => (string) config('qa.model'), 'created_at' => now()->toIso8601String()];
    }
}
