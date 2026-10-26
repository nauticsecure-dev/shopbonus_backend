<?php

namespace Tests\Feature\Notifications;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherOrigin;
use App\Models\AffiliateConnection;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\Admin\PlatformSettings;
use App\Services\Affiliate\AffiliateProcessor;
use App\Services\Consumer\CommunicationPreferences;
use App\Services\Discovery\PublicProfileService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationMail;
use App\Services\Notifications\NotificationMaintenance;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\NotificationSources;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class NotificationJourneyTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private function voucher(User $consumer): Voucher
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $member = $shop->memberships()->sole()->setRelation('webshop', $shop)->setRelation('user', $owner);
        $offer = app(OfferService::class)->create($member, OfferAcquisition::MerchantReward, ['name' => 'Verified test offer', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 10000, 'redemption_validity_days' => 30, 'allocation_limit' => null]);
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'PRIVATE-NOTIFICATION-CODE', 'code_hash' => VoucherCode::hashFor($shop->id, 'PRIVATE-NOTIFICATION-CODE'), 'code_last4' => 'CODE']);
        $offer = app(OfferService::class)->transition($offer, OfferStatus::Active);

        return app(VoucherInventory::class)->issue(app(VoucherInventory::class)->reserve($offer, VoucherOrigin::MerchantReward, 'fixture:'.$consumer->public_id), $consumer);
    }

    public function test_e2e_credited_conversion_creates_one_exact_nl_wallet_notice_after_ledger_posting(): void
    {
        $user = User::factory()->create(['locale' => 'nl']);
        $connection = AffiliateConnection::create(['network' => 'daisycon', 'name' => 'Fixture', 'status' => 'connected', 'credentials' => [], 'postback_token_hash' => hash('sha256', 'fixture')]);
        AffiliateRewardRule::create(['version' => 1, 'unit' => 'eur_credit', 'share_bps' => 7000, 'min_commission_minor' => 0, 'active' => true, 'created_by' => $user->id]);
        $tx = AffiliateTransaction::create(['network' => 'daisycon', 'connection_id' => $connection->id, 'external_id' => 'verified-source-fixture', 'user_id' => $user->id,
            'order_amount_minor' => 10000, 'currency' => 'EUR', 'commission_minor' => 1000, 'network_status' => 'approved', 'status' => 'approved', 'transaction_at' => now()]);
        app(AffiliateProcessor::class)->settle($tx, 'admin', $user->id);
        app(AffiliateProcessor::class)->settle($tx->fresh(), 'admin', $user->id);
        $this->assertSame(0, $user->notifications()->count());
        app(NotificationDispatcher::class)->drain();
        app(NotificationDispatcher::class)->drain();
        $notice = $user->notifications()->where('event_type', 'cashback_credited')->sole();
        $this->assertStringContainsString('€7,00', $notice->data['body']);
        $this->assertSame(700, $tx->fresh()->reward_amount);
        $this->assertSame(1, NotificationEvent::where('event_type', 'cashback_credited')->count());
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread_count', 1);
    }

    public function test_e2e_issued_voucher_and_all_reminder_stages_are_once_and_stop_after_actual_redemption(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
        $user = User::factory()->create();
        $v = $this->voucher($user);
        $maintenance = app(NotificationMaintenance::class);
        $dispatcher = app(NotificationDispatcher::class);
        $this->assertSame(1, $maintenance->reminders());
        $this->assertSame(0, $maintenance->reminders());
        $dispatcher->drain();
        $this->assertSame(1, $user->notifications()->where('event_type', 'voucher_received')->count());
        $this->travel(23)->days();
        $this->assertSame(1, $maintenance->reminders());
        $dispatcher->drain();
        $this->travel(6)->days();
        $this->assertSame(1, $maintenance->reminders());
        $dispatcher->drain();
        $this->assertSame(3, $user->notifications()->where('event_type', 'voucher_expiring')->count());
        app(VoucherInventory::class)->redeemByMerchant($v->fresh(), User::factory()->create()->id, 'Verified redemption fixture');
        $this->assertSame(0, $maintenance->reminders());
        $dispatcher->drain();
        $this->assertSame(1, $user->notifications()->where('event_type', 'voucher_used')->count());
        Sanctum::actingAs($user);
        $this->assertStringNotContainsString('PRIVATE-NOTIFICATION-CODE', $this->getJson('/api/v1/notifications')->assertOk()->getContent());
    }

    public function test_e2e_delayed_reminders_are_suppressed_after_expiry_or_source_change(): void
    {
        $user = User::factory()->create();
        $v = $this->voucher($user);
        app(NotificationMaintenance::class)->reminders();
        $v->forceFill(['expires_at' => now()->addDays(90)])->save();
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(0, $user->notifications()->where('event_type', 'voucher_expiring')->count());
        $v->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertSame(0, app(NotificationMaintenance::class)->reminders());
    }

    public function test_e2e_favorite_promotion_requires_current_consent_and_preference_and_membership_changes_do_not_leak(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        DB::table('consumer_favorite_webshops')->insert(['user_id' => $user->id, 'webshop_id' => $shop->id, 'created_at' => now()]);
        $member = $shop->memberships()->sole()->setRelation('webshop', $shop)->setRelation('user', $owner);
        $offer = app(OfferService::class)->create($member, OfferAcquisition::MerchantReward, ['name' => 'Favorite offer', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 10000, 'redemption_validity_days' => 30]);
        app(OfferService::class)->transition($offer, OfferStatus::Active);
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(1, $owner->notifications()->where('event_type', 'offer_published')->count());
        config(['shopbonus.legal.marketing_consent_version' => 'notification-test-consent']);
        app(CommunicationPreferences::class)->update($user, ['offers_inspiration' => true]);
        app(NotificationPreferences::class)->update($user, ['preferences' => ['favorite_merchant_offer' => ['in_app' => true]]]);
        app(PublicProfileService::class)->save($member, ['display_name' => 'Favorite shop', 'short_description' => 'Synthetic shop', 'categories' => ['sports_outdoor'], 'published' => true]);
        $offer->forceFill(['slug' => 'notification-favorite-offer'])->save();
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'FAVORITE-PRIVATE', 'code_hash' => VoucherCode::hashFor($shop->id, 'FAVORITE-PRIVATE'), 'code_last4' => 'VATE']);
        app(NotificationSources::class)->capture('favorite_merchant_offer', 'eligible-favorite-fixture', [$user->id], ['offer_id' => $offer->id]);
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(1, $user->notifications()->where('event_type', 'favorite_merchant_offer')->count());
        config(['notifications.email_enabled' => true]);
        app(NotificationPreferences::class)->update($user, ['preferences' => ['favorite_merchant_offer' => ['in_app' => false, 'email' => true]]]);
        app(NotificationSources::class)->capture('favorite_merchant_offer', 'favorite-email-only', [$user->id], ['offer_id' => $offer->id]);
        app(NotificationDispatcher::class)->drain();
        $emailOnly = $user->notifications()->where('in_app_enabled', false)->sole();
        $this->assertSame('skipped', $emailOnly->data['delivery_channels']['database']);
        app(NotificationMail::class)->send($emailOnly->delivery()->firstOrFail()->id);
        $this->assertSame('sent', $emailOnly->delivery()->firstOrFail()->status);
        DB::table('consumer_favorite_webshops')->where('user_id', $user->id)->delete();
        $this->assertFalse(app(NotificationMail::class)->eligible($user, $emailOnly));

    }

    public function test_late_queued_reminder_rechecks_the_exact_stage_before_email(): void
    {
        $user = User::factory()->create();
        $this->voucher($user);
        app(NotificationPreferences::class)->update($user, ['preferences' => ['voucher_expiring' => ['email' => true]]]);
        app(NotificationMaintenance::class)->reminders();
        app(NotificationDispatcher::class)->drain();
        $n = $user->notifications()->where('event_type', 'voucher_expiring')->sole();
        $this->assertTrue(app(NotificationMail::class)->eligible($user, $n));
        $this->travel(1)->days();
        $this->assertFalse(app(NotificationMail::class)->eligible($user, $n));
    }

    public function test_published_custom_reminder_policy_controls_scheduler_and_tampering_fails_closed(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        foreach ([$a, $b] as $admin) {
            $admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        }
        $settings = app(PlatformSettings::class);
        $version = $settings->draft($a, 'notifications.policy', ['retention_days' => 30, 'email_enabled' => false, 'voucher_reminder_days' => [14, 3], 'offer_expiry_days' => 5], 'Reviewed configurable windows');
        $settings->publish($settings->submit($version, $a), $b, 'Independent policy review');
        $user = User::factory()->create();
        $v = $this->voucher($user);
        $this->assertSame(0, app(NotificationMaintenance::class)->reminders());
        $v->forceFill(['expires_at' => now()->addDays(14)])->save();
        $this->assertSame(1, app(NotificationMaintenance::class)->reminders());
        app(NotificationDispatcher::class)->drain();
        $this->assertSame(1, $user->notifications()->where('event_type', 'voucher_expiring')->count());
        $this->assertSame(5, app(NotificationPolicy::class)->offerExpiryDays());
        DB::table('platform_setting_versions')->where('id', $version->id)->update(['value' => json_encode(['retention_days' => 1, 'email_enabled' => true])]);
        $this->expectException(\LogicException::class);
        app(NotificationPolicy::class)->current();
    }
}
