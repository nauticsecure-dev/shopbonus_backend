<?php

namespace Tests\Feature\Discovery;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B08a: public discovery (Trello #18 E2E 2, 3, 4; cache invalidation;
 * merchant public profile and logo).
 */
class PublicDiscoveryTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $fietsen;

    private Offer $fietsenOffer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();

        $this->fietsen = $this->listedShop('Fietsen Utrecht', ['sports_outdoor'], 'Fietsen en onderdelen');
        $this->fietsenOffer = $this->offer($this->fietsen, '€10 korting op je volgende fiets', 2);
        $this->travel(1)->minutes();
        $woon = $this->listedShop('Woon & Co', ['home_living'], 'Meubels en decoratie');
        $this->offer($woon, '15% op meubels', 0, ['discount_type' => 'percentage', 'discount_value' => 1500, 'max_discount_minor' => 5000]);
    }

    private function listedShop(string $name, array $categories, string $short, ?User $owner = null): Webshop
    {
        $owner ??= $this->owner;
        $shop = $this->activeWebshop($owner, ['name' => $name]);
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/workspaces/{$shop->public_id}/public-profile", [
            'short_description' => $short, 'description' => "Over {$name}.", 'website_url' => 'https://'.str()->slug($name).'.nl',
            'categories' => $categories, 'published' => true,
        ])->assertOk();

        return $shop;
    }

    private function offer(Webshop $shop, string $name, int $codes, array $terms = []): Offer
    {
        $membership = $shop->memberships()->where('role', 'owner')->firstOrFail()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => $name, 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 90, ...$terms,
        ]), OfferStatus::Active);
        for ($i = 1; $i <= $codes; $i++) {
            $code = strtoupper(substr(md5($name.$i), 0, 10));
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code,
                'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
        }

        return $offer->refresh();
    }

    public function test_card18_e2e2_search_filters_sort_and_pagination_return_only_listed_webshops(): void
    {
        // Not listed: suspended, not approved, unpublished.
        $suspended = $this->listedShop('Fietsenmaker Zwolle', ['sports_outdoor'], 'Gesloten');
        $suspended->forceFill(['status' => WebshopStatus::Suspended])->save();
        $draft = Webshop::factory()->withMember($this->owner, MembershipRole::Owner)->create(['name' => 'Draft Fietsen', 'created_by' => $this->owner->id]);
        $this->putJson("/api/v1/workspaces/{$draft->public_id}/public-profile", ['short_description' => 'x', 'categories' => ['sports_outdoor'], 'published' => true])
            ->assertOk()->assertJsonPath('data.listed', false)->assertJsonPath('data.listing_blockers', ['webshop_not_approved']);
        $hidden = $this->activeWebshop($this->owner, ['name' => 'Fietsen Verborgen']);
        $this->putJson("/api/v1/workspaces/{$hidden->public_id}/public-profile", ['short_description' => 'x', 'categories' => ['sports_outdoor']])->assertOk();

        $this->app['auth']->forgetGuards();
        $all = $this->getJson('/api/v1/public/webshops')->assertOk();
        $this->assertSame(['Fietsen Utrecht', 'Woon & Co'], array_column($all->json('data'), 'name'));
        $this->assertSame(2, $all->json('meta.total'));

        $this->assertSame(['fietsen-utrecht'], array_column($this->getJson('/api/v1/public/webshops?search=fiets')->json('data'), 'slug'));
        $this->assertSame(['woon-co'], array_column($this->getJson('/api/v1/public/webshops?category=home_living')->json('data'), 'slug'));
        // Woon & Co's only offer is sold out.
        $this->assertSame(['fietsen-utrecht'], array_column($this->getJson('/api/v1/public/webshops?has_offers=1')->json('data'), 'slug'));
        $this->assertSame(['Woon & Co', 'Fietsen Utrecht'], array_column($this->getJson('/api/v1/public/webshops?sort=newest')->json('data'), 'name'));

        // CMS featured order: requested slug list is preserved.
        $this->assertSame(
            ['woon-co', 'fietsen-utrecht'],
            array_column($this->getJson('/api/v1/public/webshops?slugs=woon-co,fietsen-utrecht')->json('data'), 'slug')
        );
        $this->assertSame(
            ['fietsen-utrecht'],
            array_column($this->getJson('/api/v1/public/webshops?slugs=fietsen-utrecht,missing-shop')->json('data'), 'slug')
        );

        $page2 = $this->getJson('/api/v1/public/webshops?sort=name&per_page=1&page=2')->assertOk();
        $this->assertSame(['Woon & Co'], array_column($page2->json('data'), 'name'));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 1, 'total' => 2], $page2->json('meta'));

        $this->getJson('/api/v1/public/webshops?category=weapons')->assertUnprocessable();
        $this->getJson('/api/v1/public/webshops?per_page=500')->assertUnprocessable();
        $this->getJson('/api/v1/public/webshops?search=%25')->assertOk()->assertJsonPath('meta.total', 0); // LIKE wildcards are literal

        $card = $all->json('data.0');
        $this->assertSame('€10 korting op je volgende fiets', $card['featured_offer']['title']);
        $this->assertSame(['key' => 'sports_outdoor', 'label' => ['nl' => 'Sport & outdoor', 'en' => 'Sports & outdoor']], $card['categories'][0]);
    }

    public function test_card18_e2e3_webshop_detail_shows_public_data_only(): void
    {
        DB::table('webshops')->where('id', $this->fietsen->id)->update(['verified_domain' => 'secret-domain.nl']);
        $this->app['auth']->forgetGuards();

        $detail = $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->assertOk()
            ->assertJsonPath('data.name', 'Fietsen Utrecht')
            ->assertJsonPath('data.website_url', 'https://fietsen-utrecht.nl')
            ->assertJsonPath('data.offers.0.availability', 'available');
        $this->assertSame(['slug', 'name', 'short_description', 'logo_url', 'cover_url', 'categories', 'badge_label', 'cashback_label', 'description', 'website_url', 'benefits', 'offers'], array_keys($detail->json('data')));

        $raw = $detail->getContent();
        foreach ([$this->fietsen->public_id, $this->owner->email, 'secret-domain.nl', 'agreement', 'kvk', 'members', 'connection', '"id"'] as $private) {
            $this->assertStringNotContainsString($private, $raw);
        }

        $this->fietsen->forceFill(['status' => WebshopStatus::Suspended])->save();
        $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->assertNotFound()->assertJsonPath('error.code', 'webshop_not_found');
        $this->getJson('/api/v1/public/webshops/does-not-exist')->assertNotFound();
    }

    public function test_card18_e2e4_offer_availability_and_unavailable_offers(): void
    {
        $this->app['auth']->forgetGuards();
        $slug = $this->fietsenOffer->slug;
        $this->assertMatchesRegularExpression('/^eur10-korting-op-je-volgende-fiets-[a-z0-9]{6}$/', $slug);

        $this->getJson("/api/v1/public/offers/{$slug}")->assertOk()
            ->assertJsonPath('data.availability', 'available')->assertJsonPath('data.how_to_obtain', 'earn_with_purchase')
            ->assertJsonPath('data.min_order_minor', 5000)->assertJsonPath('data.webshop.slug', 'fietsen-utrecht');
        // Viewing never reserves anything.
        $this->assertSame(2, VoucherCode::where('offer_id', $this->fietsenOffer->id)->where('status', VoucherCodeStatus::Available)->count());

        $soldOut = Offer::where('webshop_id', '!=', $this->fietsen->id)->sole();
        $this->getJson("/api/v1/public/offers/{$soldOut->slug}")->assertOk()->assertJsonPath('data.availability', 'sold_out');
        $this->assertSame([$slug], array_column($this->getJson('/api/v1/public/offers?available=1')->json('data'), 'slug'));
        $this->assertCount(2, $this->getJson('/api/v1/public/offers')->json('data'));

        // Expired while still "active": shown as expired on its page, gone from lists.
        DB::table('offer_versions')->where('id', $soldOut->current_version_id)->update(['ends_at' => now()->subDay()]);
        $soldOut->touch();
        $this->getJson("/api/v1/public/offers/{$soldOut->slug}")->assertOk()->assertJsonPath('data.availability', 'expired');
        $this->assertSame([$slug], array_column($this->getJson('/api/v1/public/offers')->json('data'), 'slug'));

        app(OfferService::class)->transition($this->fietsenOffer, OfferStatus::Paused);
        $this->getJson("/api/v1/public/offers/{$slug}")->assertNotFound()->assertJsonPath('error.code', 'offer_not_found');
        $this->assertSame([], $this->getJson('/api/v1/public/offers')->json('data'));
    }

    public function test_public_cache_is_invalidated_by_profile_offer_and_status_changes(): void
    {
        $this->app['auth']->forgetGuards();
        $this->assertCount(2, $this->getJson('/api/v1/public/webshops')->json('data'));

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->fietsen->public_id}/public-profile", ['published' => false])->assertOk()->assertJsonPath('data.listed', false);
        $this->app['auth']->forgetGuards();
        $this->assertSame(['Woon & Co'], array_column($this->getJson('/api/v1/public/webshops')->json('data'), 'name'));

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->fietsen->public_id}/public-profile", ['published' => true, 'short_description' => 'Nieuwe tekst'])->assertOk();
        $this->assertSame('Nieuwe tekst', $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->json('data.short_description'));

        app(OfferService::class)->transition($this->fietsenOffer, OfferStatus::Paused);
        $this->assertSame([], $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->json('data.offers'));

        Webshop::whereKey($this->fietsen->id)->firstOrFail()->forceFill(['status' => WebshopStatus::Suspended])->save();
        $this->assertSame(['Woon & Co'], array_column($this->getJson('/api/v1/public/webshops')->json('data'), 'name'));
    }

    public function test_merchant_profile_validation_publishing_rules_and_permissions(): void
    {
        $shop = $this->activeWebshop($this->owner, ['name' => 'Kaas & Zo']);
        $url = "/api/v1/workspaces/{$shop->public_id}/public-profile";
        Sanctum::actingAs($this->owner);

        $this->getJson($url)->assertOk()->assertJsonPath('data.published', false)->assertJsonPath('data.display_name', 'Kaas & Zo');
        $this->putJson($url, ['published' => true])->assertUnprocessable()->assertJsonPath('error.code', 'public_profile_incomplete')
            ->assertJsonPath('error.details.missing', ['short_description', 'categories']);
        $this->putJson($url, ['description' => '<script>alert(1)</script>'])->assertUnprocessable()->assertJsonValidationErrors('description', 'error.fields');
        $this->putJson($url, ['website_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->putJson($url, ['categories' => ['weapons']])->assertUnprocessable();
        $this->putJson($url, ['slug' => 'fietsen-utrecht'])->assertUnprocessable()->assertJsonValidationErrors('slug', 'error.fields');

        $this->putJson($url, ['slug' => 'kaas-en-zo', 'short_description' => 'Kaas', 'categories' => ['food_drinks'], 'published' => true])->assertOk()
            ->assertJsonPath('data.slug', 'kaas-en-zo')->assertJsonPath('data.slug_locked', true)->assertJsonPath('data.listed', true);
        $this->putJson($url, ['slug' => 'kaas-nieuw'])->assertStatus(409)->assertJsonPath('error.code', 'slug_locked');

        $employee = User::factory()->create();
        $shop->memberships()->create(['user_id' => $employee->id, 'role' => 'employee', 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->putJson($url, ['short_description' => 'Gehackt'])->assertForbidden()->assertJsonPath('error.code', 'workspace_permission_denied');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url)->assertNotFound();
    }

    public function test_logo_upload_validation_replacement_and_serving(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/workspaces/{$this->fietsen->public_id}/public-profile/logo";

        $this->postJson($url, ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertUnprocessable();
        $this->postJson($url, ['logo' => UploadedFile::fake()->image('big.png', 400, 400)->size(2048)])->assertUnprocessable();
        $this->postJson($url, ['logo' => UploadedFile::fake()->create('fake.png', 10, 'image/png')])->assertUnprocessable(); // not really an image
        $this->postJson($url, ['logo' => UploadedFile::fake()->image('huge.png', 3000, 3000)])->assertUnprocessable();

        $this->postJson($url, ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertOk()->assertJsonPath('data.has_logo', true);
        $first = Storage::disk('local')->allFiles('public-profiles');
        $this->assertCount(1, $first);

        $this->app['auth']->forgetGuards();
        $logoUrl = $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->json('data.logo_url');
        $this->assertStringNotContainsString('public-profiles', $logoUrl);
        $this->get(parse_url($logoUrl, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        Sanctum::actingAs($this->owner);
        $this->postJson($url, ['logo' => UploadedFile::fake()->image('new.webp', 300, 300)])->assertOk();
        $this->assertNotSame($first, Storage::disk('local')->allFiles('public-profiles'));
        $this->assertCount(1, Storage::disk('local')->allFiles('public-profiles'));

        $this->deleteJson($url)->assertOk()->assertJsonPath('data.has_logo', false);
        $this->assertCount(0, Storage::disk('local')->allFiles('public-profiles'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/webshops/fietsen-utrecht/logo')->assertNotFound();
    }

    public function test_cover_is_served_without_a_logo_and_hidden_for_unlisted_profiles(): void
    {
        Storage::disk('local')->put('covers/fietsen.png', UploadedFile::fake()->image('cover.png', 64, 32)->getContent());
        WebshopPublicProfile::where('webshop_id', $this->fietsen->id)->update(['cover_path' => 'covers/fietsen.png', 'cover_mime' => 'image/png', 'logo_path' => null]);

        $this->get('/api/v1/public/webshops/fietsen-utrecht/cover')->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        WebshopPublicProfile::where('webshop_id', $this->fietsen->id)->update(['published' => false]);
        $this->getJson('/api/v1/public/webshops/fietsen-utrecht/cover')->assertNotFound();
    }
}
