<?php

namespace Tests\Feature\Support;

use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Support\SupportChat;
use App\Services\Support\SupportPolicy;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

class ConcurrentSupportTest extends TestCase
{
    use DatabaseTruncation, RacesProcesses {
        DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables;
    }

    protected function truncateDatabaseTables(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->truncateCommittedTables();
        }
    }

    protected function tearDown(): void
    {
        $this->truncateAfterRace();
        parent::tearDown();
    }

    public function test_simultaneous_identical_chat_requests_create_one_message_and_job(): void
    {
        $this->skipUnlessRealDatabase();
        Queue::fake();
        $u = User::factory()->create();
        $key = (string) Str::uuid();
        $results = $this->race(2, fn ($n) => app(SupportChat::class)->submit($u, ['message' => 'wallet'], $key)->public_id);
        $this->assertSame($results[0], $results[1], implode(', ', $results));
        $this->assertSame(1, SupportMessage::count());
        $this->assertSame(1, DB::table('support_conversations')->count());
    }

    public function test_concurrent_workers_cannot_exceed_one_global_provider_call(): void
    {
        $this->skipUnlessRealDatabase();
        Queue::fake();
        config(['support_ai.enabled' => true, 'support_ai.api_key' => 'fixture', 'support_ai.model' => 'fixture-model', 'support_ai.daily_request_limit' => 1]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $settings = app(PlatformSettings::class);
        $v = $settings->draft($a, 'support.ai', ['enabled' => true, 'model' => null, 'languages' => ['en', 'nl'], 'daily_request_limit' => 1, 'timeout_seconds' => 5, 'max_retry' => 1, 'restrictions' => []], 'Race test policy');
        app(SupportPolicy::class)->testVersion($v, $a);
        $settings->submit($v, $a);
        $settings->publish($v, $b, 'Independent race review');
        $m1 = app(SupportChat::class)->submit($a, ['message' => 'wallet'], (string) Str::uuid());
        $m2 = app(SupportChat::class)->submit($b, ['message' => 'wallet'], (string) Str::uuid());
        Http::fake(function (Request $r) {
            $input = json_decode($r['input'], true);

            return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => json_encode(['evidence_ids' => array_column($input['evidence'], 'evidence_id')])]]]);
        });
        $ids = [$m1->id, $m2->id];
        $results = $this->race(2, function ($n) use ($ids) {
            app(SupportChat::class)->execute($ids[$n]);

            return SupportMessage::findOrFail($ids[$n])->status;
        });
        sort($results);
        $this->assertSame(['UNAVAILABLE', 'VERIFIED'], $results, implode(', ', $results));
        $this->assertSame(1, DB::table('support_quotas')->where('scope', 'global')->value('requests'));
        $this->assertSame(1, SupportMessage::where('failure_code', 'quota_exceeded')->count());
    }
}
