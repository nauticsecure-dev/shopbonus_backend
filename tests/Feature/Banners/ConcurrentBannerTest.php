<?php

namespace Tests\Feature\Banners;

use App\Jobs\GenerateBannerProposals;
use App\Models\BannerAiRequest;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Services\Banners\BannerAi;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\RacesProcesses;
use Tests\Support\BannerFixture;
use Tests\TestCase;

class ConcurrentBannerTest extends TestCase
{
    use CreatesActiveWebshops;
    use DatabaseTruncation, RacesProcesses {
        DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables;
    }

    /**
     * On SQLite these tests are skipped; truncating would mark migrations as
     * done and leave later in-memory RefreshDatabase tests without tables.
     */
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

    public function test_two_ai_requests_cannot_exceed_global_daily_quota(): void
    {
        $this->skipUnlessRealDatabase();
        config(['banner_ai.enabled' => true, 'banner_ai.api_key' => 'fixture', 'banner_ai.model' => 'fixture-model', 'banner_ai.daily_request_limit' => 1]);
        Bus::fake([GenerateBannerProposals::class]);
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $campaign = app(BannerService::class)->create($shop, 'Race', null, BannerFixture::config(), $owner->id);
        $results = $this->race(2, fn ($worker) => 'ok:'.app(BannerAi::class)->request(BannerCampaign::findOrFail($campaign->id), $owner->id, 'designs', (string) Str::uuid())->public_id);
        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $this->assertContains('err:banner_ai_limit', $results);
        $this->assertSame(1, BannerAiRequest::count());
        $this->assertSame(1, DB::table('banner_ai_quotas')->value('requests'));
    }

    public function test_simultaneous_assignment_retries_keep_one_arm_and_token(): void
    {
        $this->skipUnlessRealDatabase();
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner, ['verified_domain' => 'example.nl']);
        $banners = app(BannerService::class);
        $campaign = $banners->create($shop, 'Race', null, BannerFixture::config(), $owner->id);
        $banners->publish($campaign, $owner->id);
        app(BannerExperiments::class)->start($campaign, null, 1, 5000, $owner->id);
        $installation = $banners->installation($shop);
        $visitor = (string) Str::uuid();
        $results = $this->race(2, fn ($worker) => app(BannerExperiments::class)->assign($installation, $visitor)[0]['assignment_token']);
        $this->assertSame($results[0], $results[1], implode(', ', $results));
        $this->assertStringStartsWith('bx_', $results[0]);
        $this->assertSame(1, DB::table('banner_assignments')->count());
    }
}
