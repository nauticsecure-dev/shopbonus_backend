<?php

namespace Tests\Feature\Consumer;

use App\Enums\ConsentPurpose;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherOrigin;
use App\Models\AuditEvent;
use App\Models\ConsentRecord;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Notifications\EmailChangeCode;
use App\Notifications\EmailChangeRequested;
use App\Services\Consumer\CommunicationPreferences;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\BrowsesLikeSpa;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * B08b: consumer profile, email change, preferences, favourites, My
 * Vouchers, dashboard, privacy (Trello #10 E2E 1–6, 9, 10; #18 E2E 9).
 */
class ConsumerProfileTest extends TestCase
{
    use BrowsesLikeSpa, CreatesActiveWebshops, InteractsWithIdentity, RefreshDatabase;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Storage::fake('local');
        Notification::fake();
        $this->anna = User::factory()->create(['first_name' => 'Anna', 'email' => 'anna@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
    }

    private function listedShop(string $name, string $category): Webshop
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner, ['name' => $name]);
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/workspaces/{$shop->public_id}/public-profile", ['short_description' => $name, 'categories' => [$category], 'published' => true])->assertOk();

        return $shop;
    }

    private function issuedVoucher(Webshop $shop): void
    {
        $membership = $shop->memberships()->sole()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 20,
        ]), OfferStatus::Active);
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'ANNA-0001', 'code_hash' => VoucherCode::hashFor($shop->id, 'ANNA-0001'), 'code_last4' => '0001']);
        $inventory = app(VoucherInventory::class);
        $inventory->issue($inventory->reserve($offer, VoucherOrigin::Promotional, 'test:'.$shop->id), $this->anna);
    }

    public function test_card10_e2e1_profile_loads_from_existing_services(): void
    {
        $this->anna->forceFill(['phone' => '+31612345678'])->save();
        Sanctum::actingAs($this->anna);

        $this->getJson('/api/v1/consumer/profile')->assertOk()
            ->assertJsonPath('data.first_name', 'Anna')
            ->assertJsonPath('data.email', 'anna@example.nl')
            ->assertJsonPath('data.email_verification', 'verified')
            ->assertJsonPath('data.phone', '+31612345678')
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.security', ['email_verified' => true, 'two_factor' => 'disabled'])
            ->assertJsonPath('data.notifications', ['vouchers_discounts' => false, 'reminders' => true, 'offers_inspiration' => false]);
        $this->assertNotSame($this->anna->getRawOriginal('phone'), '+31612345678'); // stored encrypted

        $this->getJson('/api/v1/consumer/dashboard')->assertOk()->assertJsonPath('data.wallet.eur_credit.available', 0); // authoritative ledger, empty
    }

    public function test_card10_e2e2_personal_data_changes_persist_and_invalid_input_changes_nothing(): void
    {
        Sanctum::actingAs($this->anna);

        $this->patchJson('/api/v1/consumer/profile', ['first_name' => "Anne-Marie O'Neil", 'phone' => '06 1234 5678', 'locale' => 'en'])->assertOk()
            ->assertJsonPath('data.first_name', "Anne-Marie O'Neil")->assertJsonPath('data.phone', '+31612345678')->assertJsonPath('data.locale', 'en');
        $this->patchJson('/api/v1/consumer/profile', ['phone' => '+44 20 7946 0958'])->assertOk()->assertJsonPath('data.phone', '+442079460958');

        $this->patchJson('/api/v1/consumer/profile', ['first_name' => '<b>Anna</b>', 'phone' => '06 1234 5678'])->assertUnprocessable()->assertJsonValidationErrors('first_name', 'error.fields');
        $this->patchJson('/api/v1/consumer/profile', ['first_name' => 'Anna', 'phone' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('phone', 'error.fields');
        $this->patchJson('/api/v1/consumer/profile', ['first_name' => ''])->assertUnprocessable();

        $fresh = $this->anna->fresh();
        $this->assertSame("Anne-Marie O'Neil", $fresh->first_name);
        $this->assertSame('+442079460958', $fresh->phone);

        // Server-owned fields are ignored.
        $this->patchJson('/api/v1/consumer/profile', ['email' => 'x@example.nl', 'status' => 'suspended', 'email_verified_at' => null, 'id' => 1])->assertOk();
        $this->assertSame('anna@example.nl', $this->anna->fresh()->email);
        $this->assertTrue($this->anna->fresh()->isActive());
        $this->assertSame(1, $this->anna->fresh()->email_verified_at === null ? 0 : 1);

        $this->patchJson('/api/v1/consumer/profile', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);
    }

    public function test_card10_e2e3_profile_photo_upload_replace_remove_and_rejection(): void
    {
        Sanctum::actingAs($this->anna);

        $this->postJson('/api/v1/consumer/profile/avatar', ['avatar' => UploadedFile::fake()->create('me.svg', 5, 'image/svg+xml')])->assertUnprocessable();
        $this->postJson('/api/v1/consumer/profile/avatar', ['avatar' => UploadedFile::fake()->image('big.jpg', 500, 500)->size(3000)])->assertUnprocessable();
        $this->postJson('/api/v1/consumer/profile/avatar', ['avatar' => UploadedFile::fake()->create('me.png', 5, 'image/png')])->assertUnprocessable();

        $url = $this->postJson('/api/v1/consumer/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertOk()->json('data.avatar_url');
        $this->assertStringNotContainsString('avatars/', $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($url, $this->getJson('/api/v1/consumer/profile')->json('data.avatar_url'));

        $replaced = $this->postJson('/api/v1/consumer/profile/avatar', ['avatar' => UploadedFile::fake()->image('me2.png', 200, 200)])->assertOk()->json('data.avatar_url');
        $this->assertNotSame($url, $replaced);
        $this->assertCount(1, Storage::disk('local')->allFiles('avatars'));

        $this->deleteJson('/api/v1/consumer/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertCount(0, Storage::disk('local')->allFiles('avatars'));
        $this->getJson('/api/v1/consumer/profile/avatar')->assertNotFound();
    }

    public function test_card10_e2e10_failed_photo_storage_changes_nothing_and_is_retryable(): void
    {
        Sanctum::actingAs($this->anna);
        $broken = Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('putFileAs')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($broken);

        $this->postJson('/api/v1/consumer/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 300, 300)])
            ->assertStatus(503)->assertJsonPath('error.code', 'storage_unavailable')->assertJsonStructure(['error' => ['request_id']]);
        $this->assertNull($this->anna->fresh()->avatar_path);
    }

    public function test_card10_e2e4_email_change_needs_the_code_sent_to_the_new_address(): void
    {
        Sanctum::actingAs($this->anna);

        $this->postJson('/api/v1/consumer/email-change', ['email' => 'anna.nieuw@example.nl', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password', 'error.fields');
        User::factory()->create(['email' => 'taken@example.nl']);
        $this->postJson('/api/v1/consumer/email-change', ['email' => 'TAKEN@example.nl', 'current_password' => 'Sterk-wachtwoord-2026'])->assertUnprocessable();

        $this->postJson('/api/v1/consumer/email-change', ['email' => 'Anna.Nieuw@example.nl', 'current_password' => 'Sterk-wachtwoord-2026'])
            ->assertStatus(202)->assertJsonPath('data.pending_email', 'anna.nieuw@example.nl');

        $code = null;
        Notification::assertSentTo(new AnonymousNotifiable, EmailChangeCode::class, function (EmailChangeCode $n, array $channels, AnonymousNotifiable $to) use (&$code) {
            $code = $n->code;

            return $to->routes['mail'] === 'anna.nieuw@example.nl';
        });
        Notification::assertSentTo($this->anna, EmailChangeRequested::class);

        // Still the old, verified address until confirmed; the account keeps working.
        $this->getJson('/api/v1/consumer/profile')->assertJsonPath('data.email', 'anna@example.nl')
            ->assertJsonPath('data.email_verification', 'verified')->assertJsonPath('data.pending_email', 'anna.nieuw@example.nl');

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/consumer/email-change/confirm', ['code' => $wrong])->assertUnprocessable()->assertJsonPath('error.code', 'verification_code_invalid');
        $this->postJson('/api/v1/consumer/email-change/confirm', ['code' => $code])->assertOk()->assertJsonPath('data.email', 'anna.nieuw@example.nl');
        $this->assertSame('anna.nieuw@example.nl', $this->anna->fresh()->email);
        $this->assertNotNull($this->anna->fresh()->email_verified_at);

        // Reused code.
        $this->postJson('/api/v1/consumer/email-change/confirm', ['code' => $code])->assertStatus(409)->assertJsonPath('error.code', 'email_change_not_pending');
        Notification::assertSentTo(new AnonymousNotifiable, EmailChangeRequested::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'anna@example.nl' && $n->completed);
        $this->assertSame(1, AuditEvent::where('action', 'identity.email_changed')->count());
    }

    public function test_email_change_mails_render_for_on_demand_recipients(): void
    {
        // Notification::fake() never renders; the real send failed once (found during doc capture).
        $to = (new AnonymousNotifiable)->route('mail', 'anna@example.nl');
        $notice = (new EmailChangeRequested('a****@example.nl', completed: true, firstName: 'Anna'))->toMail($to);
        $this->assertStringContainsString('a****@example.nl', implode(' ', $notice->introLines));
        $code = (new EmailChangeCode('123456', 'Anna', 10))->toMail($to);
        $this->assertContains('**123456**', $code->introLines);
    }

    public function test_card10_e2e4_expired_code_is_rejected(): void
    {
        Sanctum::actingAs($this->anna);
        $this->postJson('/api/v1/consumer/email-change', ['email' => 'anna.nieuw@example.nl', 'current_password' => 'Sterk-wachtwoord-2026'])->assertStatus(202);
        $code = null;
        Notification::assertSentTo(new AnonymousNotifiable, EmailChangeCode::class, function (EmailChangeCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/consumer/email-change/confirm', ['code' => $code])->assertUnprocessable()->assertJsonPath('error.code', 'verification_code_expired');
        $this->assertSame('anna@example.nl', $this->anna->fresh()->email);

        // A new request supersedes; cancel clears it.
        $this->postJson('/api/v1/consumer/email-change', ['email' => 'anna.ander@example.nl', 'current_password' => 'Sterk-wachtwoord-2026'])->assertStatus(202);
        $this->deleteJson('/api/v1/consumer/email-change')->assertOk();
        $this->getJson('/api/v1/consumer/profile')->assertJsonPath('data.pending_email', null);
    }

    public function test_card10_e2e5_password_change_and_throttling(): void
    {
        $this->spa('POST', '/login', ['email' => 'anna@example.nl', 'password' => 'Sterk-wachtwoord-2026'])->assertOk();
        $this->spa('PUT', '/user/password', ['current_password' => 'Sterk-wachtwoord-2026', 'password' => 'Nieuw-wachtwoord-2027'])->assertOk();
        $this->assertTrue(Hash::check('Nieuw-wachtwoord-2027', $this->anna->fresh()->password));
        $this->assertFalse(Hash::check('Sterk-wachtwoord-2026', $this->anna->fresh()->password));

        for ($i = 0; $i < 4; $i++) {
            $this->spa('PUT', '/user/password', ['current_password' => 'wrong-'.$i, 'password' => 'Ander-wachtwoord-2028'])->assertStatus(422);
        }
        $this->spa('PUT', '/user/password', ['current_password' => 'wrong', 'password' => 'Ander-wachtwoord-2028'])
            ->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
        $this->assertTrue(Hash::check('Nieuw-wachtwoord-2027', $this->anna->fresh()->password));
    }

    public function test_card10_e2e6_notification_preferences_persist_and_drive_dispatch_rules(): void
    {
        Sanctum::actingAs($this->anna);
        $this->putJson('/api/v1/consumer/preferences', ['notifications' => ['vouchers_discounts' => true, 'offers_inspiration' => true]])->assertOk();
        $this->putJson('/api/v1/consumer/preferences', ['notifications' => ['vouchers_discounts' => false, 'offers_inspiration' => false, 'reminders' => true]])->assertOk()
            ->assertJsonPath('data.notifications', ['vouchers_discounts' => false, 'reminders' => true, 'offers_inspiration' => false]);

        // History: one record per actual change, with version and source; "reminders" was unchanged.
        $records = ConsentRecord::where('user_id', $this->anna->id)->where('source', 'profile')->orderBy('id')->get();
        $this->assertSame([['marketing_email', true], ['marketing_inspiration', true], ['marketing_email', false], ['marketing_inspiration', false]],
            $records->map(fn ($r) => [$r->purpose->value, $r->granted])->all());
        $this->assertSame('marketing-2026-10', $records->first()->policy_version);

        // Persists across sign-in (fresh request, no state in the session).
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->anna->fresh());
        $this->getJson('/api/v1/consumer/preferences')->assertJsonPath('data.notifications.reminders', true)->assertJsonPath('data.notifications.vouchers_discounts', false);

        $prefs = app(CommunicationPreferences::class);
        $this->assertFalse($prefs->allows($this->anna, ConsentPurpose::MarketingEmail));
        $this->assertFalse($prefs->allows($this->anna, ConsentPurpose::MarketingInspiration));
        $this->assertTrue($prefs->allows($this->anna, ConsentPurpose::VoucherReminders));

        $this->putJson('/api/v1/consumer/preferences', ['notifications' => ['sms' => true]])->assertUnprocessable();
        config(['shopbonus.legal.marketing_consent_version' => null]);
        $this->putJson('/api/v1/consumer/preferences', ['notifications' => ['vouchers_discounts' => true]])->assertStatus(503);
    }

    public function test_card10_e2e9_profile_changes_keep_favorites_categories_and_vouchers(): void
    {
        $fietsen = $this->listedShop('Fietsen Utrecht', 'sports_outdoor');
        $this->issuedVoucher($fietsen);
        Sanctum::actingAs($this->anna);

        $this->putJson('/api/v1/consumer/favorites/fietsen-utrecht')->assertCreated()->assertJsonPath('data.0.slug', 'fietsen-utrecht');
        $this->putJson('/api/v1/consumer/favorites/fietsen-utrecht')->assertOk(); // idempotent
        $this->putJson('/api/v1/consumer/favorites/bestaat-niet')->assertNotFound();
        $this->putJson('/api/v1/consumer/preferences', ['categories' => ['sports_outdoor', 'pets']])->assertOk();

        $this->patchJson('/api/v1/consumer/profile', ['first_name' => 'Annie', 'phone' => '0612345678'])->assertOk();
        $this->putJson('/api/v1/consumer/preferences', ['notifications' => ['vouchers_discounts' => true]])->assertOk();

        $this->assertSame(['fietsen-utrecht'], array_column($this->getJson('/api/v1/consumer/favorites')->json('data'), 'slug'));
        $this->assertSame(['pets', 'sports_outdoor'], $this->getJson('/api/v1/consumer/preferences')->json('data.categories'));
        $vouchers = $this->getJson('/api/v1/consumer/vouchers')->assertOk()->json('data');
        $this->assertCount(1, $vouchers);
        $this->assertSame(['issued', '••••0001', 'Fietsen Utrecht'], [$vouchers[0]['status'], $vouchers[0]['code_masked'], $vouchers[0]['webshop']['name']]);
        $this->assertSame('ANNA-0001', $this->getJson('/api/v1/consumer/vouchers/'.$vouchers[0]['id'])->json('data.code'));

        $dashboard = $this->getJson('/api/v1/consumer/dashboard')->assertOk();
        $this->assertSame(['issued' => 1, 'redeemed' => 0, 'expiring_within_30_days' => 1], $dashboard->json('data.vouchers'));
        $this->assertSame('fietsen-utrecht', $dashboard->json('data.favorites.0.slug'));

        $this->deleteJson('/api/v1/consumer/favorites/fietsen-utrecht')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_card10_e2e10_and_card18_e2e9_no_cross_user_access(): void
    {
        $shop = $this->listedShop('Fietsen Utrecht', 'sports_outdoor');
        $this->issuedVoucher($shop);
        $bob = User::factory()->create(['email' => 'bob@example.nl']);
        Sanctum::actingAs($bob);

        // Bob sees only his own (empty) data, whatever he sends.
        $this->getJson('/api/v1/consumer/profile?user_id='.$this->anna->public_id)->assertOk()->assertJsonPath('data.email', 'bob@example.nl');
        $this->assertSame([], $this->getJson('/api/v1/consumer/vouchers?consumer='.$this->anna->public_id)->json('data'));
        $this->patchJson('/api/v1/consumer/profile', ['id' => $this->anna->public_id, 'first_name' => 'Hacked'])->assertOk();
        $this->assertSame('Anna', $this->anna->fresh()->first_name);
        $this->assertStringNotContainsString('ANNA-0001', $this->getJson('/api/v1/consumer/privacy/export')->getContent());

        // Signed out: every consumer route is 401 and audited.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/consumer/profile')->assertUnauthorized();
        $this->assertGreaterThanOrEqual(1, AuditEvent::where('http_status', 401)->where('route', 'like', '%consumer/profile%')->count());

        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->getJson('/api/v1/consumer/profile')->assertForbidden()->assertJsonPath('error.code', 'email_not_verified');
    }

    public function test_privacy_export_and_deletion_request(): void
    {
        $this->issuedVoucher($this->listedShop('Fietsen Utrecht', 'sports_outdoor'));
        Sanctum::actingAs($this->anna);

        $export = $this->getJson('/api/v1/consumer/privacy/export')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="shopbonus-data-'.now()->format('Y-m-d').'.json"')
            ->assertJsonPath('data.account.email', 'anna@example.nl')->assertJsonPath('data.vouchers.0.code', 'ANNA-0001');
        $this->assertArrayNotHasKey('password', $export->json('data.account'));
        $this->assertSame(1, AuditEvent::where('action', 'consumer.data_exported')->count());

        $this->postJson('/api/v1/consumer/privacy/deletion-request', ['current_password' => 'wrong', 'confirm' => true])->assertUnprocessable();
        $this->postJson('/api/v1/consumer/privacy/deletion-request', ['current_password' => 'Sterk-wachtwoord-2026', 'confirm' => true])
            ->assertStatus(202)->assertJsonPath('data.status', 'open')->assertJsonPath('data.type', 'deletion');
        $this->postJson('/api/v1/consumer/privacy/deletion-request', ['current_password' => 'Sterk-wachtwoord-2026', 'confirm' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'deletion_already_requested');
        $this->assertCount(1, $this->getJson('/api/v1/consumer/privacy/requests')->json('data'));
        // Nothing is deleted by the request itself.
        $this->assertTrue($this->anna->fresh()->isActive());
    }
}
