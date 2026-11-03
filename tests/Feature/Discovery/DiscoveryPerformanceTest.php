<?php

namespace Tests\Feature\Discovery;

use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Discovery\DiscoveryCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Opt-in local HTTP-kernel benchmark; never a substitute for staging load tests. */
class DiscoveryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_search_p95(): void
    {
        if (! getenv('DISCOVERY_BENCHMARK')) {
            $this->markTestSkipped('Run with DISCOVERY_BENCHMARK=1 to measure 100 cold and 100 warm searches.');
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        // Representative scale assumption: 200 shops, 1000 offers, 2000 codes.
        // Includes future, expired, allocation-limited and obtainable offers.
        Model::withoutEvents(function () use ($owner) {
            for ($i = 0; $i < 200; $i++) {
                $shop = Webshop::factory()->approved()->create(['created_by' => $owner->id]);
                WebshopPublicProfile::create(['webshop_id' => $shop->id, 'slug' => 'benchmark-'.$i, 'display_name' => 'Benchmark Shop '.$i,
                    'short_description' => 'Representative directory fixture', 'published' => true, 'first_published_at' => now()]);
                DB::table('webshop_public_categories')->insert(['webshop_id' => $shop->id, 'category' => $i % 2 ? 'fashion' : 'home_living']);
                for ($j = 0; $j < 5; $j++) {
                    $offer = Offer::create(['webshop_id' => $shop->id, 'acquisition' => 'merchant_reward', 'status' => 'active',
                        'slug' => "benchmark-{$i}-{$j}", 'created_by' => $owner->id, 'activated_at' => now()]);
                    $version = OfferVersion::create(['offer_id' => $offer->id, 'version' => 1, 'name' => 'Benchmark offer',
                        'discount_type' => 'fixed', 'discount_value' => 1000, 'created_by' => $owner->id,
                        'starts_at' => $j === 1 ? now()->addDay() : null, 'ends_at' => $j === 2 ? now()->subDay() : null,
                        'allocation_limit' => $j === 3 ? 0 : null]);
                    $offer->forceFill(['current_version_id' => $version->id])->save();
                    for ($k = 0; $k < 2; $k++) {
                        $code = "BENCH-{$i}-{$j}-{$k}";
                        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code,
                            'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4), 'status' => 'available']);
                    }
                }
            }
        });
        $paths = [
            '/api/v1/public/webshops?search=Benchmark&sort=most_offers&per_page=12',
            '/api/v1/public/webshops?search=Shop&category=home_living&has_offers=1&sort=most_offers&per_page=12',
            '/api/v1/public/webshops?search=Shop%201&sort=name&per_page=12',
            '/api/v1/public/webshops?search=no-match&sort=most_offers&per_page=12',
        ];
        $stats = [];
        foreach (['cold', 'warm'] as $mode) {
            $samples = [];
            foreach (range(0, 99) as $i) {
                if ($mode === 'cold') {
                    DiscoveryCache::flush();
                }
                $start = hrtime(true);
                $this->getJson($paths[$i % count($paths)])->assertOk();
                $samples[] = (hrtime(true) - $start) / 1e6;
            }
            sort($samples);
            $stats[$mode] = ['samples' => count($samples), 'p50_ms' => round($samples[49], 2), 'p95_ms' => round($samples[94], 2), 'max_ms' => round(max($samples), 2)];
        }
        $report = ['environment' => 'Local PHPUnit HTTP kernel, SQLite memory, array cache, sequential, throttling disabled',
            'shops' => 200, 'offers' => 1000, 'codes' => 2000, 'paths' => $paths, 'results' => $stats];
        file_put_contents(storage_path('logs/discovery-benchmark.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertCount(2, $stats);
    }
}
