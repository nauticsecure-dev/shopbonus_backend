<?php

namespace Tests\Feature\Workspaces;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopInvitation;
use App\Models\WebshopMembership;
use App\Notifications\WorkspaceInvitationMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B03b team management (Trello #16 E2E 7, 8, 9 membership half).
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = User::factory()->create(['first_name' => 'Jan']);
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['name' => 'Fietsen', 'created_by' => $this->owner->id]);
    }

    private function member(MembershipRole $role, array $grants = [], ?Webshop $shop = null): User
    {
        $user = User::factory()->create();
        ($shop ?? $this->shop)->memberships()->create([
            'user_id' => $user->id, 'role' => $role, 'permissions' => $grants ?: null,
            'status' => MembershipStatus::Active, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    private function invite(string $email, string $role = 'employee', array $permissions = []): TestResponse
    {
        return $this->postJson($this->url('/invitations'), compact('email', 'role', 'permissions'));
    }

    private function tokenFor(string $email): string
    {
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitationMail::class,
            function (WorkspaceInvitationMail $mail, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
                if (($notifiable->routes['mail'] ?? null) === $email) {
                    $token = $mail->token;
                }

                return true;
            });

        return (string) $token;
    }

    // ------------------------------------------------------------- E2E 7: invitations

    public function test_e2e7_existing_user_accepts_invitation_and_gets_exactly_the_invited_access(): void
    {
        $invitee = User::factory()->create(['email' => 'piet@example.nl']);
        Sanctum::actingAs($this->owner);

        $this->invite('PIET@example.nl', 'employee', ['orders.view'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'piet@example.nl')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.resend_count', 0)
            ->assertJsonMissingPath('data.token_hash');

        $token = $this->tokenFor('piet@example.nl');
        $this->assertSame(48, strlen($token));
        $this->assertStringNotContainsString($token, json_encode(WebshopInvitation::sole()->getAttributes()));
        $this->assertContains(ShouldBeEncrypted::class, class_implements(WorkspaceInvitationMail::class));

        $this->postJson('/api/v1/invitations/preview', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.webshop.name', 'Fietsen')
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.invited_by', 'Jan')
            ->assertJsonPath('data.email_hint', 'p***@example.nl');

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.id', $this->shop->public_id)
            ->assertJsonPath('data.membership.role', 'employee')
            ->assertJsonPath('data.membership.permissions', ['dashboard.view', 'orders.view']);

        $this->assertSame(InvitationStatus::Accepted, WebshopInvitation::sole()->status);
        $this->getJson($this->url())->assertOk();
    }

    public function test_e2e7_new_user_registers_later_and_accepts_with_the_same_identity(): void
    {
        Sanctum::actingAs($this->owner);
        $this->invite('nieuw@example.nl')->assertCreated();
        $token = $this->tokenFor('nieuw@example.nl');

        $newcomer = User::factory()->create(['email' => 'nieuw@example.nl']);
        Sanctum::actingAs($newcomer);

        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->assertSame(1, WebshopMembership::where('user_id', $newcomer->id)->count());
    }

    public function test_e2e7_reused_expired_cancelled_and_unknown_tokens_fail(): void
    {
        $invitee = User::factory()->create(['email' => 'piet@example.nl']);
        Sanctum::actingAs($this->owner);
        $this->invite('piet@example.nl')->assertCreated();
        $token = $this->tokenFor('piet@example.nl');

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertStatus(410)->assertJsonPath('error.code', 'invitation_invalid'); // reused

        $this->postJson('/api/v1/invitations/accept', ['token' => str_repeat('a', 48)])->assertStatus(410); // unknown
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertStatus(410);

        // Expired.
        Sanctum::actingAs($this->owner);
        $this->invite('eva@example.nl')->assertCreated();
        $expired = $this->tokenFor('eva@example.nl');
        $eva = User::factory()->create(['email' => 'eva@example.nl']);
        $this->travel(8)->days();
        Sanctum::actingAs($eva);
        $this->postJson('/api/v1/invitations/accept', ['token' => $expired])->assertStatus(410);

        // Cancelled.
        $this->travelBack();
        Sanctum::actingAs($this->owner);
        $this->invite('ann@example.nl')->assertCreated();
        $cancelled = $this->tokenFor('ann@example.nl');
        $id = WebshopInvitation::where('email', 'ann@example.nl')->value('public_id');
        $this->deleteJson($this->url("/invitations/{$id}"))->assertOk()->assertJsonPath('data.status', 'cancelled');
        Sanctum::actingAs(User::factory()->create(['email' => 'ann@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $cancelled])->assertStatus(410);
    }

    public function test_e2e7_mismatched_recipient_cannot_accept_and_is_audited(): void
    {
        Sanctum::actingAs($this->owner);
        $this->invite('piet@example.nl')->assertCreated();
        $token = $this->tokenFor('piet@example.nl');

        Sanctum::actingAs(User::factory()->create(['email' => 'iemand-anders@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'invitation_recipient_mismatch');

        $this->assertSame(InvitationStatus::Pending, WebshopInvitation::sole()->status);
        $this->assertSame(1, AuditEvent::where('action', 'team.invitation_recipient_mismatch')->count());
    }

    public function test_resend_replaces_the_token_and_respects_cooldown(): void
    {
        Sanctum::actingAs($this->owner);
        $this->invite('piet@example.nl')->assertCreated();
        $first = $this->tokenFor('piet@example.nl');
        $id = WebshopInvitation::sole()->public_id;

        $this->postJson($this->url("/invitations/{$id}/resend"))
            ->assertStatus(429)->assertJsonPath('error.code', 'invitation_resend_too_soon')->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->postJson($this->url("/invitations/{$id}/resend"))->assertOk()->assertJsonPath('data.resend_count', 1);

        $tokens = [];
        Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitationMail::class, function ($mail) use (&$tokens) {
            $tokens[] = $mail->token;

            return true;
        });
        $second = end($tokens);
        $this->assertNotSame($first, $second);

        Sanctum::actingAs(User::factory()->create(['email' => 'piet@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $first])->assertStatus(410);
        $this->postJson('/api/v1/invitations/accept', ['token' => $second])->assertOk();
    }

    public function test_duplicate_invitations_and_existing_members_conflict(): void
    {
        $existing = $this->member(MembershipRole::Employee);
        Sanctum::actingAs($this->owner);

        $this->invite($existing->email)->assertStatus(409)->assertJsonPath('error.code', 'already_member');
        $this->invite('piet@example.nl')->assertCreated();
        $this->invite('Piet@Example.nl')->assertStatus(409)->assertJsonPath('error.code', 'invitation_pending');
    }

    public function test_invitation_dies_when_inviter_loses_authority(): void
    {
        $manager = $this->member(MembershipRole::Manager);
        Sanctum::actingAs($manager);
        $this->invite('piet@example.nl')->assertCreated();
        $token = $this->tokenFor('piet@example.nl');

        WebshopMembership::where('user_id', $manager->id)->update(['status' => MembershipStatus::Removed]);

        Sanctum::actingAs(User::factory()->create(['email' => 'piet@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(410);
    }

    public function test_invitation_dies_when_inviter_loses_a_granted_permission_or_rank(): void
    {
        $lead = $this->member(MembershipRole::Employee, ['team.manage', 'orders.view', 'orders.export']);
        Sanctum::actingAs($lead);
        $this->invite('piet@example.nl', permissions: ['orders.export'])->assertCreated();
        $this->invite('kees@example.nl', permissions: ['orders.view'])->assertCreated();
        $exportToken = $this->tokenFor('piet@example.nl');
        $viewToken = $this->tokenFor('kees@example.nl');

        // Still a team manager, but no longer holds orders.export.
        WebshopMembership::where('user_id', $lead->id)->update(['permissions' => json_encode(['team.manage', 'orders.view'])]);

        $piet = User::factory()->create(['email' => 'piet@example.nl']);
        Sanctum::actingAs($piet);
        $this->postJson('/api/v1/invitations/accept', ['token' => $exportToken])->assertStatus(410)->assertJsonPath('error.code', 'invitation_invalid');
        $this->assertFalse(WebshopMembership::where('user_id', $piet->id)->exists());
        $this->assertSame(InvitationStatus::Pending, WebshopInvitation::where('email', 'piet@example.nl')->sole()->status);

        Sanctum::actingAs(User::factory()->create(['email' => 'kees@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $viewToken])->assertOk()
            ->assertJsonPath('data.membership.permissions', ['dashboard.view', 'orders.view']);

        // A manager who invited a manager and is then demoted keeps team.manage but not the rank.
        $manager = $this->member(MembershipRole::Manager);
        Sanctum::actingAs($manager);
        $this->invite('anna@example.nl', 'manager')->assertCreated();
        $managerToken = $this->tokenFor('anna@example.nl');
        WebshopMembership::where('user_id', $manager->id)->update(['role' => MembershipRole::Employee, 'permissions' => json_encode(['team.manage'])]);

        Sanctum::actingAs(User::factory()->create(['email' => 'anna@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $managerToken])->assertStatus(410);
    }

    public function test_invitations_list_shows_pending_only(): void
    {
        Sanctum::actingAs($this->owner);
        $this->invite('a@example.nl')->assertCreated();
        $this->invite('b@example.nl')->assertCreated();
        $id = WebshopInvitation::where('email', 'b@example.nl')->value('public_id');
        $this->deleteJson($this->url("/invitations/{$id}"))->assertOk();

        $this->assertSame(['a@example.nl'], array_column($this->getJson($this->url('/invitations'))->json('data'), 'email'));
    }

    // ------------------------------------------------------------- E2E 8: boundaries

    public function test_e2e8_nobody_can_grant_the_owner_role(): void
    {
        $employee = $this->member(MembershipRole::Employee);
        Sanctum::actingAs($this->owner);

        $this->invite('piet@example.nl', 'owner')->assertForbidden()->assertJsonPath('error.code', 'owner_role_not_grantable');
        $this->patchJson($this->url("/members/{$employee->public_id}"), ['role' => 'owner'])
            ->assertForbidden()->assertJsonPath('error.code', 'owner_role_not_grantable');
    }

    public function test_e2e8_manager_limits(): void
    {
        $manager = $this->member(MembershipRole::Manager);
        $otherManager = $this->member(MembershipRole::Manager);
        $employee = $this->member(MembershipRole::Employee);
        Sanctum::actingAs($manager);

        // May invite employees and managers, but never grant owner-only permissions.
        $this->invite('emp@example.nl', 'employee', ['orders.view'])->assertCreated();
        $this->invite('mgr@example.nl', 'manager')->assertCreated();
        $this->invite('x@example.nl', 'employee', ['agreements.accept'])
            ->assertForbidden()->assertJsonPath('error.code', 'permission_above_own');

        // Cannot promote self, change peers, or touch the owner.
        $this->patchJson($this->url("/members/{$manager->public_id}"), ['role' => 'manager'])
            ->assertForbidden()->assertJsonPath('error.code', 'cannot_manage_self');
        $this->postJson($this->url("/members/{$otherManager->public_id}/suspend"))
            ->assertForbidden()->assertJsonPath('error.code', 'member_not_below_you');
        $this->deleteJson($this->url("/members/{$this->owner->public_id}"))
            ->assertForbidden()->assertJsonPath('error.code', 'owner_not_manageable');

        // May manage employees.
        $this->patchJson($this->url("/members/{$employee->public_id}"), ['permissions' => ['orders.view', 'offers.view']])
            ->assertOk()->assertJsonPath('data.granted_permissions', ['orders.view', 'offers.view']);

        $this->assertGreaterThanOrEqual(4, AuditEvent::where('action', 'auth.forbidden')->where('webshop_id', $this->shop->id)->count());
    }

    public function test_e2e8_employee_cannot_manage_team_or_grant_beyond_own(): void
    {
        $employee = $this->member(MembershipRole::Employee, ['team.view']);
        Sanctum::actingAs($employee);

        $this->getJson($this->url('/members'))->assertOk();
        $this->invite('x@example.nl')->assertForbidden()->assertJsonPath('error.code', 'workspace_permission_denied');
    }

    public function test_e2e8_unknown_permission_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->invite('x@example.nl', 'employee', ['root.everything'])
            ->assertStatus(422)->assertJsonPath('error.code', 'unknown_permission');
    }

    public function test_e2e8_members_of_another_webshop_cannot_be_addressed(): void
    {
        $otherShop = Webshop::factory()->withMember(User::factory()->create())->create();
        $stranger = $this->member(MembershipRole::Employee, [], $otherShop);
        Sanctum::actingAs($this->owner);

        $this->patchJson($this->url("/members/{$stranger->public_id}"), ['role' => 'manager'])->assertNotFound();
        $this->deleteJson($this->url("/members/{$stranger->public_id}"))->assertNotFound();

        $foreignInvitation = WebshopInvitation::create([
            'webshop_id' => $otherShop->id, 'email' => 'z@example.nl', 'role' => 'employee', 'token_hash' => str_repeat('0', 64),
            'status' => 'pending', 'invited_by' => $otherShop->created_by, 'expires_at' => now()->addDay(), 'last_sent_at' => now(),
        ]);
        $this->deleteJson($this->url("/invitations/{$foreignInvitation->public_id}"))->assertNotFound();
        $this->assertSame(InvitationStatus::Pending, $foreignInvitation->fresh()->status);
    }

    // ------------------------------------------------------------- E2E 9: revocation

    public function test_e2e9_removed_member_is_blocked_immediately_and_loses_current_selection(): void
    {
        $employee = $this->member(MembershipRole::Employee);
        $employee->forceFill(['current_webshop_id' => $this->shop->id])->save();

        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url("/members/{$employee->public_id}"))->assertNoContent();

        Sanctum::actingAs($employee->fresh());
        $this->getJson($this->url())->assertNotFound();
        $this->assertNull($employee->fresh()->current_webshop_id);
        $this->assertSame(1, AuditEvent::where('action', 'team.member_removed')->count());
    }

    public function test_e2e9_suspend_and_reactivate(): void
    {
        $employee = $this->member(MembershipRole::Employee);
        Sanctum::actingAs($this->owner);

        $this->postJson($this->url("/members/{$employee->public_id}/suspend"))->assertOk()->assertJsonPath('data.status', 'suspended');
        Sanctum::actingAs($employee);
        $this->getJson($this->url())->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/members/{$employee->public_id}/reactivate"))->assertOk()->assertJsonPath('data.status', 'active');
        Sanctum::actingAs($employee);
        $this->getJson($this->url())->assertOk();
    }

    public function test_e2e9_final_owner_cannot_leave_but_a_co_owner_can(): void
    {
        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url('/membership'))->assertStatus(409)->assertJsonPath('error.code', 'final_owner');

        $coOwner = $this->member(MembershipRole::Owner);
        Sanctum::actingAs($coOwner);
        $this->deleteJson($this->url('/membership'))->assertNoContent();
        $this->assertSame(MembershipStatus::Removed, WebshopMembership::where('user_id', $coOwner->id)->sole()->status);
    }

    public function test_removed_member_can_be_invited_again_and_rejoins(): void
    {
        $employee = $this->member(MembershipRole::Employee);
        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url("/members/{$employee->public_id}"))->assertNoContent();

        $this->invite($employee->email, 'manager')->assertCreated();
        $token = $this->tokenFor($employee->email);

        Sanctum::actingAs($employee);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('data.membership.role', 'manager');
        $this->assertSame(1, WebshopMembership::where('user_id', $employee->id)->count());
    }

    public function test_member_list_shows_roles_permissions_and_activity(): void
    {
        $this->member(MembershipRole::Employee, ['orders.view']);
        Sanctum::actingAs($this->owner);

        $members = $this->getJson($this->url('/members'))->assertOk()->json('data');

        $this->assertSame(['owner', 'employee'], array_column($members, 'role'));
        $this->assertSame(['dashboard.view', 'orders.view'], $members[1]['permissions']);
        $this->assertArrayHasKey('last_activity_at', $members[0]);
        $this->assertArrayNotHasKey('user_id', $members[0]);
    }

    public function test_suspended_webshop_invitations_cannot_be_accepted(): void
    {
        Sanctum::actingAs($this->owner);
        $this->invite('piet@example.nl')->assertCreated();
        $token = $this->tokenFor('piet@example.nl');
        $this->shop->forceFill(['status' => WebshopStatus::Suspended])->save();

        Sanctum::actingAs(User::factory()->create(['email' => 'piet@example.nl']));
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(410);
    }
}
