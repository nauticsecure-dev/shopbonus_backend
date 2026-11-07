<?php

namespace App\Services\Qa;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\QaEndpoint;
use App\Models\QaTestCase;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Fixed read-only denial regressions only. Model prose is never interpolated into executable PHP. */
class QaTestGenerator
{
    public function __construct(private readonly QaExecutions $authority) {}

    /** @return array<string,mixed> */
    public function generate(QaTestCase $proposal, QaEndpoint $endpoint, User $actor): array
    {
        $this->authority->authorize($actor);
        if ($proposal->source !== 'ai' || ! in_array($proposal->status, ['draft', 'approved'], true) || $endpoint->removed_at !== null
            || $endpoint->method !== 'GET' || ! in_array($endpoint->access, ['authenticated', 'verified', 'admin'], true)
            || ! str_starts_with($endpoint->path, '/api/v1/') || preg_match('/[{}?#\r\n]/', $endpoint->path)) {
            throw new ApiException('Choose an open AI proposal and a concrete protected GET endpoint.', 422, 'qa_generation_unsupported');
        }
        $covered = DB::table('qa_test_coverage')->where('test_case_id', $proposal->id)->where('target_type', 'endpoint')->where('target_id', $endpoint->id)->exists();
        if (! $covered) {
            throw ApiException::conflict('Link the proposal to the discovered endpoint before generation.', 'qa_generation_coverage_required');
        }
        $id = 'generated-'.strtolower($proposal->public_id).'-v'.$proposal->version.'-'.substr(hash('sha256', $endpoint->public_id.':'.$endpoint->fingerprint), 0, 20);
        $key = 'GeneratedDenial::'.str_replace('-', '_', $id);
        $frozen = ['runner_id' => $id, 'test_key' => $key, 'endpoint' => $endpoint->method.' '.$endpoint->path, 'endpoint_fingerprint' => $endpoint->fingerprint,
            'path' => $endpoint->path, 'proposal' => ['key' => $proposal->key, 'version' => $proposal->version], 'expected_status' => 401, 'expected_error' => 'unauthenticated'];
        $template = (string) file_get_contents(base_path('resources/qa/protected-get-runner.php.txt'));
        // JSON is encoded into a PHP string literal, not used as code or a shell command.
        $code = str_replace('__FROZEN_PLAN__', var_export(json_encode($frozen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true), $template);
        $relative = 'scripts/qa-runners/'.$id.'.php';
        $path = base_path($relative);
        File::ensureDirectoryExists(dirname($path));
        if (is_file($path) && ! hash_equals(hash('sha256', $code), (string) hash_file('sha256', $path))) {
            throw ApiException::conflict('Generated executable already exists with different bytes.', 'qa_generation_conflict');
        }
        if (! is_file($path)) {
            File::put($path, $code);
        }
        $case = QaTestCase::firstOrCreate(['key' => $key], ['title' => 'Anonymous denial: '.$endpoint->path, 'scenario' => 'Generated protected GET denial only; not the full AI scenario or provider acceptance.', 'source' => 'phpunit', 'status' => 'draft', 'created_by' => $actor->id, 'version' => 1]);
        DB::table('qa_test_coverage')->updateOrInsert(['test_case_id' => $case->id, 'target_type' => 'endpoint', 'target_id' => $endpoint->id], ['updated_at' => now(), 'created_at' => now()]);

        app(AuditLogger::class)->record('qa.test_generated', category: AuditCategory::Admin, actorUserId: $actor->id, metadata: ['proposal' => $proposal->public_id, 'endpoint' => $endpoint->public_id, 'path' => $relative, 'sha256' => hash('sha256', $code), 'scope' => 'protected_get_denial_only', 'review_required' => true]);

        return ['id' => $id, 'path' => $relative, 'sha256' => hash('sha256', $code), 'triggers' => ['nightly', 'weekly', 'after_deploy'], 'timeout_seconds' => 30,
            'tests' => [['key' => $case->key, 'version' => $case->version, 'min_assertions' => 3, 'discovery' => ['endpoint_id' => $endpoint->public_id, 'fingerprint' => $endpoint->fingerprint], 'proposal' => $frozen['proposal']]],
            'review_required' => true, 'coverage_scope' => 'Concrete protected GET anonymous denial only; broader scenario assertions require a separate reviewed executor.'];
    }
}
