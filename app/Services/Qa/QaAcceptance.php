<?php

namespace App\Services\Qa;

use App\Enums\PlatformRole;
use App\Models\QaEvidence;
use App\Models\QaFinding;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** Execution PASS is not independently reviewed acceptance. Never executes manifest instructions. */
class QaAcceptance
{
    public const PROVIDERS = ['shopify', 'woocommerce', 'wix', 'bigcommerce', 'ecwid', 'lightspeed_c', 'prestashop', 'opencart', 'shopware6', 'magento'];

    public const STAGES = ['installation', 'authorization', 'order', 'refund', 'storefront', 'uninstall'];

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function evaluate(array $manifest, string $sha): array
    {
        $errors = [];
        $rows = [];
        $validSha = preg_match('/\A[0-9a-f]{40,64}\z/D', $sha) === 1;
        if (! $validSha) {
            $errors[] = 'exact_deployment_sha_required';
        }
        $validator = Validator::make($manifest, [
            'schema_version' => ['required', 'integer', 'in:1'], 'state' => ['required', 'in:reviewed'],
            'source.version' => ['required', 'string', 'max:100'], 'source.path' => ['required', 'string', 'max:500'], 'source.sha256' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'checks' => ['required', 'array', 'size:175'], 'provider_subruns' => ['required', 'array', 'size:60'],
            'frontend_discovery' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            $errors = array_merge($errors, array_map(fn ($key) => 'manifest_invalid:'.$key, array_keys($validator->errors()->messages())));
        }
        $sourceHash = is_string($manifest['source']['sha256'] ?? null) ? $manifest['source']['sha256'] : '';
        $path = is_string($manifest['source']['path'] ?? null) ? realpath(base_path('requirements').'/'.$manifest['source']['path']) : false;
        if (! $path || ! str_starts_with($path, realpath(base_path('requirements')).DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! hash_equals($sourceHash, (string) hash_file('sha256', $path))) {
            $errors[] = 'approved_source_missing_or_mismatched';
        }
        if (QaFinding::whereIn('severity', ['p0', 'p1'])->whereIn('status', ['open', 'fixed', 'retest_failed'])->exists()) {
            $errors[] = 'critical_or_high_findings_unresolved';
        }
        $definitions = [];
        $ids = [];
        $packages = [];
        $testKeys = [];
        foreach (['checks', 'provider_subruns'] as $group) {
            foreach (is_array($manifest[$group] ?? null) ? $manifest[$group] : [] as $entry) {
                if (! is_array($entry) || ! $this->definitionValid($entry, $group === 'provider_subruns')) {
                    $errors[] = 'invalid_definition:'.$group;

                    continue;
                }
                if (isset($ids[$entry['id']])) {
                    $errors[] = 'duplicate_check_id:'.$entry['id'];
                }
                if (isset($testKeys[$entry['test_key']])) {
                    $errors[] = 'duplicate_test_key:'.$entry['test_key'];
                }
                $testKeys[$entry['test_key']] = true;
                $ids[$entry['id']] = true;
                $packages[$entry['package']] = true;
                $definitions[] = $entry;
            }
        }
        foreach (range(1, 25) as $number) {
            $package = sprintf('E2E-%02d', $number);
            if (! isset($packages[$package])) {
                $errors[] = 'package_unmapped:'.$package;
            }
        }
        foreach (self::PROVIDERS as $provider) {
            foreach (self::STAGES as $stage) {
                $matched = array_filter($definitions, fn ($row) => ($row['provider'] ?? null) === $provider && ($row['stage'] ?? null) === $stage);
                if (count($matched) !== 1) {
                    $errors[] = 'provider_subrun_missing_or_duplicate:'.$provider.':'.$stage;
                }
            }
        }
        $frontend = $manifest['frontend_discovery'] ?? null;
        if (is_array($frontend) && $this->definitionValid($frontend, false) && preg_match('/\A[0-9a-f]{40,64}\z/D', (string) ($frontend['frontend_git_sha'] ?? ''))) {
            if (isset($ids[$frontend['id']]) || isset($testKeys[$frontend['test_key']])) {
                $errors[] = 'duplicate_frontend_discovery_identity';
            }
            if (! in_array('frontend_inventory', $frontend['required_evidence'], true)) {
                $errors[] = 'frontend_inventory_evidence_required';
            }
            $definitions[] = $frontend;
        } else {
            $errors[] = 'frontend_discovery_unmapped';
        }
        if ($errors === []) {
            foreach ($definitions as $definition) {
                $issues = $this->check($definition, $sha, $sourceHash, $manifest['source']['version']);
                $rows[] = ['id' => $definition['id'], 'status' => $issues === [] ? 'evidence_complete' : 'blocked', 'blockers' => $issues];
            }
        }
        $complete = $errors === [] && $rows !== [] && array_filter($rows, fn ($row) => $row['status'] !== 'evidence_complete') === [];

        return ['schema_version' => 1, 'deployment_sha' => $sha, 'environment' => 'staging', 'evidence_complete' => $complete,
            'release_approved' => false, 'approval_owner' => 'Martijn', 'blockers' => array_values(array_unique($errors)), 'checks' => $rows,
            'scope' => 'Reviewed evidence gate only; not automatic proof of factual provider state, exhaustive frontend discovery or final release approval.'];
    }

    /** @param array<string, mixed> $definition */
    private function definitionValid(array $definition, bool $provider): bool
    {
        $rules = ['id' => ['required', 'string', 'max:100'], 'package' => ['required', 'regex:/\AE2E-(0[1-9]|1[0-9]|2[0-5])\z/D'],
            'test_key' => ['required', 'string', 'max:250'], 'assertions' => ['required', 'array', 'min:1'], 'assertions.*' => ['required', 'string', 'max:2000'],
            'case_version' => ['required', 'integer', 'min:1'], 'min_assertions' => ['required', 'integer', 'min:1'], 'environment' => ['required', 'in:staging'],
            'executor' => ['required', 'string', 'max:250'], 'instructions' => ['required', 'array', 'min:1'], 'instructions.*' => ['required', 'string', 'max:2000'],
            'required_evidence' => ['required', 'array', 'min:4'], 'required_evidence.*' => ['required', 'string', 'in:http,database,request_ids,screenshot,provider_confirmation,frontend_inventory'],
            'review_evidence_id' => ['required', 'ulid']];
        if ($provider) {
            $rules += ['provider' => ['required', 'in:'.implode(',', self::PROVIDERS)], 'stage' => ['required', 'in:'.implode(',', self::STAGES)]];
        }

        return Validator::make($definition, $rules)->passes()
            && array_diff(['http', 'database', 'request_ids', 'screenshot'], $definition['required_evidence'] ?? []) === []
            && (! $provider || in_array('provider_confirmation', $definition['required_evidence'], true));
    }

    /** Hash reviewed instructions/assertions too: changing a mapping invalidates its old proof.
     * @param  array<string, mixed>  $definition
     */
    public static function definitionHash(array $definition): string
    {
        unset($definition['review_evidence_id']);
        ksort($definition);

        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $definition
     * @return list<string>
     */
    private function check(array $definition, string $sha, string $sourceHash, string $sourceVersion): array
    {
        try {
            $proofs = app(QaRuns::class);
            $review = QaEvidence::where('public_id', strtolower($definition['review_evidence_id']))->first();
            if ($review === null || ! $proofs->evidenceAvailable($review)) {
                return ['review_evidence_unavailable'];
            }
            $record = json_decode($proofs->evidenceBytes($review), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($record)) {
                return ['invalid_review_record'];
            }
            $result = QaTestResult::whereKey($review->result_id)->where('run_id', $review->run_id)->first();
            $run = QaTestRun::find($review->run_id);
            $case = QaTestCase::where('key', $definition['test_key'])->first();
            if ($result === null || $run === null || $case === null || $case->status !== 'approved' || $case->version !== $definition['case_version'] || $result->test_case_id !== $case->id
                || $result->key !== $definition['test_key'] || $result->status !== 'pass' || $result->mocked || $result->assertions < $definition['min_assertions']) {
                return ['approved_unmocked_assertion_required'];
            }
            if ($run->environment !== 'staging' || $run->git_sha !== $sha || $run->started_at === null || $run->finished_at === null
                || $run->finished_at->isFuture() || $run->started_at->greaterThan($run->finished_at)
                || $run->started_at->lessThan(now()->subDays((int) config('qa.stale_after_days')))) {
                return ['exact_fresh_staging_execution_required'];
            }
            $latest = QaTestResult::where('test_case_id', $case->id)->whereIn('run_id', QaTestRun::where('git_sha', $sha)->where('environment', 'staging')->select('id'))->latest('id')->value('id');
            if ($latest !== $result->id) {
                return ['latest_execution_required'];
            }
            $reviewedAt = CarbonImmutable::parse($record['reviewed_at'] ?? 'invalid');
            if (($record['verdict'] ?? null) !== 'accepted' || $reviewedAt->lessThan($run->finished_at) || $reviewedAt->isFuture()) {
                return ['completed_independent_review_required'];
            }
            $reviewer = User::find($review->uploaded_by);
            if (! $reviewer?->isActive() || (! $reviewer->isSuperAdmin() && ! $reviewer->hasPlatformRole(PlatformRole::QaTester))
                || $run->created_by === null || $review->uploaded_by === $run->created_by || $review->uploaded_by === $case->created_by) {
                return ['independent_active_reviewer_required'];
            }
            foreach (['check_id' => $definition['id'], 'definition_sha256' => self::definitionHash($definition), 'source_sha256' => $sourceHash, 'source_version' => $sourceVersion,
                'deployment_sha' => $sha, 'environment' => 'staging', 'run_id' => $run->public_id, 'result_id' => $result->public_id,
                'executor_user_id' => $run->created_by, 'reviewer_user_id' => $review->uploaded_by] as $key => $expected) {
                if (($record[$key] ?? null) !== $expected) {
                    return ['review_binding_mismatch:'.$key];
                }
            }
            $errors = [];
            foreach (array_unique($definition['required_evidence']) as $kind) {
                $refs = is_array($record['evidence'] ?? null) ? array_filter($record['evidence'], fn ($ref) => is_array($ref) && ($ref['kind'] ?? null) === $kind) : [];
                if (count($refs) !== 1) {
                    $errors[] = 'evidence_missing_or_duplicate:'.$kind;

                    continue;
                }
                $ref = array_values($refs)[0];
                $artifact = QaEvidence::where('public_id', $ref['evidence_id'] ?? '')->where('run_id', $run->id)->where('result_id', $result->id)->first();
                if ($artifact === null || $artifact->id === $review->id || ! hash_equals($artifact->sha256, (string) ($ref['sha256'] ?? '')) || ! $proofs->evidenceAvailable($artifact)
                    || ($kind === 'request_ids' && ! $artifact->request_id)
                    || ($kind === 'screenshot' && ! in_array($artifact->mime, ['image/png', 'image/jpeg', 'image/webp'], true))) {
                    $errors[] = 'evidence_invalid:'.$kind;
                } elseif (! $this->artifactValid($kind, $artifact, $definition)) {
                    $errors[] = 'evidence_contract_invalid:'.$kind;
                }
            }

            return $errors;
        } catch (Throwable) {
            return ['invalid_or_unavailable_review_evidence'];
        }
    }

    /** @param array<string, mixed> $definition */
    private function artifactValid(string $kind, QaEvidence $artifact, array $definition): bool
    {
        if ($kind === 'screenshot') {
            return true;
        }
        try {
            $data = json_decode(app(QaRuns::class)->evidenceBytes($artifact), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                return false;
            }
            $rules = match ($kind) {
                'http' => ['request.method' => 'required|string', 'request.url' => 'required|string', 'response.status' => 'required|integer|min:100|max:599', 'request_id' => 'required|string'],
                'database' => ['before' => 'present|array', 'after' => 'present|array', 'source_references' => 'required|array|min:1'],
                'request_ids' => ['request_ids' => 'required|array|min:1', 'request_ids.*' => 'required|string'],
                'provider_confirmation' => ['provider' => 'required|in:'.$definition['provider'], 'store_reference' => 'required|string', 'source_reference' => 'required|string', 'observed_state' => 'required|string', 'observed_at' => 'required|date', 'genuine_provider' => 'required|accepted'],
                'frontend_inventory' => ['git_sha' => ['required', 'regex:/\A[0-9a-f]{40,64}\z/D'], 'routes' => 'required|array|min:1', 'actions' => 'required|array|min:1', 'components' => 'required|array|min:1', 'ui_states' => 'required|array|min:1'],
                default => [],
            };

            return $rules !== [] && Validator::make($data, $rules)->passes()
                && ($kind !== 'request_ids' || in_array($artifact->request_id, $data['request_ids'], true))
                && ($kind !== 'frontend_inventory' || $data['git_sha'] === ($definition['frontend_git_sha'] ?? null));
        } catch (Throwable) {
            return false;
        }
    }
}
