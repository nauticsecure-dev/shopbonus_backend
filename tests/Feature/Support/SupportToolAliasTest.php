<?php

namespace Tests\Feature\Support;

use App\Enums\OfferAcquisition;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class SupportToolAliasTest extends TestCase
{
    use CreatesActiveWebshops;
    use RefreshDatabase;

    public function test_me_wallet_and_tool_aliases_expose_contract_fields(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($user), 1000, 'seed');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/wallet')
            ->assertOk()
            ->assertJsonPath('data.available_minor', 1000)
            ->assertJsonPath('data.unit', 'EUR_REDEMPTION_CREDIT');

        $this->getJson('/api/v1/me/vouchers')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['source_id', 'checked_at', 'issued']]);
    }

    public function test_workspace_wallet_offer_cost_and_isolation(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $intruder = User::factory()->create(['email_verified_at' => now()]);
        $shop = $this->activeWebshop($owner);
        Wallet::query()
            ->where('owner_type', 'webshop')
            ->where('owner_id', $shop->id)
            ->where('kind', WalletKind::MerchantFunding)
            ->where('unit', WalletUnit::EurCredit)
            ->update(['available' => 5000, 'reserved' => 0, 'pending' => 0]);
        $offer = app(OfferService::class)->create(
            $shop->memberships()->sole(),
            OfferAcquisition::MerchantReward,
            ['name' => 'Cost', 'discount_type' => 'fixed', 'discount_value' => 1000],
        );

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/workspaces/'.$shop->public_id.'/wallet')
            ->assertOk()
            ->assertJsonPath('data.available_minor', 5000)
            ->assertJsonPath('data.currency', 'EUR');

        $this->getJson('/api/v1/workspaces/'.$shop->public_id.'/offers/'.$offer->public_id.'/cost')
            ->assertOk()
            ->assertJsonPath('data.consumer_reward_minor', 1000)
            ->assertJsonPath('data.shopbonus_fee_minor', 300)
            ->assertJsonPath('data.merchant_total_economic_cost_minor', 1300)
            ->assertJsonPath('data.shopbonus_fee_rate', 0.3);

        Sanctum::actingAs($intruder);
        $this->getJson('/api/v1/workspaces/'.$shop->public_id.'/wallet')->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$shop->public_id.'/offers/'.$offer->public_id.'/cost')->assertNotFound();
    }
}
