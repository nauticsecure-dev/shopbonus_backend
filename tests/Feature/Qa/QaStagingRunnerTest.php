<?php

namespace Tests\Feature\Qa;

use App\Models\QaEndpoint;
use App\Models\QaInventorySnapshot;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Services\Qa\QaInventory;
use App\Services\Qa\QaRunnerSource;
use App\Services\Qa\QaStagingRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QaStagingRunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/shopbonus-staging-runner-'.Str::random(12);
        mkdir($this->directory, 0700);
        $this->sha = str_repeat('a', 40);
        $this->app['env'] = 'staging';
        config(['qa.runner_lock_store' => 'array', 'qa.runner_enabled' => true, 'qa.release_sha' => $this->sha, 'qa.staging_url' => 'https://staging.example.test', 'qa.max_report_kb' => 10]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function fixture(string $body, string $state = 'reviewed', int $timeout = 2): void
    {
        $script = $this->directory.'/fixture.php';
        file_put_contents($script, '<?php '.$body);
        $manifest = ['schema_version' => 1, 'state' => $state, 'review_reference' => 'Synthetic reviewed script fixture, not staging proof', 'runners' => [
            ['id' => 'fixture', 'path' => 'scripts/qa-runners/fixture.php', 'sha256' => hash_file('sha256', $script), 'triggers' => ['nightly', 'weekly', 'after_deploy'], 'timeout_seconds' => $timeout, 'tests' => [['key' => 'Staging::executed', 'version' => 1, 'min_assertions' => 2]]],
        ]];
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest));
        $source = Mockery::mock(QaRunnerSource::class);
        $source->shouldReceive('verify')->andReturnUsing(fn ($path) => $path === 'requirements/QA_RUNNERS.json' ? $this->directory.'/manifest.json' : $script);
        $this->app->instance(QaRunnerSource::class, $source);
        $case = QaTestCase::firstOrCreate(['key' => 'Staging::executed'], ['title' => 'Synthetic executed case', 'source' => 'phpunit', 'status' => 'approved', 'version' => 1]);
        $endpoint = QaEndpoint::firstOrCreate(['method' => 'GET', 'path' => '/api/v1/health'], ['access' => 'public', 'scope' => 'platform', 'documented' => true, 'fingerprint' => str_repeat('a', 64), 'first_seen_sha' => $this->sha, 'changed_sha' => $this->sha, 'changed_at' => now()]);
        DB::table('qa_test_coverage')->insertOrIgnore(['test_case_id' => $case->id, 'target_type' => 'endpoint', 'target_id' => $endpoint->id, 'created_at' => now(), 'updated_at' => now()]);
        $inventory = Mockery::mock(QaInventory::class);
        $inventory->shouldReceive('scan')->with($this->sha)->andReturn(new QaInventorySnapshot(['public_id' => strtolower((string) Str::ulid()), 'diff' => ['endpoints' => ['changed' => ['GET /api/v1/health']]]]));
        $this->app->instance(QaInventory::class, $inventory);
    }

    private function report(): string
    {
        return 'file_put_contents(getenv("QA_JUNIT_PATH"), \'<testsuites><testsuite><testcase classname="Staging" name="executed" assertions="2"/></testsuite></testsuites>\');';
    }

    public function test_real_script_receives_impact_context_and_imports_execution_without_acceptance(): void
    {
        $this->fixture('$c=json_decode(file_get_contents(getenv("QA_RUN_CONTEXT")),true);if($c["environment"]!=="staging" || $c["trigger"]!=="after_deploy" || $c["inventory"]["diff"]["endpoints"]["changed"]!==["GET /api/v1/health"])exit(7);'.$this->report());
        $result = app(QaStagingRunner::class)->execute('after_deploy', $this->sha);
        $this->assertFalse($result['acceptance_proven']);
        $this->assertTrue($result['executions'][0]['execution_passed']);
        $run = QaTestRun::sole();
        $this->assertSame($this->sha, $run->git_sha);
        $this->assertSame('changed', $run->suite);
        $this->assertSame('staging', $run->environment);
        $this->assertNull($run->created_by); // Automated execution cannot impersonate an independent human executor.
        $this->assertSame(1, $run->totals['pass']);
        $this->assertSame('approved', QaTestCase::sole()->status); // Pre-reviewed fixture case; execution still cannot prove acceptance.
        $this->assertDatabaseHas('audit_events', ['action' => 'qa.staging_runner_executed']);
    }

    public function test_environment_build_disabled_and_unsafe_origin_are_rejected_before_execution(): void
    {
        $this->fixture($this->report());
        foreach ([['qa.runner_enabled' => false], ['qa.release_sha' => str_repeat('b', 40)], ['qa.staging_url' => 'http://production.example.test'], ['qa.staging_url' => 'https://user:secret@staging.example.test']] as $change) {
            config(['qa.runner_lock_store' => 'array', 'qa.runner_enabled' => true, 'qa.release_sha' => $this->sha, 'qa.staging_url' => 'https://staging.example.test']);
            config($change);
            try {
                app(QaStagingRunner::class)->execute('nightly', $this->sha);
                $this->fail('Unsafe execution accepted.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('qa_test_runs', 0);
            }
        }
        config(['qa.runner_lock_store' => 'array', 'qa.runner_enabled' => true, 'qa.release_sha' => $this->sha, 'qa.staging_url' => 'https://staging.example.test']);
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        app(QaStagingRunner::class)->execute('nightly', $this->sha);
    }

    public function test_unreviewed_registry_and_concurrent_execution_are_blocked(): void
    {
        $this->fixture($this->report(), 'unmapped');
        try {
            app(QaStagingRunner::class)->execute('nightly', $this->sha);
            $this->fail('Unreviewed runner accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('qa_test_runs', 0);
        }
        $this->fixture($this->report());
        $lock = Cache::lock('qa:staging-execution', 60);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(RuntimeException::class);
            app(QaStagingRunner::class)->execute('nightly', $this->sha);
        } finally {
            $lock->release();
        }
    }

    public function test_nonzero_execution_imports_report_but_fails_and_releases_lock(): void
    {
        $this->fixture($this->report().'exit(9);');
        try {
            app(QaStagingRunner::class)->execute('nightly', $this->sha);
            $this->fail('Nonzero execution accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('qa_test_runs', 1);
            $this->assertSame(1, QaTestRun::sole()->totals['blocked']);
            $this->assertSame('blocked', QaTestResult::sole()->status);
        }
        $lock = Cache::lock('qa:staging-execution', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_timeout_and_missing_report_cannot_be_pass(): void
    {
        foreach (['sleep(3);', 'exit(0);', 'echo str_repeat("x", 1100000);'.$this->report()] as $body) {
            $this->fixture($body, 'reviewed', 1);
            try {
                app(QaStagingRunner::class)->execute('weekly', $this->sha);
                $this->fail('Incomplete execution accepted.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('qa_test_runs', 0);
            }
        }
    }

    public function test_command_cannot_skip_acceptance_after_successful_execution(): void
    {
        $this->fixture($this->report());
        $this->artisan('shopbonus:qa-staging-run', ['trigger' => 'nightly', '--sha' => $this->sha])->assertFailed();
        $this->assertDatabaseCount('qa_test_runs', 1);
    }

    public function test_unmapped_impact_is_blocked_before_a_child_can_execute(): void
    {
        $marker = $this->directory.'/executed';
        $this->fixture('file_put_contents('.var_export($marker, true).', "executed");'.$this->report());
        DB::table('qa_test_coverage')->delete();
        try {
            app(QaStagingRunner::class)->execute('after_deploy', $this->sha);
            $this->fail('Unmapped impact executed.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($marker);
            $this->assertDatabaseCount('qa_test_runs', 0);
        }
    }

    public function test_unrelated_green_report_is_retained_as_blocked_execution(): void
    {
        $this->fixture('file_put_contents(getenv("QA_JUNIT_PATH"), \'<testsuites><testsuite><testcase classname="Unrelated" name="test" assertions="10"/></testsuite></testsuites>\');');
        try {
            app(QaStagingRunner::class)->execute('nightly', $this->sha);
            $this->fail('Unrelated green assertions qualified.');
        } catch (RuntimeException) {
            $this->assertSame(1, QaTestRun::sole()->totals['blocked']);
            $this->assertSame('blocked', QaTestResult::sole()->status);
        }
    }

    public function test_source_verification_rejects_uncommitted_tampered_and_foreign_files(): void
    {
        mkdir($this->directory.'/requirements');
        $path = $this->directory.'/requirements/QA_RUNNERS.json';
        file_put_contents($path, '{"state":"reviewed"}');
        foreach ([['git', 'init', '--quiet'], ['git', 'add', 'requirements/QA_RUNNERS.json'], ['git', '-c', 'user.name=QA fixture', '-c', 'user.email=qa@example.test', 'commit', '--quiet', '-m', 'Synthetic reviewed registry']] as $argv) {
            (new Process($argv, $this->directory))->mustRun();
        }
        $head = new Process(['git', 'rev-parse', 'HEAD'], $this->directory);
        $head->mustRun();
        $sha = trim($head->getOutput());
        $hash = hash_file('sha256', $path);
        $source = new QaRunnerSource($this->directory);
        $this->assertSame(realpath($path), $source->verify('requirements/QA_RUNNERS.json', $hash, $sha));
        file_put_contents($path, 'tampered');
        foreach ([['requirements/QA_RUNNERS.json', $hash, $sha], ['requirements/QA_RUNNERS.json', hash_file('sha256', $path), $sha], ['../foreign.php', $hash, $sha], ['requirements/QA_RUNNERS.json', $hash, $this->sha]] as $args) {
            try {
                $source->verify(...$args);
                $this->fail('Changed/untracked source accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }
}
