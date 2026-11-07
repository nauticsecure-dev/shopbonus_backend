<?php

namespace App\Services\Qa;

use App\Enums\AuditCategory;
use App\Services\Audit\AuditLogger;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Executes only deployment-reviewed PHP entry points; never manifest instructions or AI output. */
class QaStagingRunner
{
    public function __construct(private readonly QaRunnerSource $source, private readonly QaInventory $inventory, private readonly QaRuns $runs, private readonly AuditLogger $audit, private readonly QaExecutionPlan $planner) {}

    /** @return array<string,mixed> */
    public function execute(string $trigger, string $sha): array
    {
        if (! config('qa.runner_enabled') || ! app()->environment('staging') || ! in_array($trigger, ['nightly', 'weekly', 'after_deploy'], true)
            || ! preg_match('/\A[0-9a-f]{40,64}\z/D', $sha) || $sha !== config('qa.release_sha')) {
            throw new RuntimeException('Enabled exact staging deployment required.');
        }
        $url = (string) config('qa.staging_url');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Configure an explicit HTTPS staging origin.');
        }
        // All triggers share one lock: nightly/deploy must not mutate the same staging fixtures concurrently.
        $repository = Cache::store((string) config('qa.runner_lock_store', 'database'));
        if (! $repository instanceof Repository || ! ($store = $repository->getStore()) instanceof LockProvider) {
            throw new RuntimeException('A lock-capable staging cache store is required.');
        }
        $lock = $store->lock('qa:staging-execution', 3700);
        if (! $lock->get()) {
            throw new RuntimeException('A staging execution is already active.');
        }
        $directory = null;
        try {
            $manifestPath = base_path('requirements/QA_RUNNERS.json');
            $manifestHash = (string) hash_file('sha256', $manifestPath);
            $manifestPath = $this->source->verify('requirements/QA_RUNNERS.json', $manifestHash, $sha);
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            $validation = Validator::make($manifest, [
                'schema_version' => ['required', 'in:1'], 'state' => ['required', 'in:reviewed'], 'review_reference' => ['required', 'string', 'max:500'],
                'runners' => ['required', 'array', 'min:1', 'max:10'], 'runners.*.id' => ['required', 'string', 'regex:/\A[a-z0-9_-]{1,80}\z/D', 'distinct'],
                'runners.*.path' => ['required', 'string', 'regex:~\Ascripts/qa-runners/[A-Za-z0-9_/-]+\.php\z~D', 'distinct'], 'runners.*.sha256' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
                'runners.*.triggers' => ['required', 'array', 'min:1'], 'runners.*.triggers.*' => ['required', 'in:nightly,weekly,after_deploy'],
                'runners.*.tests' => ['required', 'array', 'min:1', 'max:2000'],
                'runners.*.tests.*.key' => ['required', 'string', 'max:250'],
                'runners.*.tests.*.version' => ['required', 'integer', 'min:1'],
                'runners.*.tests.*.min_assertions' => ['required', 'integer', 'min:1'],
                'runners.*.tests.*.proposal' => ['sometimes', 'array:key,version', 'required_array_keys:key,version'],
                'runners.*.tests.*.proposal.key' => ['string', 'max:250'],
                'runners.*.tests.*.proposal.version' => ['integer', 'min:1'],
                'runners.*.timeout_seconds' => ['required', 'integer', 'between:1,300'],
            ]);
            if ($validation->fails()) {
                throw new RuntimeException('Reviewed staging runner registry required.');
            }
            $selected = array_values(array_filter($manifest['runners'], fn ($row) => in_array($trigger, $row['triggers'], true)));
            if ($selected === []) {
                throw new RuntimeException('No reviewed runner covers this trigger.');
            }
            // Validate every selected executable before discovery or any external side effect.
            $paths = [];
            foreach ($selected as $row) {
                $paths[$row['id']] = $this->source->verify($row['path'], $row['sha256'], $sha);
            }
            $snapshot = $this->inventory->scan($sha);
            $plans = $this->planner->build($selected, $trigger, $snapshot->diff, $sha);
            $selected = array_values(array_filter($selected, fn ($row) => $plans[$row['id']]['required_tests'] !== []));
            $directory = storage_path('app/private/qa-runner/'.Str::ulid());
            File::makeDirectory($directory, 0700, true);
            $context = ['schema_version' => 1, 'deployment_sha' => $sha, 'environment' => 'staging', 'trigger' => $trigger, 'base_url' => $url,
                'inventory' => ['id' => $snapshot->public_id, 'diff' => $snapshot->diff], 'execution_plan' => $plans, 'proves' => 'Execution only; independent acceptance evidence and review still required.'];
            $this->write($directory.'/context.json', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $executed = [];
            foreach ($selected as $row) {
                $this->source->verify($row['path'], $row['sha256'], $sha);
                $reportPath = $directory.'/'.$row['id'].'.xml';
                $process = new Process([PHP_BINARY, $paths[$row['id']]], base_path(), ['QA_RUN_CONTEXT' => $directory.'/context.json', 'QA_JUNIT_PATH' => $reportPath], null, $row['timeout_seconds']);
                $started = now()->toIso8601String();
                $size = 0;
                try {
                    $process->start(function (string $type, string $buffer) use (&$size): void {
                        $size += strlen($buffer);
                    });
                    while ($process->isRunning()) {
                        $process->clearOutput();
                        $process->clearErrorOutput();
                        if ($size > 1048576) {
                            throw new RuntimeException('Runner output limit exceeded.');
                        }
                        $process->checkTimeout();
                        usleep(20000);
                    }
                    if ($size > 1048576) {
                        throw new RuntimeException('Runner output limit exceeded.');
                    }
                } finally {
                    if ($process->isRunning()) {
                        $process->stop(1);
                    }
                }
                clearstatcache(true, $reportPath);
                if (! is_file($reportPath) || is_link($reportPath) || filesize($reportPath) > (int) config('qa.max_report_kb') * 1024) {
                    throw new RuntimeException('Runner did not produce a bounded JUnit report.');
                }
                $run = $this->runs->importJunit((string) file_get_contents($reportPath), ['suite' => match ($trigger) {
                    'nightly' => 'full', 'weekly' => 'weekly', default => 'changed'
                },
                    'environment' => 'staging', 'git_sha' => $sha, 'source' => 'staging_runner', 'executed_at' => $started, 'process_exit_code' => $process->getExitCode() ?? -1, 'execution_plan' => $plans[$row['id']]], null);
                $planMatches = $this->planner->reportMatches($run, $plans[$row['id']]);
                $failed = ! $planMatches || ! $process->isSuccessful() || ($run->totals['pass'] ?? 0) !== ($run->totals['total'] ?? 0) || ($run->totals['mocked'] ?? 0) > 0;
                $executed[] = ['runner' => $row['id'], 'run_id' => $run->public_id, 'execution_passed' => ! $failed, 'required_tests_executed' => $planMatches];
                $this->audit->record('qa.staging_runner_executed', category: AuditCategory::Admin, metadata: ['runner' => $row['id'], 'source_sha256' => $row['sha256'], 'registry_sha256' => $manifestHash,
                    'review_reference' => $manifest['review_reference'], 'git_sha' => $sha, 'run' => $run->public_id, 'execution_passed' => ! $failed, 'required_tests_executed' => $planMatches, 'required_test_keys' => array_column($plans[$row['id']]['required_tests'], 'key')]);
                if ($failed) {
                    throw new RuntimeException('Staging runner execution failed; imported results are retained.');
                }
            }

            return ['deployment_sha' => $sha, 'trigger' => $trigger, 'executions' => $executed, 'acceptance_proven' => false];
        } finally {
            if ($directory !== null) {
                File::deleteDirectory($directory);
            }
            $lock->release();
        }
    }

    private function write(string $path, string $bytes): void
    {
        $stream = fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Runner context unavailable.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Runner context incomplete.');
            }
        } finally {
            fclose($stream);
        }
    }
}
