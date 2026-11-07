<?php

namespace App\Services\Qa;

use App\Models\QaComponent;
use App\Models\QaEndpoint;
use App\Models\QaInventorySnapshot;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Enforces reviewed executable coverage; neither AI proposals nor green unrelated JUnit qualify. */
class QaExecutionPlan
{
    /** @param list<array<string,mixed>> $runners
     * @param  array<string,mixed>  $diff
     * @return array<string,array{allowed_tests:list<array{key:string,version:int,min_assertions:int}>,required_tests:list<array{key:string,version:int,min_assertions:int}>}>
     */
    public function build(array $runners, string $trigger, array $diff, string $sha): array
    {
        $plans = [];
        $mapped = [];
        foreach ($runners as $runner) {
            $tests = $runner['tests'];
            $seen = [];
            foreach ($tests as $test) {
                $case = QaTestCase::where('key', $test['key'])->first();
                if (isset($seen[$test['key']]) || ($case !== null && isset($mapped[$case->id])) || $case === null || ! $this->reviewed($case, $test) || $test['min_assertions'] < 1) {
                    throw new RuntimeException('Runner requires distinct approved executable case versions and positive assertions.');
                }
                $seen[$test['key']] = true;
                $mapped[$case->id] = $case->key;
            }
            $plans[$runner['id']] = ['allowed_tests' => $tests, 'required_tests' => $trigger === 'after_deploy' ? [] : $tests];
        }
        if ($trigger !== 'after_deploy') {
            return $plans;
        }
        // Discovery persists even when planning/execution fails. Retain all impacts on this build across retries.
        $diffs = [$diff];
        foreach (QaInventorySnapshot::where('git_sha', $sha)->get(['diff']) as $snapshot) {
            $diffs[] = $snapshot->diff;
        }
        $required = [];
        foreach (['endpoints' => 'endpoint', 'components' => 'component'] as $group => $type) {
            $keys = [];
            foreach ($diffs as $impact) {
                foreach (['added', 'changed', 'removed'] as $change) {
                    $keys = array_merge($keys, $impact[$group][$change] ?? []);
                }
            }
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                continue;
            }
            $targets = $type === 'endpoint' ? QaEndpoint::all()->keyBy(fn ($e) => $e->method.' '.$e->path) : QaComponent::all()->keyBy(fn ($c) => $c->kind.':'.$c->name);
            foreach ($keys as $key) {
                $target = $targets->get($key);
                if ($target === null) {
                    throw new RuntimeException('Discovery impact is absent from the backend inventory.');
                }
                $links = DB::table('qa_test_coverage as c')->join('qa_test_cases as t', 't.id', '=', 'c.test_case_id')->where('c.target_type', $type)->where('c.target_id', $target->id)->where('t.status', 'approved')->pluck('t.id')->all();
                if ($links === []) {
                    throw new RuntimeException('Changed backend target has no approved executable coverage.');
                }
                foreach ($links as $id) {
                    if (! isset($mapped[$id])) {
                        throw new RuntimeException('An impacted approved case has no reviewed runner for this trigger.');
                    }
                    $required[$mapped[$id]] = true;
                }
            }
        }
        // No source diff is not proof of no behavior changes (configuration/dependencies may differ).
        foreach ($plans as &$plan) {
            $plan['required_tests'] = $required === [] ? $plan['allowed_tests'] : array_values(array_filter($plan['allowed_tests'], fn ($test) => isset($required[$test['key']])));
        }
        unset($plan);

        return $plans;
    }

    /** @param array{allowed_tests:list<array{key:string,version:int,min_assertions:int}>,required_tests:list<array{key:string,version:int,min_assertions:int}>} $plan */
    public function reportMatches(QaTestRun $run, array $plan): bool
    {
        return $this->casesMatch(QaTestResult::where('run_id', $run->id)->get()->map(fn ($r) => ['key' => $r->key, 'assertions' => $r->assertions])->all(), $plan);
    }

    /** @param list<array{key:string,assertions:int}> $cases
     * @param  array{allowed_tests:list<array{key:string,version:int,min_assertions:int}>,required_tests:list<array{key:string,version:int,min_assertions:int}>}  $plan
     */
    public function casesMatch(array $cases, array $plan): bool
    {
        $allowed = array_column($plan['allowed_tests'], null, 'key');
        $seen = [];
        foreach ($cases as $result) {
            $test = $allowed[$result['key']] ?? null;
            $case = $test === null ? null : QaTestCase::where('key', $test['key'])->first();
            if ($test === null || isset($seen[$result['key']]) || $result['assertions'] < $test['min_assertions'] || $case === null || ! $this->reviewed($case, $test)) {
                return false;
            }
            $seen[$result['key']] = true;
        }

        return array_diff(array_column($plan['required_tests'], 'key'), array_keys($seen)) === [];
    }

    private function reviewed(QaTestCase $case, array $test): bool
    {
        if ($case->status !== 'approved' || $case->version !== $test['version'] || ! in_array($case->source, ['phpunit', 'postman', 'browser'], true)) {
            return false;
        }
        if (! array_key_exists('proposal', $test)) {
            return true;
        }

        return QaTestCase::where('key', $test['proposal']['key'] ?? '')
            ->where('version', $test['proposal']['version'] ?? 0)
            ->where('source', 'ai')
            ->where('status', 'approved')
            ->exists();
    }
}
