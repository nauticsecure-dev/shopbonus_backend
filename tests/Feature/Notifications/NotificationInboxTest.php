<?php

namespace Tests\Feature\Notifications;

use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\WebshopStatus;
use App\Events\NotificationInboxChanged;
use App\Models\CentralNotification;
use App\Models\SupportConversation;
use App\Models\SupportReply;
use App\Models\User;
use App\Services\Admin\SupportSessions;
use App\Services\Notifications\NotificationInbox;
use App\Services\Support\SupportReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private function reply(User $user, ?int $webshopId = null): array
    {
        $c = SupportConversation::create(['user_id' => $user->id, 'webshop_id' => $webshopId, 'locale' => 'nl', 'expires_at' => now()->addDays(30)]);
        $r = app(SupportReviews::class)->reply(User::factory()->create(), $c, 'Private support text with voucher SECRET-CODE');

        return [$c, $r];
    }

    public function test_owned_inbox_read_state_filter_pagination_and_generic_encrypted_content(): void
    {
        $user = User::factory()->create(['locale' => 'nl']);
        [$c, $reply] = $this->reply($user);
        $this->reply(User::factory()->create());
        $raw = DB::table('notifications')->where('notifiable_id', $user->id)->first();
        $this->assertStringNotContainsString('supportgesprek', $raw->data);
        Sanctum::actingAs($user);
        $r = $this->getJson('/api/v1/notifications?filter=unread&per_page=1')->assertOk()
            ->assertJsonPath('meta.unread_count', 1)->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Nieuw antwoord van support')
            ->assertJsonPath('data.0.action_route', '/app/support?conversation='.$c->public_id);
        $this->assertStringNotContainsString('SECRET-CODE', $r->getContent());
        $this->assertStringNotContainsString('event_key', $r->getContent());
        $id = $r->json('data.0.id');
        $first = $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertOk()->json('data.read_at');
        $this->travel(1)->hours();
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertOk()->assertJsonPath('data.read_at', $first);
        $this->getJson('/api/v1/notifications?filter=unread')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.unread_count', 0);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 0);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_other_recipient_cannot_read_or_mark_foreign_notifications_even_as_admin(): void
    {
        $owner = User::factory()->create();
        $this->reply($owner);
        $id = $owner->notifications()->firstOrFail()->id;
        $other = User::factory()->create();
        $other->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all')->assertJsonPath('data.marked_read', 0);
        $this->assertNull(CentralNotification::findOrFail($id)->read_at);
    }

    public function test_workspace_history_and_counts_disappear_after_membership_or_workspace_revocation(): void
    {
        $user = User::factory()->create();
        $shop = $this->activeWebshop($user);
        $this->reply($user, $shop->id);
        $id = $user->notifications()->firstOrFail()->id;
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.type', 'workspace_support_reply')->assertJsonPath('meta.unread_count', 1);
        $shop->memberships()->where('user_id', $user->id)->update(['status' => MembershipStatus::Removed]);
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0)->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all')->assertJsonPath('data.marked_read', 0);
        $shop->memberships()->where('user_id', $user->id)->update(['status' => MembershipStatus::Active]);
        $shop->forceFill(['status' => WebshopStatus::Suspended])->save();
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.total', 0);
        $this->assertNull(CentralNotification::findOrFail($id)->read_at);
    }

    public function test_transaction_rollback_leaves_no_reply_or_inbox_and_replay_deduplicates(): void
    {
        $user = User::factory()->create();
        DB::beginTransaction();
        [$c, $r] = $this->reply($user);
        $this->assertSame(1, $user->notifications()->count());
        DB::rollBack();
        $this->assertSame(0, SupportReply::count());
        $this->assertSame(0, CentralNotification::count());
        [$c, $r] = $this->reply($user);
        DB::transaction(fn () => app(NotificationInbox::class)->supportReply($c, $r));
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_preferences_share_locale_preserve_consent_and_enforce_mandatory_in_app(): void
    {
        $user = User::factory()->create(['locale' => 'nl']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notification-preferences')->assertOk()->assertJsonPath('data.preferences.support_reply.in_app', true)
            ->assertJsonPath('data.email_delivery_available', false);
        $this->putJson('/api/v1/notification-preferences', ['locale' => 'en', 'email_enabled' => false,
            'preferences' => ['support_reply' => ['email' => true]]])->assertOk()->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.email_enabled', false)->assertJsonPath('data.preferences.support_reply.email', true);
        $this->assertSame('en', $user->fresh()->locale);
        $this->assertDatabaseCount('consent_records', 0);
        $this->reply($user->fresh());
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.title', 'New support reply')->assertJsonPath('meta.unread_count', 1);
        foreach ([['preferences' => ['support_reply' => ['in_app' => false]]], ['preferences' => ['unknown' => ['email' => true]]],
            ['preferences' => ['support_reply' => ['action_route' => 'https://evil.test']]], ['locale' => 'xx'], ['email_enabled' => 'no']] as $input) {
            $this->putJson('/api/v1/notification-preferences', $input)->assertUnprocessable();
        }
    }

    public function test_strict_pagination_and_auth_account_verification_gates(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->putJson('/api/v1/notification-preferences', [])->assertUnauthorized();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertForbidden();
        $user->forceFill(['email_verified_at' => now(), 'status' => UserStatus::Suspended])->save();
        $this->getJson('/api/v1/notifications')->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        foreach (['per_page=101', 'per_page=0', 'page=-1', 'page=10001', 'filter=other'] as $query) {
            $this->getJson('/api/v1/notifications?'.$query)->assertUnprocessable();
        }
        $this->patchJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
    }

    public function test_private_channel_and_support_impersonation_never_expose_inbox(): void
    {
        $user = User::factory()->create();
        $callback = Broadcast::getChannels()['shopbonus.user.{userId}.notifications'];
        $this->assertTrue($callback($user, $user->public_id));
        $this->assertFalse($callback(User::factory()->create(), $user->public_id));
        $user->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($callback($user, $user->public_id));
        $user->forceFill(['email_verified_at' => now()])->save();
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        [, $plain] = app(SupportSessions::class)->start($admin, $user, 'Test notification privacy');
        $user->withAccessToken(PersonalAccessToken::findToken($plain));
        $this->assertFalse($callback($user, $user->public_id));
        $this->getJson('/api/v1/notifications', ['Authorization' => 'Bearer '.$plain])->assertForbidden()->assertJsonPath('error.code', 'blocked_during_impersonation');
        $this->putJson('/api/v1/notification-preferences', [], ['Authorization' => 'Bearer '.$plain])->assertForbidden();
        foreach (['NotificationCreated', 'NotificationRead', 'NotificationReadAll', 'NotificationCountUpdated'] as $name) {
            $event = new NotificationInboxChanged($user->public_id, $name, 'opaque-id');
            $this->assertSame(['notification_id' => 'opaque-id'], $event->broadcastWith());
            $this->assertSame($name, $event->broadcastAs());
            $this->assertSame('private-shopbonus.user.'.$user->public_id.'.notifications', $event->broadcastOn()[0]->name);
        }
    }

    public function test_notification_writes_require_csrf_for_browser_requests_and_respect_rate_limits(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->postJson('/api/v1/notifications/read-all', [], ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->assertStatus(419);
        $this->app->detectEnvironment(fn () => 'testing');
        config(['shopbonus.api.rate_limit_per_minute' => 1]);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/notifications')->assertOk();
        $this->getJson('/api/v1/notification-preferences')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_revoked_admin_role_hides_historical_admin_entries_without_tenant_bypass(): void
    {
        $user = User::factory()->create();
        $role = $user->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->reply($user);
        $n = $user->notifications()->firstOrFail();
        $n->forceFill(['recipient_type' => 'admin'])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 1);
        $role->forceFill(['revoked_at' => now()])->save();
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0);
        $this->patchJson('/api/v1/notifications/'.$n->id.'/read')->assertNotFound();
    }

    public function test_no_new_inbox_delivery_for_inactive_or_removed_recipients(): void
    {
        $user = User::factory()->create();
        $shop = $this->activeWebshop($user);
        $shop->memberships()->where('user_id', $user->id)->update(['status' => MembershipStatus::Removed]);
        $this->reply($user, $shop->id);
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $this->reply($user);
        $this->assertSame(0, CentralNotification::count());
    }

    public function test_storage_failure_uses_safe_global_500_envelope(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config(['app.debug' => false]);
        $this->app->bind(NotificationInbox::class, fn () => throw new \RuntimeException('SECRET fixture storage detail'));
        $response = $this->getJson('/api/v1/notifications')->assertStatus(500)->assertJsonPath('error.code', 'server_error')
            ->assertJsonStructure(['error' => ['request_id']]);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }

    public function test_read_all_only_marks_current_users_visible_unread_entries(): void
    {
        $user = User::factory()->create();
        $this->reply($user);
        $this->reply($user);
        $this->reply(User::factory()->create());
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 2);
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0)->assertJsonPath('meta.total', 2);
        $this->assertSame(1, CentralNotification::whereNull('read_at')->count());
    }
}
