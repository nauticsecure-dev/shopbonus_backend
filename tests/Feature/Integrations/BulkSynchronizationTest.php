<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\BulkSynchronization;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\WooCommerceConnector;
use App\Jobs\RecoverProviderOrder;
use App\Models\IntegrationOrderRecovery;
use App\Models\IntegrationSyncRun;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $connection;

    private object $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RecoverProviderOrder::class]);
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        [$this->connection] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::WooCommerce, ['store_url' => 'https://fietsen-utrecht.nl', 'consumer_key' => 'ck_SYNTHETIC_key', 'consumer_secret' => 'cs_SYNTHETIC_secret']);
        $this->reader = new class extends WooCommerceConnector
        {
            public int $calls = 0;

            public bool $cycle = false;

            public ?\Closure $during = null;

            public function discoverOrders(WebshopConnection $c, CarbonImmutable $s, CarbonImmutable $u, ?string $cursor): array
            {
                $this->calls++;
                if ($this->during) {
                    ($this->during)();
                }

                return ['ids' => ['4711', '4711', '4712'], 'next' => $cursor === null ? 'PAGE2' : ($this->cycle ? 'PAGE2' : null)];
            }
        };
        $this->app->instance(WooCommerceConnector::class, $this->reader);
    }

    private function start(?string $key = null): IntegrationSyncRun
    {
        return app(BulkSynchronization::class)->request($this->connection, CarbonImmutable::now()->subDay(), CarbonImmutable::now(), $key ?? (string) Str::uuid());
    }

    public function test_pages_resume_and_wait_for_canonical_recoveries_before_completion(): void
    {
        $s = app(BulkSynchronization::class);
        $r = $this->start();
        $s->run($r->id);
        $this->assertSame('PAGE2', $r->fresh()->cursor);
        $this->assertSame(2, IntegrationOrderRecovery::count());
        $this->assertSame(['reconciliation'], IntegrationOrderRecovery::pluck('purpose')->unique()->values()->all());
        $s->run($r->id);
        $this->assertSame(2, $r->fresh()->pages);
        $this->assertSame(2, IntegrationOrderRecovery::count());
        $s->run($r->id);
        $this->assertSame('waiting', $r->fresh()->status);
        IntegrationOrderRecovery::query()->update(['status' => 'succeeded']);
        $s->run($r->id);
        $this->assertSame('completed', $r->fresh()->status);
        $s->run($r->id);
        $this->assertSame(2, $this->reader->calls);
    }

    public function test_uuid_conflict_and_replay_are_bound_to_window(): void
    {
        $this->freezeTime();
        $key = (string) Str::uuid();
        $r = $this->start($key);
        $this->assertSame($r->id, $this->start($key)->id);
        $this->expectException(ApiException::class);
        app(BulkSynchronization::class)->request($this->connection, CarbonImmutable::now()->subDays(2), CarbonImmutable::now(), $key);
    }

    public function test_repeated_cursor_is_failed_without_advancing_or_duplicate_requests(): void
    {
        $this->reader->cycle = true;
        $s = app(BulkSynchronization::class);
        $r = $this->start();
        $s->run($r->id);
        $s->run($r->id);
        $this->assertSame('failed', $r->fresh()->status);
        $this->assertSame('provider_pagination_invalid', $r->fresh()->last_error_code);
        $this->assertSame(1, $r->fresh()->pages);
        $this->assertSame(2, IntegrationOrderRecovery::count());
    }

    public function test_rotation_during_read_discards_stale_discovery(): void
    {
        $this->reader->during = fn () => app(ConnectionManager::class)->replaceCredentials($this->connection, []);
        $r = $this->start();
        app(BulkSynchronization::class)->run($r->id);
        $this->assertSame('superseded', $r->fresh()->status);
        $this->assertSame(0, IntegrationOrderRecovery::count());
    }

    public function test_incomplete_recovery_cannot_be_reported_as_completed(): void
    {
        $s = app(BulkSynchronization::class);
        $r = $this->start();
        $s->run($r->id);
        $s->run($r->id);
        IntegrationOrderRecovery::query()->update(['status' => 'failed']);
        $s->run($r->id);
        $this->assertSame('failed', $r->fresh()->status);
    }
}
