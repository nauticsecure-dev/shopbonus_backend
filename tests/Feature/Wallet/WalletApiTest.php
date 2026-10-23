<?php

namespace Tests\Feature\Wallet;

use App\Enums\WalletUnit;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B09 consumer wallet API (Trello #19 "My Points", #10 E2E 8 wallet
 * consistency, #18 E2E 8 pending not spendable).
 */
class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_card10_e2e8_wallet_balance_history_and_pending_are_the_ledgers(): void
    {
        $anna = User::factory()->create();
        $wallets = app(WalletService::class);
        $eur = $wallets->consumerWallet($anna);
        $wallets->credit($eur, 1220, 'seed', meta: ['source_type' => 'affiliate', 'source_ref' => 'conv-1', 'reason' => 'Fietsen Utrecht purchase']);
        $wallets->addPending($eur, 300, 'pending-1', ['description' => 'Woon & Co purchase (awaiting confirmation)']);
        Sanctum::actingAs($anna);

        $this->getJson('/api/v1/consumer/wallet')->assertOk()
            ->assertJsonPath('data.balances.eur_credit.available', 1220)
            ->assertJsonPath('data.balances.eur_credit.pending', 300)
            ->assertJsonPath('data.balances.points.available', 0)
            ->assertJsonPath('data.withdrawable', false);
        $this->getJson('/api/v1/consumer/dashboard')->assertOk()->assertJsonPath('data.wallet.eur_credit.available', 1220);

        // An authorised spend through the service updates what the API shows.
        $wallets->debit($eur, 1000, 'voucher-purchase-1', ['source_type' => 'voucher_purchase', 'source_ref' => 'v-1']);
        $this->getJson('/api/v1/consumer/wallet')->assertJsonPath('data.balances.eur_credit.available', 220)->assertJsonPath('data.balances.eur_credit.total_spent', 1000);

        $all = $this->getJson('/api/v1/consumer/wallet/transactions')->assertOk();
        $this->assertSame(['debit', 'reserve', 'credit'], array_column($all->json('data'), 'type'));
        $this->assertSame(['credit'], array_column($this->getJson('/api/v1/consumer/wallet/transactions?filter=earned')->json('data'), 'type'));
        $this->assertSame(['debit'], array_column($this->getJson('/api/v1/consumer/wallet/transactions?filter=spent')->json('data'), 'type'));
        $pending = $this->getJson('/api/v1/consumer/wallet/transactions?filter=pending')->assertOk()->json('data');
        $this->assertSame([300, false], [$pending[0]['amount'], $pending[0]['spendable']]);
        $this->getJson('/api/v1/consumer/wallet/transactions?filter=cash')->assertUnprocessable();

        $credit = collect($all->json('data'))->firstWhere('type', 'credit');
        $this->getJson("/api/v1/consumer/wallet/transactions/{$credit['id']}")->assertOk()->assertJsonPath('data.remaining', 220);
    }

    public function test_consumers_cannot_see_each_others_wallets_and_no_cash_out_exists(): void
    {
        $anna = User::factory()->create();
        $wallets = app(WalletService::class);
        $entry = $wallets->credit($wallets->consumerWallet($anna, WalletUnit::Points), 500, 'p1');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/consumer/wallet')->assertJsonPath('data.balances.points.available', 0);
        $this->getJson("/api/v1/consumer/wallet/transactions/{$entry->public_id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/consumer/wallet/transactions')->json('data'));

        $routes = collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_contains($u, 'wallet'));
        foreach ($routes as $uri) {
            $this->assertDoesNotMatchRegularExpression('/withdraw|payout|transfer|cash/', $uri);
        }
    }
}
