<?php

namespace Tests\Feature\Consumer;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\Discovery\PublicProfileService;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class PromotionalClaimTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    public function test_claim_uses_stock_once_and_replays_without_exposing_the_code(): void
    {
        [$offer, $shop] = $this->offer();
        $url = '/api/v1/consumer/offers/'.$offer->slug.'/claim';
        $this->postJson($url)->assertUnauthorized();
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);
        $id = $this->postJson($url)->assertCreated()->assertJsonMissingPath('data.code')->json('data.id');
        $this->postJson($url)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Voucher::count());
        $this->assertSame($consumer->id, Voucher::sole()->consumer_user_id);
        $this->assertDatabaseCount('sponsorship_obligations', 0);
        $this->getJson('/api/v1/consumer/vouchers/'.$id)->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/consumer/vouchers/'.$id)->assertNotFound();
        $this->postJson($url)->assertConflict();
        $shop->publicProfile->forceFill(['published' => false])->save();
        $this->postJson($url)->assertNotFound();
    }

    public function test_wrong_acquisition_paused_and_unverified_cannot_allocate(): void
    {
        [$offer] = $this->offer(OfferAcquisition::WalletPurchase);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/consumer/offers/'.$offer->slug.'/claim')->assertConflict()->assertJsonPath('error.code', 'offer_not_claimable');
        [$promo] = $this->offer();
        app(OfferService::class)->transition($promo, OfferStatus::Paused);
        $this->postJson('/api/v1/consumer/offers/'.$promo->slug.'/claim')->assertConflict();
        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->postJson('/api/v1/consumer/offers/'.$promo->slug.'/claim')->assertForbidden();
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_claim_rate_limit_bounds_repeated_requests_without_extra_stock(): void
    {
        [$offer] = $this->offer();
        Sanctum::actingAs(User::factory()->create());
        $url = '/api/v1/consumer/offers/'.$offer->slug.'/claim';
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($url)->assertCreated();
        }
        $this->postJson($url)->assertStatus(429);
        $this->assertDatabaseCount('vouchers', 1);
    }

    private function offer(OfferAcquisition $acquisition = OfferAcquisition::PromotionalCode): array
    {
        $shop = $this->activeWebshop(User::factory()->create());
        $member = $shop->memberships()->sole()->setRelation('webshop', $shop);
        app(PublicProfileService::class)->save($member, ['display_name' => 'Sample', 'short_description' => 'Sample', 'categories' => ['home_living'], 'published' => true]);
        $service = app(OfferService::class);
        $offer = $service->transition($service->create($member, $acquisition, ['name' => 'Sample', 'discount_type' => 'fixed', 'discount_value' => 1000,
            'price_minor' => $acquisition === OfferAcquisition::WalletPurchase ? 500 : null, 'redemption_validity_days' => 30]), OfferStatus::Active);
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'LOCAL-SAMPLE', 'code_hash' => VoucherCode::hashFor($shop->id, 'LOCAL-SAMPLE'), 'code_last4' => 'MPLE']);

        return [$offer, $shop];
    }
}
