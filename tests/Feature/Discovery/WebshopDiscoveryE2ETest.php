<?php

namespace Tests\Feature\Discovery;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * HTTP integration scenarios for discovery; browser acceptance is separate.
 */
class WebshopDiscoveryE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $woon;

    private Webshop $tech;

    private Webshop $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->woon = $this->listedShop('Woon & Co', 'woon-en-co', ['home_living'], 'Alles voor een stijlvol thuis.');
        $this->mode = $this->listedShop('Modehuis', 'modehuis', ['fashion'], 'Mode die bij jou past.');
        $this->tech = $this->listedShop('Techwinkel', 'techwinkel', ['electronics'], 'Slimme tech voor elke dag.');
        $this->seedOffers($this->woon, 3);
        $this->seedOffers($this->mode, 3);
        $this->seedOffers($this->tech, 2);
        $this->app['auth']->forgetGuards();
    }

    private function listedShop(string $name, string $slug, array $categories, string $short): Webshop
    {
        $shop = $this->activeWebshop($this->owner, ['name' => $name]);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$shop->public_id}/public-profile", [
            'slug' => $slug,
            'short_description' => $short,
            'description' => "Over {$name}.",
            'website_url' => 'https://'.$slug.'.nl',
            'categories' => $categories,
            'published' => true,
        ])->assertOk();
        WebshopPublicProfile::where('webshop_id', $shop->id)->update([
            'cover_url' => 'https://images.example.test/'.$slug.'.jpg',
        ]);

        return $shop->fresh();
    }

    private function seedOffers(Webshop $shop, int $count): array
    {
        $membership = $shop->memberships()->where('role', 'owner')->firstOrFail()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        $created = [];
        for ($i = 1; $i <= $count; $i++) {
            $offer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
                'name' => "{$shop->name} deal {$i}",
                'discount_type' => 'fixed',
                'discount_value' => 1000 * $i,
                'min_order_minor' => 5000,
                'redemption_validity_days' => 90,
            ]), OfferStatus::Active);
            for ($c = 1; $c <= 3; $c++) {
                $code = strtoupper(substr(md5($shop->id.$i.$c), 0, 10));
                VoucherCode::create([
                    'webshop_id' => $shop->id,
                    'offer_id' => $offer->id,
                    'code' => $code,
                    'code_hash' => VoucherCode::hashFor($shop->id, $code),
                    'code_last4' => substr($code, -4),
                    'status' => VoucherCodeStatus::Available,
                ]);
            }
            $created[] = $offer->refresh();
        }

        return $created;
    }

    /** 1. Initial directory — published cards only, public fields. */
    public function test_01_initial_directory_lists_published_webshops(): void
    {
        $draft = Webshop::factory()->withMember($this->owner, MembershipRole::Owner)->create([
            'name' => 'Draft Hidden',
            'created_by' => $this->owner->id,
        ]);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$draft->public_id}/public-profile", [
            'short_description' => 'x',
            'categories' => ['fashion'],
            'published' => true,
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        $res = $this->getJson('/api/v1/public/webshops?per_page=12')->assertOk();
        $names = array_column($res->json('data'), 'name');
        $this->assertSame(['Modehuis', 'Woon & Co', 'Techwinkel'], $names); // most offers desc, then name
        $this->assertSame(3, $res->json('meta.total'));

        $card = collect($res->json('data'))->firstWhere('slug', 'woon-en-co');
        $this->assertSame(3, $card['active_offer_count']);
        $this->assertSame(3, $card['offer_count']);
        $this->assertSame('Alles voor een stijlvol thuis.', $card['short_description']);
        $this->assertStringContainsString('woon-en-co.jpg', (string) $card['cover_url']);
        $this->assertArrayNotHasKey('id', $card);
        foreach ($res->json('data') as $row) {
            $this->assertArrayNotHasKey('verified_domain', $row);
        }
    }

    /** 2. Search woon — then clear returns full directory. */
    public function test_02_search_woon_and_clear(): void
    {
        $hit = $this->getJson('/api/v1/public/webshops?search=woon')->assertOk();
        $this->assertSame(['woon-en-co'], array_column($hit->json('data'), 'slug'));

        $all = $this->getJson('/api/v1/public/webshops')->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
    }

    /** 3. Category filtering — Wonen then Elektronica, no stale mix. */
    public function test_03_category_filtering_updates_results(): void
    {
        $wonen = $this->getJson('/api/v1/public/webshops?category=home_living')->assertOk();
        $this->assertSame(['woon-en-co'], array_column($wonen->json('data'), 'slug'));

        $tech = $this->getJson('/api/v1/public/webshops?category=electronics')->assertOk();
        $this->assertSame(['techwinkel'], array_column($tech->json('data'), 'slug'));
        $this->assertNotContains('woon-en-co', array_column($tech->json('data'), 'slug'));
    }

    /** 4. Combined search + category + most offers + pagination reset. */
    public function test_04_combined_search_category_sort_resets_page(): void
    {
        $extra = $this->listedShop('WoonXtra', 'woon-xtra', ['home_living'], 'Nog meer wonen.');
        $this->seedOffers($extra, 1);
        $this->app['auth']->forgetGuards();

        $page = $this->getJson('/api/v1/public/webshops?search=woon&category=home_living&sort=most_offers&page=1&per_page=12')
            ->assertOk();
        $this->assertSame(['woon-en-co', 'woon-xtra'], array_column($page->json('data'), 'slug'));
        $this->assertSame(1, $page->json('meta.current_page'));
        $this->assertSame(2, $page->json('meta.total'));
    }

    /** 5. Offer counters drop for expired / sold-out; most_offers matches obtainable. */
    public function test_05_offer_counter_excludes_expired(): void
    {
        $offers = Offer::where('webshop_id', $this->woon->id)->orderBy('id')->get();
        $this->assertCount(3, $offers);
        DB::table('offer_versions')->where('id', $offers[0]->current_version_id)->update(['ends_at' => now()->subHour()]);
        $offers[0]->touch();

        $card = collect($this->getJson('/api/v1/public/webshops')->json('data'))->firstWhere('slug', 'woon-en-co');
        $this->assertSame(2, $card['active_offer_count']);
        $this->assertSame(2, $card['offer_count']);

        // Exhaust stock on another offer → sold out must leave the counter.
        VoucherCode::where('offer_id', $offers[1]->id)->update(['status' => VoucherCodeStatus::Issued]);
        DiscoveryCache::flush();
        $card = collect($this->getJson('/api/v1/public/webshops')->json('data'))->firstWhere('slug', 'woon-en-co');
        $this->assertSame(1, $card['active_offer_count']);

        // has_offers=true (string) must not 422; Laravel boolean accepts 1/0 via frontend contract.
        $this->getJson('/api/v1/public/webshops?has_offers=1')->assertOk();
        $this->getJson('/api/v1/public/webshops?has_offers=true')->assertUnprocessable();
    }

    /** 6. Webshop detail via clean slug — active offers only publicly. */
    public function test_06_webshop_detail_clean_slug(): void
    {
        $detail = $this->getJson('/api/v1/public/webshops/woon-en-co')->assertOk()
            ->assertJsonPath('data.slug', 'woon-en-co')
            ->assertJsonPath('data.name', 'Woon & Co');
        $this->assertCount(3, $detail->json('data.offers'));
        $raw = $detail->getContent();
        $this->assertStringNotContainsString($this->owner->email, $raw);
        $this->assertStringNotContainsString($this->woon->public_id, $raw);
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:/', $raw);
    }

    /** 7. Wallet + active voucher count for authenticated consumer. */
    public function test_07_wallet_and_active_vouchers(): void
    {
        $consumer = User::factory()->create(['onboarding_intent' => 'consumer', 'email_verified_at' => now()]);
        $wallet = app(WalletService::class)->consumerWallet($consumer);
        app(WalletService::class)->credit($wallet, 1220, 'e2e-wallet-seed:'.$consumer->id, neverExpires: true);

        $offer = Offer::where('webshop_id', $this->woon->id)->firstOrFail();
        $this->issueVoucher($offer, $consumer, VoucherStatus::Issued);
        $this->issueVoucher($offer, $consumer, VoucherStatus::Issued);
        $this->issueVoucher($offer, $consumer, VoucherStatus::Expired);

        Sanctum::actingAs($consumer);
        $this->getJson('/api/v1/me/wallet')->assertOk()
            ->assertJsonPath('data.available_minor', 1220)
            ->assertJsonPath('data.currency', 'EUR');

        $vouchers = $this->getJson('/api/v1/consumer/vouchers?filter=active')->assertOk();
        $this->assertCount(2, $vouchers->json('data'));
    }

    private function issueVoucher(Offer $offer, User $consumer, VoucherStatus $status): Voucher
    {
        $code = 'CODE'.strtoupper(Str::random(4));
        VoucherCode::create([
            'webshop_id' => $offer->webshop_id,
            'offer_id' => $offer->id,
            'code' => $code,
            'code_hash' => VoucherCode::hashFor($offer->webshop_id, $code),
            'code_last4' => substr($code, -4),
        ]);
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->issue($inventory->reserve($offer, VoucherOrigin::Promotional, 'discovery-e2e:'.uniqid()), $consumer);
        if ($status === VoucherStatus::Expired) {
            $voucher->forceFill(['status' => VoucherStatus::Expired, 'expires_at' => now()->subDay()])->save();
        }

        return $voucher->fresh();
    }

    /** 8. Guest / consumer / workspace isolation. */
    public function test_08_access_and_isolation(): void
    {
        $guest = $this->getJson('/api/v1/public/webshops')->assertOk();
        $this->assertStringNotContainsString('available_minor', $guest->getContent());

        $this->getJson('/api/v1/me/wallet')->assertUnauthorized();
        $this->getJson('/api/v1/consumer/vouchers')->assertUnauthorized();

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/me/wallet')->assertOk()->assertJsonPath('data.available_minor', 0);

        Sanctum::actingAs($this->owner);
        $draftProfile = $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/public-profile")->assertOk();
        $this->assertArrayHasKey('listing_blockers', $draftProfile->json('data'));

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/public/webshops/woon-en-co')->assertOk();
        $this->assertArrayNotHasKey('listing_blockers', $public->json('data'));
    }

    /** 9. Empty search + invalid inputs + failure-safe responses. */
    public function test_09_empty_and_failure_states(): void
    {
        $empty = $this->getJson('/api/v1/public/webshops?search=zzzz-no-match')->assertOk();
        $this->assertSame([], $empty->json('data'));
        $this->assertSame(0, $empty->json('meta.total'));

        $this->getJson('/api/v1/public/webshops?category=not-a-category')->assertUnprocessable();
        $this->getJson('/api/v1/public/webshops?sort=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/public/webshops?per_page=999')->assertUnprocessable();
        $this->getJson('/api/v1/public/webshops/does-not-exist')->assertNotFound();
    }

    /** 10. Live publication changes invalidate directory visibility. */
    public function test_10_live_publication_changes(): void
    {
        $new = $this->listedShop('Beauty Studio', 'beauty-studio', ['beauty_health'], 'Glow up.');
        $this->seedOffers($new, 1);
        $this->app['auth']->forgetGuards();
        $this->assertContains('beauty-studio', array_column($this->getJson('/api/v1/public/webshops')->json('data'), 'slug'));

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->mode->public_id}/public-profile", ['published' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        $slugs = array_column($this->getJson('/api/v1/public/webshops')->json('data'), 'slug');
        $this->assertNotContains('modehuis', $slugs);
        $this->assertContains('beauty-studio', $slugs);

        Webshop::whereKey($this->tech->id)->firstOrFail()->forceFill(['status' => WebshopStatus::Suspended])->save();
        $slugs = array_column($this->getJson('/api/v1/public/webshops')->json('data'), 'slug');
        $this->assertNotContains('techwinkel', $slugs);
    }

    public function test_counts_filter_and_sort_share_obtainability_rules(): void
    {
        $offers = Offer::where('webshop_id', $this->woon->id)->orderBy('id')->get();
        DB::table('offer_versions')->where('id', $offers[0]->current_version_id)->update(['starts_at' => now()->addDay()]);
        DB::table('offer_versions')->where('id', $offers[1]->current_version_id)->update(['ends_at' => now()]);
        DB::table('offer_versions')->where('id', $offers[2]->current_version_id)->update(['allocation_limit' => 0]);
        DiscoveryCache::flush();
        $rows = $this->getJson('/api/v1/public/webshops?sort=most_offers')->assertOk()->json('data');
        $this->assertSame(['modehuis', 'techwinkel', 'woon-en-co'], array_column($rows, 'slug'));
        $this->assertSame([3, 2, 0], array_column($rows, 'offer_count'));
        $this->assertSame(['modehuis', 'techwinkel'], array_column($this->getJson('/api/v1/public/webshops?has_offers=1')->assertOk()->json('data'), 'slug'));
        $this->assertSame(0, collect($rows)->firstWhere('slug', 'woon-en-co')['active_offer_count']);
    }

    public function test_stock_and_reusable_allocation_changes_invalidate_warm_directory(): void
    {
        $offer = Offer::where('webshop_id', $this->tech->id)->firstOrFail();
        $this->getJson('/api/v1/public/webshops')->assertOk();
        foreach (VoucherCode::where('offer_id', $offer->id)->get() as $code) {
            $code->forceFill(['status' => VoucherCodeStatus::Invalid])->save();
        }
        $rows = $this->getJson('/api/v1/public/webshops')->assertOk()->json('data');
        $this->assertSame(1, collect($rows)->firstWhere('slug', 'techwinkel')['offer_count']);

        $limited = Offer::where('webshop_id', $this->woon->id)->firstOrFail();
        DB::table('offer_versions')->where('id', $limited->current_version_id)->update(['allocation_limit' => 1]);
        DiscoveryCache::flush();
        $this->getJson('/api/v1/public/webshops')->assertOk();
        $voucher = $this->issueVoucher($limited, User::factory()->create(), VoucherStatus::Issued);
        $rows = $this->getJson('/api/v1/public/webshops')->assertOk()->json('data');
        $this->assertSame(2, collect($rows)->firstWhere('slug', 'woon-en-co')['offer_count']);
        $voucher->forceFill(['status' => VoucherStatus::Cancelled])->save();
        $rows = $this->getJson('/api/v1/public/webshops')->assertOk()->json('data');
        $this->assertSame(3, collect($rows)->firstWhere('slug', 'woon-en-co')['offer_count']);
    }

    public function test_warm_cache_expires_at_scheduled_start_and_end(): void
    {
        $this->travelTo(now()->startOfSecond());
        $offer = Offer::where('webshop_id', $this->tech->id)->firstOrFail();
        DB::table('offer_versions')->where('id', $offer->current_version_id)->update(['starts_at' => now()->addSeconds(3), 'ends_at' => now()->addSeconds(6)]);
        DiscoveryCache::flush();
        $count = fn () => collect($this->getJson('/api/v1/public/webshops')->assertOk()->json('data'))->firstWhere('slug', 'techwinkel')['offer_count'];
        $this->assertSame(1, $count());
        $this->travel(3)->seconds();
        $this->assertSame(2, $count());
        $this->travel(3)->seconds();
        $this->assertSame(1, $count());
    }

    public function test_directory_queries_do_not_grow_with_page_size(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $shop = $this->listedShop('Extra '.$i, 'extra-'.$i, ['fashion'], 'Directory query fixture');
            $this->seedOffers($shop, 1);
        }
        $this->app['auth']->forgetGuards();
        $measure = function (int $size): int {
            DiscoveryCache::flush();
            DB::table('affiliate_program_selections')->delete();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/public/webshops?per_page='.$size)->assertOk()->assertJsonCount($size, 'data');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $small = $measure(1);
        $large = $measure(12);
        $this->assertLessThanOrEqual($small + 1, $large, "Queries grew from {$small} to {$large}");
    }

    public function test_uploaded_cover_is_served_without_a_logo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('public-profiles/cover.png', 'cover-fixture');
        WebshopPublicProfile::where('webshop_id', $this->woon->id)->firstOrFail()->forceFill([
            'logo_path' => null, 'cover_path' => 'public-profiles/cover.png', 'cover_mime' => 'image/png',
        ])->save();
        $this->get('/api/v1/public/webshops/woon-en-co/cover')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v1/public/webshops/woon-en-co/logo')->assertNotFound();
    }
}
