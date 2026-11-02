<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MembershipRole;
use App\Models\User;
use App\Models\Webshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trello #4: owner-only workspace permissions require confirmed TOTP when
 * OWNER_MFA_REQUIRED is enabled (parity with Super Admin MFA enforcement).
 */
class OwnerMfaGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sensitive_routes_require_confirmed_totp(): void
    {
        config(['shopbonus.workspace.mfa_required_for_sensitive' => true]);

        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner, MembershipRole::Owner)->create(['created_by' => $owner->id]);
        Sanctum::actingAs($owner);

        $base = "/api/v1/workspaces/{$shop->public_id}";

        $this->getJson("{$base}/ownership/verifications")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_required');

        $this->postJson("{$base}/agreement/accept", [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_required');

        $this->postJson("{$base}/funding/top-ups", ['amount_minor' => 1000, 'currency' => 'EUR'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_required');

        $owner->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // With MFA enabled the gate opens; subsequent domain validation may still 422/409.
        $this->getJson("{$base}/ownership/verifications")
            ->assertOk();
        $this->assertNotSame('mfa_required', $this->postJson("{$base}/agreement/accept", [])->json('error.code'));
        $this->assertNotSame('mfa_required', $this->postJson("{$base}/funding/top-ups", ['amount_minor' => 1000])->json('error.code'));
    }

    public function test_non_owner_permissions_are_not_gated_by_owner_mfa(): void
    {
        config(['shopbonus.workspace.mfa_required_for_sensitive' => true]);

        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner, MembershipRole::Owner)->create(['created_by' => $owner->id]);
        $shop->memberships()->create([
            'user_id' => $manager->id,
            'role' => MembershipRole::Manager,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/workspaces/{$shop->public_id}/business-profile")
            ->assertOk();
    }
}
