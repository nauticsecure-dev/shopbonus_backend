<?php

namespace Tests\Feature\Qa;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\QaEndpoint;
use App\Models\QaTestCase;
use App\Models\User;
use App\Services\Qa\QaExecutionPlan;
use App\Services\Qa\QaTestGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QaTestGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_code_is_fixed_read_only_and_requires_both_approvals_and_current_discovery(): void
    {
        $actor = User::factory()->create();
        $actor->platformRoles()->create(['role' => PlatformRole::QaTester, 'granted_via' => 'console', 'granted_at' => now()]);
        $endpoint = QaEndpoint::create(['method' => 'GET', 'path' => '/api/v1/admin/synthetic-protected', 'access' => 'admin', 'scope' => 'admin', 'documented' => true, 'fingerprint' => str_repeat('a', 64), 'first_seen_sha' => str_repeat('b', 40), 'changed_sha' => str_repeat('b', 40), 'changed_at' => now()]);
        $proposal = QaTestCase::create(['key' => 'AI-synthetic', 'title' => 'Never execute model code: <?php exit;', 'source' => 'ai', 'status' => 'draft', 'version' => 1]);
        DB::table('qa_test_coverage')->insert(['test_case_id' => $proposal->id, 'target_type' => 'endpoint', 'target_id' => $endpoint->id]);
        $bytes = '';
        File::shouldReceive('ensureDirectoryExists')->once();
        File::shouldReceive('put')->once()->andReturnUsing(function ($path, $code) use (&$bytes) {
            $bytes = $code;

            return strlen($code);
        });
        $runner = app(QaTestGenerator::class)->generate($proposal, $endpoint, $actor);
        $this->assertStringNotContainsString('Never execute model code', $bytes);
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $bytes);
        $this->assertStringContainsString('/api/v1/admin/synthetic-protected', $bytes);
        $this->assertSame(hash('sha256', $bytes), $runner['sha256']);
        $path = tempnam(sys_get_temp_dir(), 'qa-generated-');
        try {
            file_put_contents($path, $bytes);
            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();
            $this->assertTrue($lint->isSuccessful(), $lint->getOutput().$lint->getErrorOutput());
        } finally {
            unlink($path);
        }
        $executable = QaTestCase::where('key', $runner['tests'][0]['key'])->sole();
        $this->assertSame('draft', $executable->status);
        $this->assertTrue($runner['review_required']);
        $executable->update(['status' => 'approved']);
        $proposal->update(['status' => 'approved']);
        $plan = app(QaExecutionPlan::class)->build([$runner], 'nightly', [], str_repeat('b', 40));
        $this->assertCount(1, $plan[$runner['id']]['required_tests']);
        $endpoint->update(['fingerprint' => str_repeat('c', 64)]);
        $this->expectException(\RuntimeException::class);
        app(QaExecutionPlan::class)->build([$runner], 'nightly', [], str_repeat('b', 40));
    }

    public function test_public_mutating_and_unlinked_endpoints_cannot_be_generated(): void
    {
        $actor = User::factory()->create();
        $actor->platformRoles()->create(['role' => PlatformRole::QaTester, 'granted_via' => 'console', 'granted_at' => now()]);
        $proposal = QaTestCase::create(['key' => 'AI-unlinked', 'title' => 'Synthetic', 'source' => 'ai', 'status' => 'draft', 'version' => 1]);
        $endpoint = QaEndpoint::create(['method' => 'POST', 'path' => '/api/v1/admin/synthetic-mutation', 'access' => 'admin', 'scope' => 'admin', 'documented' => true, 'fingerprint' => str_repeat('a', 64), 'first_seen_sha' => str_repeat('b', 40), 'changed_sha' => str_repeat('b', 40), 'changed_at' => now()]);
        File::shouldReceive('put')->never();
        $this->expectException(ApiException::class);
        app(QaTestGenerator::class)->generate($proposal, $endpoint, $actor);
    }
}
