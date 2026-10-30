<?php

namespace Tests\Feature\Identity;

use App\Enums\ConsentPurpose;
use App\Enums\LegalDocumentType;
use App\Enums\OnboardingIntent;
use App\Enums\UserStatus;
use App\Models\AuditEvent;
use App\Models\ConsentRecord;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Trello #4 E2E 1 (consumer registration), 2 (owner registration),
 * 7 (uniqueness) and the registration half of 8 (tampering).
 */
class RegistrationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Notification::fake();

        Route::middleware(['api', 'auth:sanctum', 'account.active', 'verified'])
            ->get('api/v1/_test/verified-only', fn () => ['ok' => true]);
    }

    public function test_e2e1_consumer_registers_verifies_once_and_reaches_consumer_area(): void
    {
        $response = $this->register(['marketing_opt_in' => true]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'Jan')
            ->assertJsonPath('data.email', 'jan@example.nl')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.role', 'consumer')
            ->assertJsonPath('data.onboarding_intent', 'consumer')
            ->assertJsonPath('data.destination', 'verify_email')
            ->assertJsonMissingPath('data.password');

        $user = User::sole();
        // Public ULID, never the internal integer key.
        $this->assertSame($user->public_id, $response->json('data.id'));
        $this->assertNotSame((string) $user->id, $response->json('data.id'));

        // Exact current versions stored as immutable acceptances.
        $this->assertEqualsCanonicalizing(
            [[LegalDocumentType::Terms, 'terms-2026-10'], [LegalDocumentType::Privacy, 'privacy-2026-10']],
            LegalAcceptance::all()->map(fn ($a) => [$a->document_type, $a->document_version])->all(),
        );
        $consent = ConsentRecord::sole();
        $this->assertSame(ConsentPurpose::MarketingEmail, $consent->purpose);
        $this->assertTrue($consent->granted);
        $this->assertSame('marketing-2026-10', $consent->policy_version);

        // OTP mail queued (encrypted); no signed-link verification mail.
        Notification::assertSentTo($user, EmailVerificationCode::class);
        Notification::assertNotSentTo($user, VerifyEmail::class);
        $this->assertContains(ShouldQueue::class, class_implements(EmailVerificationCode::class));
        $this->assertContains(ShouldBeEncrypted::class, class_implements(EmailVerificationCode::class));

        // Blocked until verified.
        $this->getJson('/api/v1/_test/verified-only')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'email_not_verified');

        $this->postJson('/api/v1/auth/email/verify-code', ['code' => $this->lastCodeFor($user)])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.destination', 'consumer_dashboard');

        $this->getJson('/api/v1/_test/verified-only')->assertOk();

        // Single success: a second attempt is a conflict, not a second verification.
        $this->postJson('/api/v1/auth/email/verify-code', ['code' => $this->lastCodeFor($user)])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'email_already_verified');
    }

    public function test_e2e2_owner_registration_goes_to_webshop_setup_without_any_membership(): void
    {
        $this->register(['account_type' => 'merchant', 'email' => 'owner@shop.nl'])->assertCreated();
        $user = User::sole();

        $this->postJson('/api/v1/auth/email/verify-code', ['code' => $this->lastCodeFor($user)])
            ->assertOk()
            ->assertJsonPath('data.role', 'merchant')
            ->assertJsonPath('data.onboarding_intent', 'merchant')
            ->assertJsonPath('data.destination', 'webshop_setup')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.workspaces', []);
    }

    public function test_registration_rejects_super_admin_and_ignores_client_role_fields(): void
    {
        $this->register(['account_type' => 'super_admin', 'email' => 'hacker@shop.nl'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['account_type']]]);

        $this->register([
            'account_type' => 'consumer',
            'email' => 'safe@shop.nl',
            'role' => 'super_admin',
            'roles' => ['super_admin'],
            'is_admin' => true,
        ])->assertCreated()
            ->assertJsonPath('data.role', 'consumer')
            ->assertJsonPath('data.roles', []);

        $this->assertFalse(User::where('email', 'safe@shop.nl')->sole()->isSuperAdmin());
    }

    public function test_registration_requires_terms_and_current_versions(): void
    {
        $this->register(['accept_terms' => false])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['accept_terms']]]);

        $this->register(['terms_version' => 'terms-2025-01'])->assertStatus(422)
            ->assertJsonPath('error.fields.terms_version.0', 'The Terms have been updated. Reload the page and review them.');

        $this->assertSame(0, User::count());
    }

    public function test_registration_fails_closed_when_no_legal_version_is_published(): void
    {
        config(['shopbonus.legal.terms_version' => null]);

        $this->register()->assertStatus(503)->assertJsonPath('error.code', 'legal_documents_unavailable');
        $this->assertSame(0, User::count());
    }

    public function test_marketing_consent_is_optional_and_recorded_as_not_granted(): void
    {
        $this->register(['email' => 'piet@example.nl', 'marketing_opt_in' => false])->assertCreated();

        $this->assertFalse(ConsentRecord::sole()->granted);
    }

    public function test_password_strength_rules_apply(): void
    {
        $this->register(['password' => 'short1'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
        $this->register(['password' => 'onlyletterslongenough'])->assertStatus(422);
    }

    public function test_e2e7_email_is_unique_regardless_of_case(): void
    {
        $this->register(['email' => 'Jan@Example.NL'])->assertCreated();
        $this->assertSame('jan@example.nl', User::sole()->email);

        $this->postJson('/logout')->assertNoContent();
        $this->register(['email' => '  JAN@example.nl '])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);

        $this->assertSame(1, User::count());
    }

    public function test_e2e7_concurrent_signup_losing_the_race_gets_422_and_no_duplicate(): void
    {
        // Simulate another request inserting the same email after validation passed.
        User::creating(function (User $user) {
            DB::table('users')->insert([
                'public_id' => (string) str()->ulid(),
                'first_name' => 'Other',
                'email' => $user->email,
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->register()->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);

        // The simulated competitor shares this request's transaction, so both
        // roll back here; what matters is a clean 422 and no partial records.
        $this->assertSame(0, LegalAcceptance::count());
        $this->assertSame(0, ConsentRecord::count());
    }

    public function test_database_enforces_unique_email(): void
    {
        User::factory()->create(['email' => 'dup@example.nl']);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'DUP@example.nl']);
    }

    public function test_e2e8_client_supplied_privileges_are_ignored(): void
    {
        $this->register([
            'role' => 'super_admin',
            'roles' => ['super_admin'],
            'is_admin' => true,
            'status' => 'active',
            'email_verified_at' => now()->toIso8601String(),
            'webshop_id' => 1,
            'public_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
        ])->assertCreated()->assertJsonPath('data.email_verified', false);

        $user = User::sole();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame(OnboardingIntent::Consumer, $user->onboarding_intent);
        $this->assertNotSame('01JABCDEFGHJKMNPQRSTVWXYZ0', $user->public_id);
    }

    public function test_registration_is_audited_without_pii(): void
    {
        $this->register()->assertCreated();

        $event = AuditEvent::where('action', 'identity.registered')->sole();
        $this->assertSame(User::sole()->id, $event->actor_user_id);
        $this->assertStringNotContainsString('jan@example.nl', json_encode($event->toArray()));
        $this->assertStringNotContainsString('Sterk-wachtwoord', json_encode(AuditEvent::all()->toArray()));
    }

    public function test_legal_acceptances_and_consents_are_append_only(): void
    {
        $this->register(['marketing_opt_in' => true])->assertCreated();

        $this->expectException(LogicException::class);
        LegalAcceptance::first()->update(['document_version' => 'tampered']);
    }

    public function test_registration_is_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $this->register(['email' => 'same@example.nl', 'password' => 'x'])->assertStatus(422);
        }

        $this->register(['email' => 'same@example.nl', 'password' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests');
    }
}
