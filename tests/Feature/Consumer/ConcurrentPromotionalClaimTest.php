<?php

namespace Tests\Feature\Consumer;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\Discovery\PublicProfileService;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\PromotionalClaims;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/** Real committed MySQL transactions in competing processes, not a latency benchmark. */
class ConcurrentPromotionalClaimTest extends TestCase
{
    use CreatesActiveWebshops, RacesProcesses;
    use DatabaseTruncation { DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables; }

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

    private function offer(int $codes): Offer
    {
        $shop = $this->activeWebshop(User::factory()->create());
        $member = $shop->memberships()->sole()->setRelation('webshop', $shop);
        app(PublicProfileService::class)->save($member, ['display_name' => 'Synthetic race', 'short_description' => 'Synthetic', 'categories' => ['home_living'], 'published' => true]);
        $service = app(OfferService::class);
        $offer = $service->transition($service->create($member, OfferAcquisition::PromotionalCode, ['name' => 'Race', 'discount_type' => 'fixed', 'discount_value' => 1000, 'redemption_validity_days' => 30]), OfferStatus::Active);
        for ($i = 0; $i < $codes; $i++) {
            $code = 'SYNTHETIC-RACE-'.$i;
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code, 'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
        }

        return $offer;
    }

    public function test_parallel_retries_for_one_consumer_issue_one_voucher(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offer(1);
        $consumer = User::factory()->create();
        $results = $this->race(4, fn () => 'ok:'.app(PromotionalClaims::class)->claim($offer->slug, User::findOrFail($consumer->id))->public_id);
        $this->assertCount(4, array_filter($results, fn ($r) => str_starts_with($r, 'ok:')), implode(' | ', $results));
        $this->assertCount(1, array_unique($results));
        $this->assertSame(1, Voucher::where('status', 'issued')->count());
        $this->assertSame(1, VoucherCode::where('status', 'issued')->count());
        $this->assertSame(0, DB::table('sponsorship_obligations')->count());
    }

    public function test_eight_consumers_compete_for_three_codes_without_duplicate_issuance(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offer(3);
        $consumers = User::factory()->count(8)->create()->pluck('id')->all();
        $results = $this->race(8, fn (int $worker) => 'ok:'.app(PromotionalClaims::class)->claim($offer->slug, User::findOrFail($consumers[$worker]))->public_id);
        $wins = array_filter($results, fn ($r) => str_starts_with($r, 'ok:'));
        $this->assertCount(3, $wins, implode(' | ', $results));
        $this->assertCount(3, array_unique($wins));
        $this->assertCount(5, array_filter($results, fn ($r) => $r === 'err:offer_out_of_stock'), implode(' | ', $results));
        $this->assertSame(3, Voucher::where('status', 'issued')->count());
        $this->assertSame(3, Voucher::distinct()->count('voucher_code_id'));
        $this->assertSame(3, VoucherCode::where('status', 'issued')->count());
        $this->assertSame(0, DB::table('sponsorship_obligations')->count());
    }
}
