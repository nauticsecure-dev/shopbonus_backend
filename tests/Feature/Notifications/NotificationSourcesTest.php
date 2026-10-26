<?php

namespace Tests\Feature\Notifications;

use App\Enums\AuditCategory;
use App\Enums\ConsentPurpose;
use App\Enums\MembershipRole;
use App\Enums\PlatformRole;
use App\Models\MerchantNotification;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Admin\StepUpCodes;
use App\Services\Audit\AuditLogger;
use App\Services\Consumer\CommunicationPreferences;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\NotificationSources;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Support\SupportChat;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class NotificationSourcesTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    public function test_event_outbox_is_transactional_and_recoverable_without_inline_fanout(): void
    {
        $user = User::factory()->create();
        DB::beginTransaction();
        app(NotificationSources::class)->capture('email_verified', 'verification:fixture', [$user->id]);
        $this->assertSame(1, NotificationEvent::count());
        $this->assertSame(0, $user->notifications()->count());
        DB::rollBack();
        $this->assertSame(0, NotificationEvent::count());
        app(NotificationSources::class)->capture('email_verified', 'verification:fixture', [$user->id]);
        app(NotificationSources::class)->capture('email_verified', 'verification:fixture', [$user->id]);
        $this->artisan('shopbonus:dispatch-notifications')->assertSuccessful();
        $this->artisan('shopbonus:dispatch-notifications')->assertSuccessful();
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('processed', NotificationEvent::firstOrFail()->status);
        $this->assertStringNotContainsString('recipients', DB::table('notification_events')->value('payload'));
    }

    public function test_merchant_compatibility_bridge_is_once_per_recipient_and_read_states_are_personal(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $shop = $this->activeWebshop($user);
        $shop->memberships()->create(['user_id' => $other->id, 'role' => MembershipRole::Owner, 'status' => 'active', 'joined_at' => now()]);
        $n = MerchantNotification::create(['webshop_id' => $shop->id, 'type' => 'low_inventory', 'severity' => 'warning', 'title' => 'Legacy private title', 'body' => 'Legacy private body', 'dedupe_key' => 'fixture']);
        $this->artisan('shopbonus:dispatch-notifications', ['--bridge' => true])->assertSuccessful();
        $this->artisan('shopbonus:dispatch-notifications', ['--bridge' => true])->assertSuccessful();
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());
        $this->assertStringNotContainsString('Legacy private', json_encode($user->notifications()->firstOrFail()->data));
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/workspaces/'.$shop->public_id.'/notifications/'.$n->public_id.'/read')->assertOk()->assertJsonPath('data.read', true);
        $this->assertNotNull($user->notifications()->firstOrFail()->read_at);
        $this->assertNull($other->notifications()->firstOrFail()->read_at);
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_membership_and_admin_role_are_rechecked_before_fanout(): void
    {
        $user = User::factory()->create();
        $shop = $this->activeWebshop($user);
        $role = $user->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        app(NotificationSources::class)->workspace('workspace_approved', $shop->id, 'workspace:fixture');
        app(NotificationSources::class)->administrators('ai_support_escalated', 'review:fixture');
        $role->forceFill(['revoked_at' => now()])->save();
        $shop->memberships()->update(['status' => 'removed']);
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_posted_correction_creates_an_intent_but_arbitrary_credit_and_pending_do_not_claim_cashback(): void
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($user);
        $wallets->addPending($wallet, 700, 'pending-fixture');
        $wallets->credit($wallet, 700, 'arbitrary-fixture');
        $this->assertSame(0, NotificationEvent::count());
        $wallets->adjust($wallet, 100, 'correction-fixture', 'Verified correction', null);
        $this->assertSame('cashback_adjusted', NotificationEvent::firstOrFail()->event_type);
        app(NotificationDispatcher::class)->drain();
        $this->assertSame('cashback_adjusted', $user->notifications()->firstOrFail()->event_type);
    }

    public function test_real_source_audit_creates_admin_intent_and_no_ordinary_request_noise(): void
    {
        $user = User::factory()->create();
        $user->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        app(AuditLogger::class)->record('ordinary.read', category: AuditCategory::Security);
        $this->assertSame(0, NotificationEvent::count());
        app(AuditLogger::class)->record('affiliate.sync_failed', category: AuditCategory::Integration);
        $this->assertSame('affiliate_import_failed', NotificationEvent::firstOrFail()->event_type);
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_complete_catalogue_mandatory_channels_and_unknown_inputs_are_enforced(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notification-preferences')->assertJsonPath('data.preferences.cashback_credited.email', false)
            ->assertJsonPath('data.preferences.voucher_received.email', true)->assertJsonPath('data.preferences.favorite_merchant_offer.in_app', false);
        $this->putJson('/api/v1/notification-preferences', ['preferences' => ['voucher_received' => ['email' => false]]])->assertUnprocessable();
        $this->putJson('/api/v1/notification-preferences', ['preferences' => ['favorite_merchant_offer' => ['in_app' => true]]])->assertOk();
        $this->assertFalse(app(CommunicationPreferences::class)->allows($user, ConsentPurpose::MarketingInspiration));
        $this->assertCount(23, app(NotificationPreferences::class)->current($user)['preferences']);
    }

    public function test_legacy_read_before_projection_is_recorded_for_only_the_acting_recipient(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $shop = $this->activeWebshop($user);
        $shop->memberships()->create(['user_id' => $other->id, 'role' => MembershipRole::Owner, 'status' => 'active', 'joined_at' => now()]);
        $n = MerchantNotification::create(['webshop_id' => $shop->id, 'type' => 'low_inventory', 'severity' => 'warning', 'title' => 'Legacy', 'body' => 'Legacy', 'dedupe_key' => 'delayed-projection']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/workspaces/'.$shop->public_id.'/notifications/'.$n->public_id.'/read')->assertOk();
        app(NotificationDispatcher::class)->drain();
        $this->assertNotNull($user->notifications()->firstOrFail()->read_at);
        $this->assertNull($other->notifications()->firstOrFail()->read_at);
    }

    public function test_automatic_support_review_creates_one_admin_notice_without_explicit_user_escalation(): void
    {
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $user = User::factory()->create();
        $message = app(SupportChat::class)->submit($user, ['message' => 'I have a refund complaint'], (string) Str::uuid());
        app(SupportChat::class)->execute($message->id);
        $this->assertSame(1, NotificationEvent::where('event_type', 'ai_support_escalated')->count());
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(1, $admin->notifications()->where('event_type', 'ai_support_escalated')->count());
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_exhausted_inbox_dispatch_preserves_source_and_admin_retry_recovers_once(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        app(NotificationSources::class)->capture('email_verified', 'source-survives-dispatch-failure', [$user->id]);
        $event = NotificationEvent::where('event_type', 'email_verified')->sole();
        $this->app->bind(NotificationTemplates::class, fn () => throw new \RuntimeException('Synthetic rendering subsystem failure'));
        for ($i = 0; $i < 3; $i++) {
            app(NotificationDispatcher::class)->drain();
            $this->travel(6)->minutes();
        }
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame(3, $event->fresh()->attempts);
        $this->assertSame(0, $user->notifications()->count());
        $this->app->bind(NotificationTemplates::class, NotificationTemplates::class);
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(1, $admin->notifications()->where('event_type', 'critical_notification_failure')->count());
        Sanctum::actingAs($admin);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => time()]);
        $this->postJson('/api/v1/platform/notifications/events/'.$event->public_id.'/retry', ['note' => 'Recovered notification renderer independently verified'])->assertOk();
        app(NotificationDispatcher::class)->drain();
        app(NotificationDispatcher::class)->drain();
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertSame(1, $user->notifications()->count());
    }
}
