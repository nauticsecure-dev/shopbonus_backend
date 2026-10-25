<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WalletReconciliationFlag;
use App\Services\Admin\StepUpCodes;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletOversightTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(bool $password = true, bool $otp = true): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => $password ? time() : 0, StepUpCodes::SESSION_KEY => $otp ? time() : 0]);
    }

    public function test_wallets_and_ledger_are_paginated_filtered_and_sanitized(): void
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($user);
        $wallets->credit($wallet, 500, 'secret-provider-reference');
        $wallets->adjust($wallet, -100, 'correction', 'Verified correction', $this->admin->id);
        $wallets->consumerWallet(User::factory()->create());
        $this->asAdmin();
        $this->getJson('/api/v1/admin/wallets?consumer_id='.$user->public_id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available', 400)->assertJsonPath('data.0.id', $wallet->public_id);
        $response = $this->getJson('/api/v1/admin/wallets/'.$wallet->public_id.'/ledger?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.amount', -100);
        foreach (['wallet_id', 'actor_user_id', 'related_entry_id', 'idempotency_key', 'secret-provider-reference'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson('/api/v1/admin/wallets?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/wallets?unit=USD')->assertUnprocessable();
        $this->getJson('/api/v1/admin/wallets/missing/ledger')->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.wallet_ledger_viewed']);
    }

    public function test_flag_resolution_is_audited_and_cannot_hide_an_existing_mismatch(): void
    {
        $wallet = app(WalletService::class)->consumerWallet(User::factory()->create());
        $flag = WalletReconciliationFlag::create(['wallet_id' => $wallet->id, 'reason' => 'balance_mismatch']);
        $url = '/api/v1/admin/wallet-reconciliation-flags/'.$flag->public_id.'/resolve';
        $this->asAdmin();
        $this->getJson('/api/v1/admin/wallet-reconciliation-flags?status=open')->assertOk()->assertJsonPath('data.0.id', $flag->public_id);
        $this->postJson($url, [])->assertUnprocessable();
        // Deliberate cache corruption: admin resolution must never conceal it.
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => 10]);
        $this->postJson($url, ['reason' => 'Checked ledger'])->assertConflict()->assertJsonPath('error.code', 'wallet_still_unreconciled');
        $this->assertSame('open', $flag->fresh()->status);
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => 0]);
        $this->postJson($url, ['reason' => 'Checked ledger'])->assertOk()->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_by', $this->admin->public_id);
        $this->postJson($url, ['reason' => 'Checked again'])->assertConflict();
        $this->assertSame(1, AuditEvent::where('action', 'admin.wallet_flag_resolved')->count());
        $this->assertSame(0, $wallet->entries()->count());
        $this->assertSame(0, $wallet->fresh()->available);
    }

    public function test_mutation_requires_recent_password_and_otp_before_lookup(): void
    {
        $url = '/api/v1/admin/wallet-reconciliation-flags/missing/resolve';
        $this->asAdmin(password: false);
        $this->postJson($url, ['reason' => 'Checked ledger'])->assertStatus(423)->assertJsonPath('error.code', 'password_confirmation_required');
        $this->asAdmin(otp: false);
        $this->postJson($url, ['reason' => 'Checked ledger'])->assertStatus(423)->assertJsonPath('error.code', 'step_up_code_required');
        $this->asAdmin();
        $this->postJson($url, ['reason' => 'Checked ledger'])->assertNotFound();
    }

    public function test_all_routes_reject_guests_and_non_admins_before_lookup(): void
    {
        $routes = [['GET', 'wallets'], ['GET', 'wallets/missing/ledger'], ['GET', 'wallet-reconciliation-flags'], ['POST', 'wallet-reconciliation-flags/missing/resolve']];
        foreach ($routes as [$method, $route]) {
            $this->json($method, '/api/v1/admin/'.$route)->assertUnauthorized();
        }
        Sanctum::actingAs(User::factory()->create());
        foreach ($routes as [$method, $route]) {
            $this->json($method, '/api/v1/admin/'.$route)->assertForbidden();
        }
    }
}
