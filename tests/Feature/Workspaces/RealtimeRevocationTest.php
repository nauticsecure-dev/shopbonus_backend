<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Admin\SupportSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Pusher\Pusher;
use Pusher\PusherException;
use Tests\TestCase;

/**
 * Trello #16: withdrawing workspace access terminates the user's open
 * realtime sockets; channel auth alone only stops new subscriptions.
 */
class RealtimeRevocationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);
    }

    private function member(array $grants = []): User
    {
        $user = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $user->id, 'role' => MembershipRole::Employee, 'permissions' => $grants ?: null,
            'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return $user;
    }

    /** Reverb (Pusher protocol) with the HTTP client replaced. */
    private function sockets(): MockInterface
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'SYNTHETIC_key',
            'broadcasting.connections.reverb.secret' => 'SYNTHETIC_secret', 'broadcasting.connections.reverb.app_id' => '1']);
        $pusher = Mockery::mock(Pusher::class);
        Broadcast::connection('reverb')->setPusher($pusher);

        return $pusher;
    }

    private function url(string $suffix): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    public function test_removal_and_suspension_terminate_the_members_open_sockets(): void
    {
        $removed = $this->member(['orders.view']);
        $suspended = $this->member(['orders.view']);
        $pusher = $this->sockets();
        $pusher->shouldReceive('terminateUserConnections')->once()->with($removed->public_id)->andReturn((object) []);
        $pusher->shouldReceive('terminateUserConnections')->once()->with($suspended->public_id)->andReturn((object) []);

        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url("/members/{$removed->public_id}"))->assertNoContent();
        $this->postJson($this->url("/members/{$suspended->public_id}/suspend"))->assertOk();
    }

    public function test_losing_orders_view_terminates_but_unrelated_edits_do_not(): void
    {
        $viewer = $this->member(['orders.view']);
        $other = $this->member();
        $pusher = $this->sockets();
        $pusher->shouldReceive('terminateUserConnections')->once()->with($viewer->public_id)->andReturn((object) []);

        Sanctum::actingAs($this->owner);
        $this->patchJson($this->url("/members/{$other->public_id}"), ['permissions' => ['offers.view']])->assertOk();
        $this->patchJson($this->url("/members/{$viewer->public_id}"), ['permissions' => ['offers.view']])->assertOk();
    }

    public function test_a_realtime_outage_never_blocks_the_revocation(): void
    {
        $removed = $this->member(['orders.view']);
        $this->sockets()->shouldReceive('terminateUserConnections')->once()->andThrow(new PusherException('Reverb unreachable'));

        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url("/members/{$removed->public_id}"))->assertNoContent();
        $this->assertSame(MembershipStatus::Removed, $this->shop->memberships()->where('user_id', $removed->id)->sole()->status);
    }

    public function test_user_sign_in_identifies_by_public_id_and_refuses_support_sessions(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/broadcasting/user-auth', ['socket_id' => '123.456'])->assertOk()->assertExactJson(['id' => $this->owner->public_id]);

        $this->app['auth']->forgetGuards();
        $support = $this->owner->createToken(SupportSessions::TOKEN_NAME)->plainTextToken;
        $this->postJson('/api/broadcasting/user-auth', ['socket_id' => '123.456'], ['Authorization' => "Bearer {$support}"])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/broadcasting/user-auth', ['socket_id' => '123.456'])->assertUnauthorized();
    }
}
