<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Enums\WorkspacePermission;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BrowsesLikeSpa;
use Tests\TestCase;

/**
 * B03a: workspace core and tenant isolation (Trello #2 §3-4, #3 D, #16
 * "Workspaces & memberships", #4 E2E 3).
 */
class WorkspaceTest extends TestCase
{
    use BrowsesLikeSpa, RefreshDatabase;

    private function webshopFor(User $user, MembershipRole $role = MembershipRole::Owner, array $extra = [], array $attrs = []): Webshop
    {
        return Webshop::factory()->withMember($user, $role, $extra)->create($attrs + ['created_by' => $user->id]);
    }

    public function test_verified_user_creates_a_draft_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Fietsenwinkel Utrecht',
            'platform' => 'woocommerce',
            // Tampering attempts: all ignored.
            'status' => 'approved',
            'role' => 'super_admin',
            'created_by' => 999,
            'owner' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Fietsenwinkel Utrecht')
            ->assertJsonPath('data.platform', 'woocommerce')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.commercially_active', false)
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.membership.role', 'owner');

        $this->assertEqualsCanonicalizing(
            array_map(fn ($p) => $p->value, WorkspacePermission::cases()),
            $response->json('data.membership.permissions'),
        );

        $webshop = Webshop::sole();
        $this->assertSame($user->id, $webshop->created_by);
        $this->assertSame(WebshopStatus::Draft, $webshop->status);
        $this->assertSame($webshop->public_id, $response->json('data.id'));
        $this->assertSame($webshop->id, $user->fresh()->current_webshop_id);
        $this->assertSame($webshop->id, AuditEvent::where('action', 'workspace.created')->sole()->webshop_id);
    }

    public function test_unverified_user_cannot_create_a_workspace(): void
    {
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->postJson('/api/v1/workspaces', ['name' => 'Shop'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'email_not_verified');
    }

    public function test_validation_rejects_unknown_platform_and_short_name(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/workspaces', ['name' => 'x', 'platform' => 'myspace'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['name', 'platform']]]);
    }

    public function test_one_owner_manages_five_independent_webshops(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $ids = collect(['shopify', 'woocommerce', 'lightspeed_c', 'prestashop', 'wix'])
            ->map(fn ($platform, $i) => $this->postJson('/api/v1/workspaces', ['name' => "Shop {$i}", 'platform' => $platform])
                ->assertCreated()->json('data.id'));

        $list = $this->getJson('/api/v1/workspaces')->assertOk()->json('data');
        $this->assertCount(5, $list);
        $this->assertEqualsCanonicalizing($ids->all(), array_column($list, 'id'));

        foreach ($ids as $i => $id) {
            $this->getJson("/api/v1/workspaces/{$id}")->assertOk()->assertJsonPath('data.name', "Shop {$i}");
        }

        $this->assertSame(5, WebshopMembership::where('user_id', $owner->id)->where('role', 'owner')->count());
    }

    public function test_unapproved_draft_cap_is_enforced(): void
    {
        config(['shopbonus.workspaces.max_unapproved_per_user' => 2]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/workspaces', ['name' => 'One'])->assertCreated();
        $this->postJson('/api/v1/workspaces', ['name' => 'Two'])->assertCreated();
        $this->postJson('/api/v1/workspaces', ['name' => 'Three'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'workspace_draft_limit_reached');
    }

    public function test_one_user_has_different_roles_in_different_webshops(): void
    {
        $user = User::factory()->create();
        $own = $this->webshopFor($user, MembershipRole::Owner, [], ['name' => 'Mine']);
        $theirs = $this->webshopFor(User::factory()->create(), MembershipRole::Owner, [], ['name' => 'Theirs']);
        $theirs->memberships()->create(['user_id' => $user->id, 'role' => MembershipRole::Employee, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Sanctum::actingAs($user);

        $roles = collect($this->getJson('/api/v1/workspaces')->json('data'))->pluck('membership.role', 'name')->all();
        $this->assertSame(['Mine' => 'owner', 'Theirs' => 'employee'], $roles);

        $this->patchJson("/api/v1/workspaces/{$own->public_id}", ['name' => 'Mine renamed'])->assertOk();

        $this->patchJson("/api/v1/workspaces/{$theirs->public_id}", ['name' => 'Hijacked'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_permission_denied')
            ->assertJsonPath('error.details.required_permission', 'settings.manage');

        $this->assertSame('Theirs', $theirs->fresh()->name);
        $denied = AuditEvent::where('action', 'auth.forbidden')->sole();
        $this->assertSame($theirs->id, $denied->webshop_id);
        $this->assertSame($user->id, $denied->actor_user_id);
    }

    public function test_other_tenants_webshop_is_indistinguishable_from_a_missing_one_and_audited(): void
    {
        $intruder = User::factory()->create();
        $victim = $this->webshopFor(User::factory()->create());
        Sanctum::actingAs($intruder);

        $foreign = $this->getJson("/api/v1/workspaces/{$victim->public_id}");
        $missing = $this->getJson('/api/v1/workspaces/'.strtolower((string) str()->ulid()));
        $garbage = $this->getJson('/api/v1/workspaces/1');

        foreach ([$foreign, $missing, $garbage] as $response) {
            $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
        $this->assertSame($foreign->json('error.message'), $missing->json('error.message'));
        $this->assertStringNotContainsString($victim->name, $foreign->getContent());

        $this->patchJson("/api/v1/workspaces/{$victim->public_id}", ['name' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/workspaces/{$victim->public_id}/select")->assertNotFound();
        $this->assertNull($intruder->fresh()->current_webshop_id);

        $audits = AuditEvent::where('action', 'workspace.access_denied')->get();
        $this->assertCount(5, $audits);
        $this->assertSame('not_a_member', $audits->first()->metadata['reason']);
        $this->assertSame($intruder->id, $audits->first()->actor_user_id);
    }

    public function test_removed_or_suspended_membership_loses_access_immediately(): void
    {
        $user = User::factory()->create();
        $webshop = $this->webshopFor($user, MembershipRole::Manager);
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/workspaces/{$webshop->public_id}")->assertOk();

        foreach ([MembershipStatus::Suspended, MembershipStatus::Removed] as $status) {
            WebshopMembership::where('user_id', $user->id)->update(['status' => $status]);

            $this->getJson("/api/v1/workspaces/{$webshop->public_id}")->assertNotFound();
            $this->assertCount(0, $this->getJson('/api/v1/workspaces')->json('data'));
        }
    }

    public function test_suspended_webshop_blocks_its_members(): void
    {
        $user = User::factory()->create();
        $webshop = $this->webshopFor($user, MembershipRole::Owner, [], ['status' => WebshopStatus::Suspended]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/workspaces/{$webshop->public_id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_suspended');
    }

    public function test_server_side_current_workspace_selection(): void
    {
        $user = User::factory()->create();
        $a = $this->webshopFor($user, MembershipRole::Owner, [], ['name' => 'A']);
        $b = $this->webshopFor($user, MembershipRole::Owner, [], ['name' => 'B']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/workspaces/current')->assertStatus(409)->assertJsonPath('error.code', 'no_current_workspace');

        $this->postJson("/api/v1/workspaces/{$b->public_id}/select")->assertOk()->assertJsonPath('data.is_current', true);
        $this->getJson('/api/v1/workspaces/current')->assertOk()->assertJsonPath('data.name', 'B');

        $this->postJson("/api/v1/workspaces/{$a->public_id}/select")->assertOk();
        $this->getJson('/api/v1/workspaces/current')->assertOk()->assertJsonPath('data.name', 'A');
        $this->assertSame(2, AuditEvent::where('action', 'workspace.switched')->count());

        // Selection is re-validated on use: losing membership closes `current` too.
        WebshopMembership::where('webshop_id', $a->id)->update(['status' => MembershipStatus::Removed]);
        $this->getJson('/api/v1/workspaces/current')->assertNotFound();
    }

    public function test_switcher_list_search(): void
    {
        $user = User::factory()->create();
        $this->webshopFor($user, MembershipRole::Owner, [], ['name' => 'Fietsen Utrecht']);
        $this->webshopFor($user, MembershipRole::Owner, [], ['name' => 'Boeken Amsterdam']);
        Sanctum::actingAs($user);

        $this->assertSame(['Fietsen Utrecht'], array_column($this->getJson('/api/v1/workspaces?search=fiets')->json('data'), 'name'));
        $this->assertCount(0, $this->getJson('/api/v1/workspaces?search=%25')->json('data'));
    }

    public function test_owner_only_permissions_never_apply_to_managers_even_if_stored(): void
    {
        $user = User::factory()->create();
        $webshop = $this->webshopFor($user, MembershipRole::Manager, ['agreements.accept', 'ownership.manage', 'bogus.permission']);
        $membership = $webshop->memberships()->sole();

        $this->assertFalse($membership->allows(WorkspacePermission::AgreementsAccept));
        $this->assertFalse($membership->allows(WorkspacePermission::OwnershipManage));
        $this->assertTrue($membership->allows(WorkspacePermission::SettingsManage));
    }

    public function test_employee_has_only_dashboard_plus_explicit_grants(): void
    {
        $user = User::factory()->create();
        $webshop = $this->webshopFor($user, MembershipRole::Employee, ['orders.view']);
        Sanctum::actingAs($user);

        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'orders.view'],
            $this->getJson("/api/v1/workspaces/{$webshop->public_id}")->json('data.membership.permissions'),
        );
    }

    public function test_me_lists_real_memberships_and_destination(): void
    {
        $user = User::factory()->create();
        $webshop = $this->webshopFor($user, MembershipRole::Manager, [], ['name' => 'Shop A']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.destination', 'workspace')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.workspaces.0.id', $webshop->public_id)
            ->assertJsonPath('data.workspaces.0.role', 'manager')
            ->assertJsonPath('data.workspaces.0.status', 'draft');
    }

    public function test_card4_e2e3_employee_login_sees_assigned_memberships_only(): void
    {
        $employee = User::factory()->create(['email' => 'medewerker@shop.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $assigned = $this->webshopFor(User::factory()->create(), MembershipRole::Owner, [], ['name' => 'Werkgever']);
        $assigned->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $other = $this->webshopFor(User::factory()->create(), MembershipRole::Owner, [], ['name' => 'Concurrent']);

        $this->spa('POST', '/login', ['email' => 'medewerker@shop.nl', 'password' => 'Sterk-wachtwoord-2026'])
            ->assertOk()
            ->assertJsonPath('data.destination', 'workspace')
            ->assertJsonPath('data.workspaces.0.name', 'Werkgever')
            ->assertJsonCount(1, 'data.workspaces');

        $this->spa('GET', "/api/v1/workspaces/{$assigned->public_id}")->assertOk();
        $response = $this->spa('GET', "/api/v1/workspaces/{$other->public_id}")->assertNotFound();
        $this->assertStringNotContainsString('Concurrent', $response->getContent());
    }
}
