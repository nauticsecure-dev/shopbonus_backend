<?php

namespace Tests\Feature\Funding;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Models\FundingTopUp;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\Discovery\PublicProfileService;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\PromotionalClaims;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

class ConcurrentIntegrationRequestsTest extends TestCase
{
    use CreatesActiveWebshops, DatabaseTruncation, RacesProcesses {
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

    public function test_simultaneous_funding_retries_make_exactly_one_provider_request(): void
    {
        $this->skipUnlessRealDatabase();
        $actor = User::factory()->create();
        $shop = $this->activeWebshop($actor);
        config(['shopbonus.payments.mollie.key' => 'test_synthetic']);
        $attempts = tempnam(sys_get_temp_dir(), 'shopbonus-funding-race-');
        Http::fake(function () use ($attempts) {
            file_put_contents($attempts, "attempt\n", FILE_APPEND | LOCK_EX);
            usleep(250000);

            return Http::response(['id' => 'tr_racetopup', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://example.com/checkout']]], 201);
        });
        try {
            $key = (string) Str::uuid();
            $results = $this->race(6, fn () => app(MerchantFunding::class)->startTopUp($shop, 1000, $actor, $key)->public_id);
            $this->assertSame(1, count(array_unique($results)), implode(', ', $results));
            $this->assertSame(FundingTopUp::sole()->public_id, $results[0]);
            $this->assertSame("attempt\n", file_get_contents($attempts));
        } finally {
            unlink($attempts);
        }
    }

    public function test_simultaneous_promotional_claims_allocate_one_code(): void
    {
        $this->skipUnlessRealDatabase();
        $owner = User::factory()->create();
        $consumer = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $member = $shop->memberships()->sole()->setRelation('webshop', $shop);
        app(PublicProfileService::class)->save($member, ['short_description' => 'Sample', 'categories' => ['home_living'], 'published' => true]);
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($member, OfferAcquisition::PromotionalCode,
            ['name' => 'Sample', 'discount_type' => 'fixed', 'discount_value' => 1000, 'redemption_validity_days' => 30]), OfferStatus::Active);
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'LOCAL-SAMPLE',
            'code_hash' => VoucherCode::hashFor($shop->id, 'LOCAL-SAMPLE'), 'code_last4' => 'MPLE']);
        $results = $this->race(6, fn () => app(PromotionalClaims::class)->claim($offer->slug, $consumer)->public_id);
        $this->assertSame(1, count(array_unique($results)), implode(', ', $results));
        $this->assertSame(Voucher::sole()->public_id, $results[0]);
        $this->assertSame($consumer->id, Voucher::sole()->consumer_user_id);
    }
}
