<?php

namespace Tests\Feature\Qa;

use App\Enums\PlatformRole;
use App\Models\QaSchedule;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Qa\QaInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaCiImportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/shopbonus-qa-ci-'.Str::random(12);
        mkdir($this->directory, 0700);
        config(['qa.api_key' => 'ci-sensitive-fixture-key']);
        file_put_contents($this->directory.'/source.xml', '<testsuites><testsuite name="CI"><testcase classname="CI" name="passes" assertions="2" time="0.1"/><testcase classname="CI" name="fails" assertions="1"><failure message="ci-sensitive-fixture-key">ci-sensitive-fixture-key</failure></testcase></testsuite></testsuites>');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_ci_import_retains_failures_sha_execution_time_and_redacts_artifacts(): void
    {
        $at = now()->subMinute()->toIso8601String();
        $output = $this->directory.'/results.json';
        $this->artisan('shopbonus:qa-import', ['report' => $this->directory.'/source.xml', '--environment' => 'ci',
            '--sha' => 'abcdef1234567', '--executed-at' => $at, '--output' => $output])->assertSuccessful();
        $run = QaTestRun::sole();
        $this->assertSame('ci', $run->environment);
        $this->assertSame('abcdef1234567', $run->git_sha);
        $this->assertSame(1, $run->totals['fail']);
        $this->assertSame(2, $run->totals['mocked']);
        $artifact = json_decode(file_get_contents($output), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($at, $artifact['execution']['started_at']);
        $this->assertSame(hash_file('sha256', $this->directory.'/source.xml'), $artifact['run']['report_sha256']);
        $this->assertStringNotContainsString('ci-sensitive-fixture-key', file_get_contents($output));
        $this->assertStringNotContainsString('ci-sensitive-fixture-key', file_get_contents($this->directory.'/results.junit.xml'));
        $this->assertStringContainsString('<failure', file_get_contents($this->directory.'/results.junit.xml'));
        $this->assertSame(0600, fileperms($output) & 0777);
    }

    public function test_transitive_backend_changes_invalidate_routes_and_services_are_inventoried(): void
    {
        $fixture = app_path('Services/QaSyntheticDependency'.Str::random(12).'.php');
        try {
            file_put_contents($fixture, "<?php // synthetic dependency revision one\n");
            $inventory = app(QaInventory::class);
            $inventory->scan('aaaaaaa');
            $this->assertDatabaseHas('qa_components', ['kind' => 'service', 'name' => 'app/Services/Wallet/WalletService']);
            file_put_contents($fixture, "<?php // synthetic dependency revision two\n");
            $snapshot = $inventory->scan('bbbbbbb');
            $this->assertContains('GET /api/v1/consumer/wallet', $snapshot->diff['endpoints']['changed']);
            $this->assertGreaterThan(300, count($snapshot->diff['endpoints']['changed']));
        } finally {
            @unlink($fixture);
        }
    }

    public function test_existing_output_is_not_overwritten_or_imported(): void
    {
        $output = $this->directory.'/results.json';
        file_put_contents($output, 'existing artifact');
        $this->artisan('shopbonus:qa-import', ['report' => $this->directory.'/source.xml', '--environment' => 'ci',
            '--sha' => 'abcdef1', '--output' => $output])->assertFailed();
        $this->assertSame('existing artifact', file_get_contents($output));
        $this->assertSame(0, QaTestRun::count());
    }

    public function test_ci_cannot_be_imported_as_a_staging_schedule_or_scheduled_as_production(): void
    {
        $actor = User::factory()->create();
        $schedule = QaSchedule::create(['name' => 'Staging', 'suite' => 'full', 'environment' => 'staging',
            'trigger' => 'nightly', 'active' => true, 'timeout_minutes' => 30, 'created_by' => $actor->id]);
        $this->artisan('shopbonus:qa-import', ['report' => $this->directory.'/source.xml', '--environment' => 'ci',
            '--sha' => 'abcdef1', '--schedule' => $schedule->public_id])->assertFailed();
        $this->assertSame(0, QaTestRun::count());
        $actor->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/admin/qa/runs?environment=ci')->assertOk();
        $this->getJson('/api/v1/admin/qa/runs?environment=invalid')->assertUnprocessable();
    }
}
