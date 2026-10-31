<?php

namespace App\Services\Qa;

use App\Models\QaEndpoint;
use App\Models\QaFinding;
use App\Models\QaInventorySnapshot;
use App\Models\QaSchedule;
use App\Models\QaTestRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives each endpoint's status from executed evidence only (Trello #37 §10):
 * FAIL > NOT_IMPLEMENTED > BLOCKED > STALE > UNTESTED > PASS. Only approved
 * test cases count. Nothing becomes PASS without an executed assertion, and
 * mocked passes stay labelled.
 *
 * Freshness follows the tested build (Trello #36/#37 review): a result is
 * fresh when its run's Git SHA is the endpoint's change SHA or a later
 * inventory scan's SHA (`sha_verified`); STALE when that SHA is an earlier
 * scan (`sha_outdated`). For SHAs no scan knows, execution time is compared
 * with the change time (`time_only`). Results older than
 * `qa.stale_after_days` (by execution time) are always STALE.
 */
class QaStatus
{
    private const PRIORITY = ['fail', 'not_implemented', 'blocked', 'stale', 'untested'];

    /**
     * @param  Collection<int, QaEndpoint>  $endpoints
     * @return array<int, array{status: string, mocked: bool, tests: int, last_run_at: string|null, provenance: string|null, evidence_missing: bool}>
     */
    public function forEndpoints(Collection $endpoints): array
    {
        $ids = $endpoints->pluck('id')->all();
        $links = DB::table('qa_test_coverage as c')->join('qa_test_cases as t', 't.id', '=', 'c.test_case_id')
            ->where('c.target_type', 'endpoint')->whereIn('c.target_id', $ids === [] ? [0] : $ids)->where('t.status', 'approved')
            ->get(['c.target_id', 'c.test_case_id', 't.source'])->groupBy('target_id');
        $needsEvidence = (array) config('qa.evidence_required_sources', []);
        $caseIds = $links->flatten(1)->pluck('test_case_id')->unique()->values()->all();
        $latest = DB::table('qa_test_results as r')->join('qa_test_runs as run', 'run.id', '=', 'r.run_id')
            ->whereIn('r.id', DB::table('qa_test_results')->selectRaw('max(id)')->whereIn('test_case_id', $caseIds === [] ? [0] : $caseIds)->groupBy('test_case_id'))
            ->get(['r.id', 'r.test_case_id', 'r.status', 'r.mocked', 'r.created_at', 'run.git_sha', 'run.started_at'])->keyBy('test_case_id');
        $withEvidence = DB::table('qa_evidence')->whereIn('result_id', $latest->pluck('id')->all() ?: [0])->distinct()->pluck('result_id')->flip();
        $staleBefore = CarbonImmutable::now()->subDays((int) config('qa.stale_after_days'));
        $scans = DB::table('qa_inventory_snapshots')->orderBy('id')->get(['git_sha', 'created_at']);

        $out = [];
        foreach ($endpoints as $endpoint) {
            $caseLinks = collect($links->get($endpoint->id, []));
            $cases = $caseLinks->pluck('test_case_id');
            $evidenceMissing = false;
            $statuses = [];
            $provenance = [];
            $mocked = false;
            $last = null;
            foreach ($cases as $caseId) {
                $r = $latest->get($caseId);
                if ($r === null) {
                    $statuses[] = 'untested';

                    continue;
                }
                $at = CarbonImmutable::parse($r->started_at ?? $r->created_at);
                $last = $last === null || $at->greaterThan($last) ? $at : $last;
                $mocked = $mocked || (bool) $r->mocked;
                $source = self::provenance((string) $r->git_sha, $at, $endpoint, $scans);
                $provenance[] = $source;
                // A manual/browser PASS without attached evidence proves nothing yet.
                $unproven = $r->status === 'pass' && in_array($caseLinks->firstWhere('test_case_id', $caseId)?->source, $needsEvidence, true) && ! $withEvidence->has($r->id);
                $evidenceMissing = $evidenceMissing || $unproven;
                $statuses[] = match (true) {
                    $r->status === 'skipped', $unproven => 'untested',
                    $source === 'sha_outdated' || ($source === 'time_only' && $at->lessThan($endpoint->changed_at)) || $at->lessThan($staleBefore) => 'stale',
                    default => $r->status,
                };
            }
            $status = $statuses === [] ? 'untested' : (collect(self::PRIORITY)->first(fn ($s) => in_array($s, $statuses, true)) ?? 'pass');
            $out[$endpoint->id] = ['status' => $status, 'mocked' => $status === 'pass' && $mocked, 'tests' => $cases->count(), 'last_run_at' => $last?->toIso8601String(),
                'evidence_missing' => $evidenceMissing,
                'provenance' => $provenance === [] ? null : (in_array('sha_outdated', $provenance, true) ? 'sha_outdated' : (in_array('time_only', $provenance, true) ? 'time_only' : 'sha_verified'))];
        }

        return $out;
    }

    /**
     * How a result relates to the endpoint's last change, from the tested build.
     *
     * @param  Collection<int, \stdClass>  $scans  inventory snapshots, oldest first
     */
    private static function provenance(string $runSha, CarbonImmutable $executedAt, QaEndpoint $endpoint, Collection $scans): string
    {
        $same = fn (?string $a) => $a !== null && strlen($runSha) >= 7 && strlen($a) >= 7 && (str_starts_with($a, $runSha) || str_starts_with($runSha, $a));
        if ($same($endpoint->changed_sha)) {
            return 'sha_verified';
        }
        $match = $scans->last(fn ($s) => $same($s->git_sha));
        if ($match === null) {
            return 'time_only';
        }

        return CarbonImmutable::parse($match->created_at)->greaterThanOrEqualTo($endpoint->changed_at) ? 'sha_verified' : 'sha_outdated';
    }

    /**
     * Dashboard header (Trello #37 §8).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $endpoints = QaEndpoint::whereNull('removed_at')->get();
        $statuses = collect($this->forEndpoints($endpoints));
        $counts = $statuses->countBy('status');
        $total = $endpoints->count();
        $tested = $total - (int) $counts->get('untested', 0);
        $lastRun = QaTestRun::where('source', '!=', 'probe')->latest('id')->first();
        $probe = QaTestRun::where('source', 'probe')->latest('id')->first();
        $snapshot = QaInventorySnapshot::latest('id')->first();
        $findings = QaFinding::whereIn('status', ['open', 'fixed', 'retest_failed'])->get()->countBy('severity');

        return [
            'endpoints' => $total, 'tested' => $tested,
            'coverage_percent' => $total === 0 ? 0 : round($tested * 100 / $total, 1),
            'pass' => (int) $counts->get('pass', 0), 'pass_mocked' => $statuses->where('mocked', true)->count(),
            'fail' => (int) $counts->get('fail', 0), 'blocked' => (int) $counts->get('blocked', 0),
            'not_implemented' => (int) $counts->get('not_implemented', 0), 'stale' => (int) $counts->get('stale', 0),
            'untested' => (int) $counts->get('untested', 0),
            'undocumented' => $endpoints->where('documented', false)->count(),
            'unreferenced' => $endpoints->filter(fn ($e) => self::unreferenced($e))->count(),
            'open_findings' => ['p0' => (int) $findings->get('p0', 0), 'p1' => (int) $findings->get('p1', 0), 'p2' => (int) $findings->get('p2', 0), 'p3' => (int) $findings->get('p3', 0)],
            'last_run' => $lastRun === null ? null : ['id' => $lastRun->public_id, 'suite' => $lastRun->suite, 'environment' => $lastRun->environment, 'git_sha' => $lastRun->git_sha, 'totals' => $lastRun->totals, 'created_at' => $lastRun->created_at->toIso8601String()],
            'next_run_at' => $this->nextRunAt(),
            'last_probe' => $probe === null ? null : ['id' => $probe->public_id, 'healthy' => ($probe->totals['fail'] ?? 0) === 0, 'created_at' => $probe->created_at->toIso8601String()],
            'last_scan' => $snapshot === null ? null : ['id' => $snapshot->public_id, 'git_sha' => $snapshot->git_sha, 'created_at' => $snapshot->created_at?->toIso8601String()],
        ];
    }

    /**
     * Apparently unused: a frontend usage report exists and does not list it,
     * and it does not serve webhooks, integrations or external clients.
     */
    public static function unreferenced(QaEndpoint $e): bool
    {
        return $e->frontend_referenced === false && ! self::externalClient($e);
    }

    public static function externalClient(QaEndpoint $e): bool
    {
        return $e->access === 'integration' || preg_match('#webhook|callback|/integrations?/|answer24|/plugin|/embed#', $e->path) === 1;
    }

    /** Earliest upcoming run of an active time-based schedule (UTC); after_deploy has no fixed time. */
    public function nextRunAt(): ?string
    {
        $now = CarbonImmutable::now('UTC');
        $next = QaSchedule::where('active', true)->get()->map(fn (QaSchedule $s) => match ($s->trigger) {
            'every_5_minutes' => $now->addMinutes(5 - $now->minute % 5)->startOfMinute(),
            'nightly' => $now->setTime(2, 0)->lessThanOrEqualTo($now) ? $now->addDay()->setTime(2, 0) : $now->setTime(2, 0),
            'weekly' => $now->next(CarbonImmutable::SUNDAY)->setTime(3, 0),
            default => null,
        })->filter()->sort()->first();

        return $next?->toIso8601String();
    }
}
