<?php

namespace Tests\Feature\Affiliate;

use App\Affiliate\Adapters\DaisyconAdapter;
use App\Affiliate\NetworkFailure;
use App\Jobs\SyncDaisyconEvidence;
use App\Models\AffiliateConnection;
use App\Services\Affiliate\AffiliateSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DaisyconCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): AffiliateConnection
    {
        return AffiliateConnection::create(['network' => 'daisycon', 'name' => 'Fixture', 'status' => 'connected',
            'credentials' => ['publisher_id' => '123', 'media_id' => '9', 'access_token' => 'fixture'], 'postback_token_hash' => hash('sha256', 'fixture'), 'last_synced_at' => now()->subDay()]);
    }

    private function program(string $id): array
    {
        return ['id' => $id, 'name' => 'Fixture '.$id, 'status' => 'active', 'commission' => ['type' => 'percentage', 'value' => '10']];
    }

    public function test_programs_and_conversions_include_second_pages_even_when_server_caps_page_size(): void
    {
        Http::fake(function ($request) {
            $page = $request['page'];
            if (str_contains($request->url(), '/programs')) {
                return Http::response($page < 3 ? [$this->program('P'.$page)] : []);
            }

            return Http::response($page < 3 ? [['id' => 'T'.$page, 'program_id' => 'P1', 'date' => now()->toIso8601String()]] : []);
        });
        $connection = $this->connection();
        $adapter = app(DaisyconAdapter::class);
        $this->assertSame(['P1', 'P2'], array_map(fn ($p) => $p->externalId, $adapter->fetchPrograms($connection)));
        $this->assertSame(['T1', 'T2'], array_map(fn ($t) => $t->externalId, $adapter->fetchTransactions($connection, null)));
        Http::assertSentCount(6);
    }

    public function test_repeated_or_truncated_pages_never_advance_durable_sync_checkpoint(): void
    {
        $connection = $this->connection();
        $checkpoint = $connection->last_synced_at;
        Http::fake(fn () => Http::response([$this->program('P1')]));
        $result = app(AffiliateSync::class)->sync($connection);
        $this->assertSame('inconsistent_pagination', $result['error']);
        $this->assertTrue($checkpoint->equalTo($connection->fresh()->last_synced_at));
        $this->assertDatabaseCount('affiliate_merchant_programs', 0);
        Http::fakeSequence()->push([$this->program('P1')], 200, ['X-Total-Count' => '2'])->push([], 200, ['X-Total-Count' => '2']);
        $this->expectException(NetworkFailure::class);
        app(DaisyconAdapter::class)->fetchPrograms($connection);
    }

    public function test_page_limit_is_an_error_and_not_partial_success(): void
    {
        config(['shopbonus.affiliate.networks.daisycon.max_pages' => 1]);
        Http::fake(fn () => Http::response([$this->program('P1')]));
        $this->expectException(NetworkFailure::class);
        $this->expectExceptionMessage('pagination_limit_reached');
        app(DaisyconAdapter::class)->fetchPrograms($this->connection());
    }

    public function test_separate_resource_snapshots_preserve_previous_complete_data_on_failure(): void
    {
        $connection = $this->connection();
        Http::fake(['*/publishers/123/programs/P1/access-rules' => Http::sequence()->push([['media_type' => 'cashback', 'allowed' => false]])->pushStatus(503)]);
        $job = new SyncDaisyconEvidence($connection->id, 'access-rules', 'P1');
        $job->handle(app(DaisyconAdapter::class));
        $payload = DB::table('affiliate_provider_resources')->sole()->payload;
        try {
            $job->handle(app(DaisyconAdapter::class));
            $this->fail('Provider failure should remain retryable.');
        } catch (\Illuminate\Http\Client\RequestException) {
            $this->assertSame($payload, DB::table('affiliate_provider_resources')->sole()->payload);
            $this->assertSame('provider_resource_failed', DB::table('affiliate_provider_resources')->sole()->last_error);
        }
        $this->assertDatabaseCount('offer_versions', 0);
    }
}
