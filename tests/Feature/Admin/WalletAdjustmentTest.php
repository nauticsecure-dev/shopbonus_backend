<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Models\AdminApprovalRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\StepUpCodes;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $reviewer;

    private Wallet $wallet;

    /** @var array<string, mixed> */
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requester = $this->admin();
        $this->reviewer = $this->admin();
        $consumer = User::factory()->create();
        $this->wallet = app(WalletService::class)->consumerWallet($consumer);
        $entry = app(WalletService::class)->credit($this->wallet, 500, 'original-transaction');
        $this->payload = ['wallet_id' => $this->wallet->public_id, 'consumer_id' => $consumer->public_id, 'source_entry_id' => $entry->public_id,
            'amount' => 100, 'reason' => 'Verified missing credit', 'verification_note' => 'Consumer and original transaction checked', 'request_key' => 'adjustment-001'];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);

        return $admin;
    }

    private function asUser(User $user, bool $password = true, bool $otp = true): void
    {
        Sanctum::actingAs($user);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => $password ? time() : 0, StepUpCodes::SESSION_KEY => $otp ? time() : 0]);
    }

    public function test_two_admin_approval_posts_one_compensating_entry_and_replays_safely(): void
    {
        $this->asUser($this->requester);
        $id = $this->postJson('/api/v1/admin/wallet-adjustments', $this->payload)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/admin/wallet-adjustments', $this->payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(500, $this->wallet->fresh()->available);
        $url = '/api/v1/admin/wallet-adjustments/'.$id.'/review';
        $review = ['decision' => 'approved', 'note' => 'Independently verified evidence'];
        $this->postJson($url, $review)->assertForbidden()->assertJsonPath('error.code', 'second_admin_required');
        $this->asUser($this->reviewer);
        $entry = $this->postJson($url, $review)->assertOk()->assertJsonPath('data.status', 'approved')->json('data.posted_entry_id');
        $this->postJson($url, $review)->assertOk()->assertJsonPath('data.posted_entry_id', $entry);
        $this->postJson($url, ['decision' => 'rejected', 'note' => 'Changed decision'])->assertConflict();
        $this->assertSame(600, $this->wallet->fresh()->available);
        $this->assertSame(2, $this->wallet->entries()->count());
        $this->assertSame(1, AdminApprovalRequest::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.wallet_adjustment_requested']);
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.wallet_adjustment_reviewed']);
        $this->getJson('/api/v1/admin/wallet-adjustments?status=approved')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_verification_key_conflicts_and_zero_amount_are_refused(): void
    {
        $this->asUser($this->requester);
        $this->postJson('/api/v1/admin/wallet-adjustments', array_replace($this->payload, ['amount' => 0]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/wallet-adjustments', array_replace($this->payload, ['consumer_id' => User::factory()->create()->public_id]))->assertConflict();
        $other = app(WalletService::class)->consumerWallet(User::factory()->create());
        $source = app(WalletService::class)->credit($other, 20, 'other-source');
        $this->postJson('/api/v1/admin/wallet-adjustments', array_replace($this->payload, ['source_entry_id' => $source->public_id]))->assertConflict();
        $this->postJson('/api/v1/admin/wallet-adjustments', $this->payload)->assertCreated();
        $this->postJson('/api/v1/admin/wallet-adjustments', array_replace($this->payload, ['amount' => 101]))->assertConflict()->assertJsonPath('error.code', 'approval_key_conflict');
        $this->assertSame(1, AdminApprovalRequest::count());
    }

    public function test_rejection_and_insufficient_funds_never_change_balances(): void
    {
        $this->asUser($this->requester);
        $id = $this->postJson('/api/v1/admin/wallet-adjustments', array_replace($this->payload, ['amount' => -501]))->assertCreated()->json('data.id');
        $url = '/api/v1/admin/wallet-adjustments/'.$id.'/review';
        $this->asUser($this->reviewer);
        $this->postJson($url, ['decision' => 'approved', 'note' => 'Checked evidence'])->assertConflict();
        $this->assertSame('pending', AdminApprovalRequest::sole()->status);
        $this->postJson($url, ['decision' => 'rejected', 'note' => 'Insufficient unspent credit'])->assertOk()->assertJsonPath('data.posted_entry_id', null);
        $this->assertSame(500, $this->wallet->fresh()->available);
        $this->assertSame(1, $this->wallet->entries()->count());
    }

    public function test_review_rechecks_requester_and_consumer_eligibility(): void
    {
        $this->asUser($this->requester);
        $id = $this->postJson('/api/v1/admin/wallet-adjustments', $this->payload)->assertCreated()->json('data.id');
        $this->requester->platformRoles()->update(['revoked_at' => now()]);
        $this->asUser($this->reviewer);
        $this->postJson('/api/v1/admin/wallet-adjustments/'.$id.'/review', ['decision' => 'approved', 'note' => 'Checked evidence'])->assertConflict();
        $this->assertSame(500, $this->wallet->fresh()->available);
    }

    public function test_guards_apply_to_request_and_review_before_resource_lookup(): void
    {
        foreach (['/api/v1/admin/wallet-adjustments', '/api/v1/admin/wallet-adjustments/missing/review'] as $url) {
            $this->asUser($this->requester, password: false);
            $this->postJson($url, [])->assertStatus(423)->assertJsonPath('error.code', 'password_confirmation_required');
            $this->asUser($this->requester, otp: false);
            $this->postJson($url, [])->assertStatus(423)->assertJsonPath('error.code', 'step_up_code_required');
            $this->asUser(User::factory()->create());
            $this->postJson($url, [])->assertForbidden();
        }
    }
}
