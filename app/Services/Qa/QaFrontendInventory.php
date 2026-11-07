<?php

namespace App\Services\Qa;

use App\Models\QaEvidence;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use Carbon\CarbonImmutable;

/** Validate the same-build candidate/disposition/runtime bindings, never infer unobserved behavior. */
class QaFrontendInventory
{
    public const KINDS = ['routes', 'actions', 'components', 'ui_states'];

    /** Browser observations must resolve to actual same-backend-build staging results and retained artifacts.
     * @param  array<string,mixed>  $data
     */
    public function artifactsAvailable(array $data, string $sha): bool
    {
        foreach ($data['contexts'] ?? [] as $context) {
            $run = QaTestRun::where('public_id', $context['run_id'])->where('environment', 'staging')->where('git_sha', $sha)->first();
            $result = $run === null ? null : QaTestResult::where('public_id', $context['result_id'])->where('run_id', $run->id)->where('key', $context['test_key'])->where('status', 'pass')->where('mocked', false)->where('assertions', '>', 0)->first();
            if ($run === null || $result === null || CarbonImmutable::parse($run->started_at)->lessThan(now()->subDays((int) config('qa.stale_after_days')))
                || ! QaTestCase::whereKey($result->test_case_id)->where('status', 'approved')->where('version', $context['case_version'])->exists()) {
                return false;
            }
            foreach (['screenshot', 'trace'] as $kind) {
                $artifacts = QaEvidence::where('run_id', $run->id)->where('result_id', $result->id)->where('sha256', $context['evidence'][$kind]['sha256'])->get();
                if (! $artifacts->contains(fn ($artifact) => ($kind === 'screenshot' ? str_starts_with($artifact->mime, 'image/') : in_array($artifact->mime, ['application/json', 'text/plain'], true)) && app(QaRuns::class)->evidenceAvailable($artifact))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string,mixed> $data */
    public function valid(array $data): bool
    {
        if (($data['schema_version'] ?? null) !== 2 || ($data['verification'] ?? null) !== 'reviewed_runtime_dispositions'
            || ! preg_match('/\A[0-9a-f]{64}\z/D', (string) ($data['source_sha256'] ?? ''))
            || ! is_string($data['review_reference'] ?? null) || trim($data['review_reference']) === ''
            || ! is_array($data['contexts'] ?? null) || $data['contexts'] === [] || count($data['contexts']) > 100000) {
            return false;
        }
        $contexts = [];
        foreach ($data['contexts'] as $context) {
            if (! is_array($context) || ! is_string($context['id'] ?? null) || $context['id'] === '' || isset($contexts[$context['id']])
                || ($context['git_sha'] ?? null) !== ($data['git_sha'] ?? null) || ! in_array($context['locale'] ?? null, ['nl', 'en', 'de'], true)
                || ! is_int($context['case_version'] ?? null) || $context['case_version'] < 1
                || ! is_int($context['viewport']['width'] ?? null) || $context['viewport']['width'] < 1
                || ! is_int($context['viewport']['height'] ?? null) || $context['viewport']['height'] < 1) {
                return false;
            }
            foreach (['route', 'actor_role', 'ui_state', 'test_key', 'run_id', 'result_id'] as $key) {
                if (! is_string($context[$key] ?? null) || trim($context[$key]) === '') {
                    return false;
                }
            }
            foreach (['screenshot', 'trace'] as $kind) {
                if (! preg_match('/\A[0-9a-f]{64}\z/D', (string) ($context['evidence'][$kind]['sha256'] ?? ''))) {
                    return false;
                }
            }
            $contexts[$context['id']] = $context;
        }
        foreach (self::KINDS as $kind) {
            $covered = [];
            $seen = [];
            $rows = $data['dispositions'][$kind] ?? null;
            if (! is_array($rows) || $rows === [] || count($rows) > 100000 || ! is_array($data[$kind] ?? null) || $data[$kind] === []) {
                return false;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '' || isset($seen[$row['id']])
                    || ! in_array($row['decision'] ?? null, ['covered', 'excluded'], true) || ! is_string($row['reference'] ?? null) || trim($row['reference']) === '') {
                    return false;
                }
                $seen[$row['id']] = true;
                if ($row['decision'] === 'covered') {
                    if (! is_array($row['context_ids'] ?? null) || $row['context_ids'] === []) {
                        return false;
                    }
                    foreach ($row['context_ids'] as $id) {
                        if (! is_string($id) || ! isset($contexts[$id]) || ! in_array($row['id'], $contexts[$id]['observed'][$kind] ?? [], true)) {
                            return false;
                        }
                    }
                    $covered[] = $row['id'];
                }
            }
            $candidates = $data['source_candidates'][$kind] ?? null;
            if (! is_array($candidates) || $candidates === [] || array_filter($candidates, fn ($id) => ! is_string($id)) !== [] || count($candidates) !== count(array_unique($candidates))) {
                return false;
            }
            $discovered = array_keys($seen);
            sort($discovered);
            sort($candidates);
            if ($discovered !== $candidates) {
                return false;
            }
            foreach ($contexts as $context) {
                $observed = $context['observed'][$kind] ?? null;
                if (! is_array($observed) || array_filter($observed, fn ($id) => ! is_string($id)) !== [] || count($observed) !== count(array_unique($observed)) || array_diff($observed, array_keys($seen)) !== []) {
                    return false;
                }
            }
            $reported = $data[$kind];
            if (array_filter($reported, fn ($id) => ! is_string($id)) !== [] || count($reported) !== count(array_unique($reported))) {
                return false;
            }
            sort($covered);
            sort($reported);
            if ($covered !== $reported) {
                return false;
            }
        }

        return true;
    }
}
