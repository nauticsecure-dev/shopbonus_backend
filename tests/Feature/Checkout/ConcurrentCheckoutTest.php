<?php

namespace Tests\Feature\Checkout;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\Checkout\CheckoutService;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * Card #23 test 7 / #20 E2E 3: simultaneous wallet-only purchases in real
 * processes on MySQL. Same key → one purchase; two consumers racing for the
 * last code → one voucher, the other refused, no money lost.
 */
class ConcurrentCheckoutTest extends TestCase
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

    private function offer(int $codes): Offer
    {
        $shop = $this->activeWebshop($owner = User::factory()->create());
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::WalletPurchase, [
            'name' => 'Race', 'discount_type' => 'fixed', 'discount_value' => 2000, 'price_minor' => 2000, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);
        for ($i = 1; $i <= $codes; $i++) {
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => "RACE-{$i}", 'code_hash' => VoucherCode::hashFor($shop->id, "RACE-{$i}"), 'code_last4' => "000{$i}"]);
        }

        return $offer;
    }

    public function test_same_consumer_two_simultaneous_requests_one_debit_one_voucher(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offer(1);
        $user = User::factory()->create();
        app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($user), 2000, 'seed');

        $results = $this->race(2, fn () => app(CheckoutService::class)->start(User::findOrFail($user->id), Offer::with('currentVersion')->findOrFail($offer->id), 'same-key')->state);

        $this->assertSame(['completed', 'completed'], $results, implode(', ', $results));
        $this->assertSame(1, Voucher::where('consumer_user_id', $user->id)->count());
        $this->assertSame([0, 2000], [app(WalletService::class)->consumerWallet($user)->available, app(WalletService::class)->consumerWallet($user)->total_spent]);
    }

    public function test_two_consumers_race_for_the_last_code(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offer(1);
        $users = [User::factory()->create(), User::factory()->create()];
        foreach ($users as $u) {
            app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($u), 2000, 'seed');
        }

        $results = $this->race(2, fn (int $w) => app(CheckoutService::class)->start(User::findOrFail($users[$w]->id), Offer::with('currentVersion')->findOrFail($offer->id), "key-{$w}")->state);

        sort($results);
        $this->assertSame(['completed', 'err:offer_out_of_stock'], $results, implode(', ', $results));
        $this->assertSame(1, Voucher::whereNotNull('consumer_user_id')->count());
        $balances = array_map(fn ($u) => app(WalletService::class)->consumerWallet($u)->available, $users);
        sort($balances);
        $this->assertSame([0, 2000], $balances); // the loser keeps everything
        $this->assertSame([], app(WalletService::class)->reconcile());
    }
}
