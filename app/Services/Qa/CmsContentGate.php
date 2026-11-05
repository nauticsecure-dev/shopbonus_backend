<?php

namespace App\Services\Qa;

use App\Models\QaFinding;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** Separate owner-delegated recommended CMS contract; never claims recovery of original175 scope. */
class CmsContentGate
{
    private const DENOMINATORS = ['CMS-D01' => 'saved_fields', 'CMS-D02' => 'locale_bindings', 'CMS-D03' => 'media_breakpoints', 'CMS-D04' => 'component_contexts', 'CMS-D05' => 'staging_executors'];

    public function __construct(private readonly QaAcceptance $proofs, private readonly QaRunnerSource $source) {}

    /** @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function evaluate(array $manifest, string $sha): array
    {
        $blockers = [];
        $rows = [];
        $seen = [];
        $keys = [];
        if (! preg_match('/\A[0-9a-f]{40,64}\z/D', $sha)) {
            $blockers[] = 'exact_deployment_sha_required';
        }
        if (! Validator::make($manifest, ['schema_version' => 'required|in:1', 'state' => 'required|in:reviewed', 'scope' => 'required|in:owner_delegated_recommended_contract_not_original175', 'source.path' => 'required|string', 'source.version' => 'required|string', 'source.sha256' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'], 'frontend_git_sha' => ['required', 'regex:/\A[0-9a-f]{40,64}\z/D'], 'denominators' => 'required|array|size:5', 'checks' => 'required|array|size:23'])->passes()) {
            $blockers[] = 'cms_contract_unmapped_or_invalid';
        }
        $hash = is_string($manifest['source']['sha256'] ?? null) ? $manifest['source']['sha256'] : '';
        $version = is_string($manifest['source']['version'] ?? null) ? $manifest['source']['version'] : '';
        $path = is_string($manifest['source']['path'] ?? null) ? realpath(base_path('requirements').'/'.$manifest['source']['path']) : false;
        if (! $path || ! str_starts_with($path, realpath(base_path('requirements')).DIRECTORY_SEPARATOR) || ! is_file($path) || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
            $blockers[] = 'cms_recommended_source_missing_or_changed';
        }
        if (QaFinding::whereIn('severity', ['p0', 'p1'])->whereIn('status', ['open', 'fixed', 'retest_failed'])->exists()) {
            $blockers[] = 'critical_or_high_findings_unresolved';
        }
        try {
            $registry = json_decode((string) file_get_contents(base_path('requirements/QA_RUNNERS.json')), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $registry = [];
        }
        $runners = ($registry['state'] ?? null) === 'reviewed' ? array_column($registry['runners'] ?? [], null, 'id') : [];
        if ($blockers === [] && ($registry['state'] ?? null) === 'reviewed') {
            try {
                foreach (['requirements/QA_RUNNERS.json', 'requirements/CMS_ACCEPTANCE.json', 'requirements/CMS_ACCEPTANCE_SOURCE.md'] as $file) {
                    $this->source->verify($file, (string) hash_file('sha256', base_path($file)), $sha);
                }
                foreach ($runners as $runner) {
                    $this->source->verify($runner['path'], $runner['sha256'], $sha);
                }
            } catch (Throwable) {
                $blockers[] = 'cms_contract_or_runner_source_not_deployed';
            }
        }
        foreach (['denominators', 'checks'] as $group) {
            foreach (is_array($manifest[$group] ?? null) ? $manifest[$group] : [] as $definition) {
                if (! is_array($definition)) {
                    $blockers[] = 'invalid_cms_definition';

                    continue;
                }
                $id = $definition['id'] ?? '';
                $issues = [];
                if (! is_string($id) || isset($seen[$id])) {
                    $blockers[] = 'duplicate_or_invalid_cms_id';

                    continue;
                }$seen[$id] = true;
                if ($group === 'denominators' && (! isset(self::DENOMINATORS[$id]) || ($definition['coverage_kind'] ?? null) !== self::DENOMINATORS[$id])) {
                    $issues[] = 'unknown_coverage_denominator';
                }
                if ($group === 'checks' && ! preg_match('/\ACMS-T(0[1-9]|1[0-9]|2[0-3])\z/D', $id)) {
                    $issues[] = 'unknown_content_executor';
                }
                if (isset($definition['test_key']) && is_string($definition['test_key'])) {
                    if (isset($keys[$definition['test_key']])) {
                        $issues[] = 'duplicate_test_mapping';
                    }$keys[$definition['test_key']] = true;
                }
                if (in_array('frontend_inventory', $definition['required_evidence'] ?? [], true) && ($definition['frontend_git_sha'] ?? null) !== ($manifest['frontend_git_sha'] ?? null)) {
                    $issues[] = 'frontend_build_mismatch';
                }
                if (! is_string($definition['runner_id'] ?? null) || ! isset($runners[$definition['runner_id']])) {
                    $issues[] = 'reviewed_staging_runner_unmapped';
                }
                if ($blockers === []) {
                    $issues = array_merge($issues, $this->proofs->checkCms($definition, $sha, $hash, $version));
                } else {
                    $issues[] = 'contract_or_source_unmapped';
                }
                $rows[] = ['id' => $id, 'group' => $group, 'status' => $issues === [] ? 'evidence_complete' : 'blocked', 'blockers' => $issues];
            }
        }
        foreach (array_keys(self::DENOMINATORS) as $id) {
            if (! isset($seen[$id])) {
                $blockers[] = 'denominator_unmapped:'.$id;
            }
        }
        foreach (range(1, 23) as $n) {
            $id = sprintf('CMS-T%02d', $n);
            if (! isset($seen[$id])) {
                $blockers[] = 'executor_unmapped:'.$id;
            }
        }
        $complete = $blockers === [] && count($rows) === 28 && array_filter($rows, fn ($r) => $r['status'] !== 'evidence_complete') === [];

        return ['schema_version' => 1, 'scope' => 'Owner-delegated recommended CMS contract only; original175-check release scope remains separate.', 'deployment_sha' => $sha, 'evidence_complete' => $complete, 'release_approved' => false, 'blockers' => array_values(array_unique($blockers)), 'definitions' => $rows, 'frontend_discovery' => 'Independent exact-build evidence required; not discovered by Laravel.'];
    }
}
