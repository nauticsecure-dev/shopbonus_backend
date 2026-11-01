<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Models\AuditEvent;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-108 ownership transfer (Trello #16): owner proposes, member accepts,
 * both with a recent password confirmation; atomic, audited swap.
 */
class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);
        $this->manager = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $this->manager->id, 'role' => MembershipRole::Manager, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
    }

    /** Browser session with a recent password confirmation (password.recent). */
    private function as(User $user, bool $confirmed = true): void
    {
        Sanctum::actingAs($user);
        $this->flushSession();
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession($confirmed ? ['auth.password_confirmed_at' => time()] : []);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}/ownership-transfer{$suffix}";
    }

    private function role(User $user): MembershipRole
    {
        return WebshopMembership::where('webshop_id', $this->shop->id)->where('user_id', $user->id)->sole()->role;
    }

    public function test_owner_proposes_member_accepts_and_roles_swap_atomically(): void
    {
        $this->as($this->owner);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.to.id', $this->manager->public_id);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertStatus(409)->assertJsonPath('error.code', 'transfer_pending');

        $this->as($this->manager);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson($this->url('/accept'))->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertSame([MembershipRole::Manager, MembershipRole::Owner], [$this->role($this->owner), $this->role($this->manager)]);
        $this->assertSame(1, WebshopMembership::where('webshop_id', $this->shop->id)->where('role', MembershipRole::Owner)->count());
        $this->assertSame(['ownership.transfer_started', 'ownership.transfer_accepted'],
            AuditEvent::where('action', 'like', 'ownership.transfer_%')->orderBy('id')->pluck('action')->all());

        // The former owner lost owner-only powers.
        $this->as($this->owner);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertForbidden();
    }

    public function test_only_the_owner_starts_only_the_target_accepts_and_both_need_a_recent_password(): void
    {
        $other = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $other->id, 'role' => MembershipRole::Employee, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->as($this->manager);
        $this->postJson($this->url(), ['member' => $other->public_id])->assertForbidden();
        $this->as($this->owner, confirmed: false);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertStatus(423)->assertJsonPath('error.code', 'password_confirmation_required');
        $this->as($this->owner);
        $this->postJson($this->url(), ['member' => $this->owner->public_id])->assertStatus(409)->assertJsonPath('error.code', 'already_owner');
        $this->postJson($this->url(), ['member' => User::factory()->create()->public_id])->assertNotFound();
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertCreated();

        // A bystander neither sees nor accepts it; the target needs a fresh password too.
        $this->as($other);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data', null);
        $this->postJson($this->url('/accept'))->assertNotFound();
        $this->as($this->manager, confirmed: false);
        $this->postJson($this->url('/accept'))->assertStatus(423);
        $this->assertSame(MembershipRole::Owner, $this->role($this->owner));
    }

    public function test_decline_cancel_and_expiry_end_the_transfer_without_changing_roles(): void
    {
        $this->as($this->owner);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertCreated();
        $this->as($this->manager);
        $this->deleteJson($this->url())->assertOk()->assertJsonPath('data.status', 'declined');

        $this->as($this->owner);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertCreated();
        $this->deleteJson($this->url())->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertCreated();
        $this->travel(73)->hours();
        $this->as($this->manager);
        $this->postJson($this->url('/accept'))->assertNotFound();
        $this->assertSame('expired', OwnershipTransfer::latest('id')->first()->status);
        $this->assertSame([MembershipRole::Owner, MembershipRole::Manager], [$this->role($this->owner), $this->role($this->manager)]);
    }

    public function test_a_change_since_the_proposal_voids_it(): void
    {
        $this->as($this->owner);
        $this->postJson($this->url(), ['member' => $this->manager->public_id])->assertCreated();
        $before = $this->shop->status;
        $this->shop->forceFill(['status' => WebshopStatus::Suspended])->save();

        $this->as($this->manager);
        $this->postJson($this->url('/accept'))->assertForbidden(); // the workspace middleware refuses suspended webshops
        $this->shop->forceFill(['status' => $before])->save();
        WebshopMembership::where('user_id', $this->owner->id)->update(['status' => MembershipStatus::Suspended]);
        $this->postJson($this->url('/accept'))->assertStatus(409)->assertJsonPath('error.code', 'transfer_invalid');
        $this->assertSame('cancelled', OwnershipTransfer::sole()->status);
        $this->assertSame(MembershipRole::Manager, $this->role($this->manager));
    }
}
