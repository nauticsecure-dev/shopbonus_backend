<?php

namespace Tests\Feature\Admin;

use App\Enums\MembershipRole;
use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\MerchantNotification;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Admin\StepUpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * B16b: Super Admin user, membership and webshop administration
 * (Trello #21 "Users, memberships & access", E2E 3; "Webshop administration").
 */
class UserAdministrationTest extends TestCase
{
    use CreatesActiveWebshops, InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $consumer;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['email' => 'admin@shopbonus.test', 'first_name' => 'Beheer']);
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->owner = User::factory()->create(['email' => 'eigenaar@woon.nl', 'first_name' => 'Pieter', 'onboarding_intent' => 'merchant']);
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        $this->consumer = User::factory()->create(['email' => 'anna@example.nl', 'first_name' => 'Anna']);
    }

    private function asAdmin(bool $stepUp = false): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()] + ($stepUp ? [StepUpCodes::SESSION_KEY => time()] : []));
    }

    public function test_search_filters_and_detail_never_expose_secrets(): void
    {
        $employee = User::factory()->create(['email' => 'medewerker@woon.nl', 'first_name' => 'Kees']);
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => 'active', 'joined_at' => now()]);
        $this->consumer->forceFill(['two_factor_secret' => encrypt('SECRETSECRET'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
        $this->asAdmin();

        $emails = fn (string $query) => array_column($this->getJson('/api/v1/admin/users'.$query)->assertOk()->json('data'), 'email');
        $this->assertSame(['anna@example.nl'], $emails('?role=consumer'));
        $this->assertEqualsCanonicalizing(['eigenaar@woon.nl', 'medewerker@woon.nl'], $emails('?role=merchant'));
        $this->assertSame(['medewerker@woon.nl'], $emails('?role=employee'));
        $this->assertSame(['admin@shopbonus.test'], $emails('?role=super_admin'));
        $this->assertEqualsCanonicalizing(['eigenaar@woon.nl', 'medewerker@woon.nl'], $emails("?webshop_id={$this->shop->public_id}"));
        $this->assertSame(['anna@example.nl'], $emails('?search=ANNA'));
        $this->assertSame([], $emails('?status=suspended'));
        $this->assertSame(4, $this->getJson('/api/v1/admin/users?per_page=2')->json('meta.total'));

        $detail = $this->getJson("/api/v1/admin/users/{$this->consumer->public_id}")->assertOk()->assertJsonPath('data.two_factor_enabled', true);
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'SECRETSECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $detail->getContent());
        }
        $this->getJson('/api/v1/admin/users/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $this->assertSame(1, AuditEvent::where('action', 'admin.user_viewed')->count());
    }

    public function test_card21_e2e3_suspension_ends_access_at_once_and_last_admin_is_protected(): void
    {
        config(['session.driver' => 'database']);
        $token = $this->consumer->createToken('app')->plainTextToken;
        DB::table('sessions')->insert(['id' => 'sess-anna', 'user_id' => $this->consumer->id, 'payload' => '', 'last_activity' => time()]);
        $this->asAdmin();

        $this->postJson("/api/v1/admin/users/{$this->consumer->public_id}/suspend", [])->assertStatus(422);
        $this->postJson("/api/v1/admin/users/{$this->consumer->public_id}/suspend", ['reason' => 'Fraudulent claims'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(0, $this->consumer->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->consumer->id)->count());
        $this->postJson("/api/v1/admin/users/{$this->consumer->public_id}/suspend", ['reason' => 'again'])->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->asAdmin();
        $this->postJson("/api/v1/admin/users/{$this->consumer->public_id}/reactivate", ['reason' => 'Cleared by support'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/admin/users/{$this->admin->public_id}/suspend", ['reason' => 'Self test'])->assertStatus(409)->assertJsonPath('error.code', 'cannot_manage_self');

        // Revoking a Super Admin needs the emailed step-up code and never removes the last one.
        $this->postJson("/api/v1/admin/users/{$this->admin->public_id}/revoke-super-admin", ['reason' => 'Leaving the team'])->assertStatus(423)->assertJsonPath('error.code', 'step_up_code_required');
        $this->asAdmin(stepUp: true);
        $this->postJson("/api/v1/admin/users/{$this->admin->public_id}/revoke-super-admin", ['reason' => 'Leaving the team'])->assertStatus(409)->assertJsonPath('error.code', 'last_super_admin');
        $second = User::factory()->create();
        $second->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->postJson("/api/v1/admin/users/{$second->public_id}/revoke-super-admin", ['reason' => 'Leaving the team'])->assertOk()->assertJsonPath('data.roles', []);
        $this->assertFalse($second->refresh()->isSuperAdmin());
        $this->postJson("/api/v1/admin/users/{$this->consumer->public_id}/revoke-super-admin", ['reason' => 'Not an admin'])->assertStatus(409)->assertJsonPath('error.code', 'not_super_admin');

        // A suspended second admin does not count as "another admin".
        $third = User::factory()->create(['status' => 'suspended']);
        $third->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->postJson("/api/v1/admin/users/{$this->admin->public_id}/revoke-super-admin", ['reason' => 'Leaving the team'])->assertStatus(409);

        // The console command applies the same guard.
        $this->artisan('shopbonus:grant-super-admin', ['email' => 'admin@shopbonus.test', '--revoke' => true])->assertFailed();
        $this->assertTrue($this->admin->refresh()->isSuperAdmin());

        $this->assertSame(['admin.user_suspended', 'admin.user_reactivated', 'admin.super_admin_revoked'],
            AuditEvent::whereIn('action', ['admin.user_suspended', 'admin.user_reactivated', 'admin.super_admin_revoked'])->orderBy('id')->pluck('action')->all());
    }

    public function test_card21_e2e3_public_signup_never_creates_administrators(): void
    {
        $this->configureLegalVersions();
        $this->register(['email' => 'hacker@example.com', 'roles' => ['super_admin'], 'platform_role' => 'super_admin', 'is_admin' => true])->assertCreated()->assertJsonPath('data.roles', []);
        $this->assertFalse(User::where('email', 'hacker@example.com')->sole()->isSuperAdmin());
    }

    public function test_reset_security_turns_off_2fa_and_signs_out_everywhere(): void
    {
        $this->owner->forceFill(['two_factor_secret' => encrypt('X'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
        $this->owner->createToken('app');

        $this->asAdmin();
        $this->postJson("/api/v1/admin/users/{$this->owner->public_id}/reset-security", ['reason' => 'Lost phone, identity checked'])->assertStatus(423);
        $this->asAdmin(stepUp: true);
        $this->postJson("/api/v1/admin/users/{$this->owner->public_id}/reset-security", ['reason' => 'Lost phone, identity checked'])->assertOk()->assertJsonPath('data.two_factor_enabled', false);
        $this->assertNull($this->owner->refresh()->two_factor_secret);
        $this->assertSame(0, $this->owner->tokens()->count());
        $this->postJson("/api/v1/admin/users/{$this->admin->public_id}/reset-security", ['reason' => 'Self test'])->assertStatus(409);
    }

    public function test_membership_revocation_is_enforced_and_the_last_owner_is_protected(): void
    {
        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}")->assertOk();

        $this->asAdmin();
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/members/{$employee->public_id}/revoke", ['reason' => 'Left the company'])
            ->assertOk()->assertJsonPath('data.status', 'removed');
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/members/{$employee->public_id}/revoke", ['reason' => 'Left the company'])->assertStatus(409);
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/members/{$this->owner->public_id}/revoke", ['reason' => 'Ownership dispute'])
            ->assertStatus(409)->assertJsonPath('error.code', 'final_owner');
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/members/{$this->consumer->public_id}/revoke", ['reason' => 'Not a member'])->assertNotFound();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($employee->refresh());
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}")->assertNotFound();
        $this->assertSame('removed', WebshopMembership::where('user_id', $employee->id)->value('status')->value);
    }

    public function test_webshop_list_columns_checklist_and_information_request(): void
    {
        $this->asAdmin();
        $row = $this->getJson('/api/v1/admin/webshops')->assertOk()->json('data.0');
        $this->assertSame(['not_connected', 0], [$row['integration_status'], $row['active_offers']]);
        $this->assertNotNull($row['last_activity_at']);

        $checklist = $this->getJson("/api/v1/admin/webshops/{$this->shop->public_id}")->assertOk()->json('data.approval_checklist');
        $this->assertSame(['business_identity', 'ownership_verified', 'company_information_confirmed', 'agreement_accepted', 'owner_account_active'], array_keys($checklist));
        $this->assertTrue($checklist['ownership_verified']);

        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/request-information", ['message' => 'short'])->assertStatus(422);
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/request-information", ['message' => 'Upload een recent KvK-uittreksel.'])
            ->assertCreated()->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/notifications")->assertOk()
            ->assertJsonPath('data.0.type', 'information_requested')->assertJsonPath('data.0.body', 'Upload een recent KvK-uittreksel.');
        $this->assertSame(1, MerchantNotification::count());
    }

    public function test_login_records_last_login_for_activity_counts(): void
    {
        $this->assertNull($this->consumer->last_login_at);
        $this->postJson('/login', ['email' => 'anna@example.nl', 'password' => 'password'])->assertOk();
        $this->assertNotNull($this->consumer->refresh()->last_login_at);
    }

    public function test_every_new_admin_route_refuses_non_admins(): void
    {
        Sanctum::actingAs($this->owner);
        $this->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => time()]);
        foreach ([['GET', '/api/v1/admin/users'], ['GET', "/api/v1/admin/users/{$this->consumer->public_id}"], ['POST', "/api/v1/admin/users/{$this->consumer->public_id}/suspend"],
            ['POST', "/api/v1/admin/users/{$this->consumer->public_id}/reset-security"], ['POST', "/api/v1/admin/webshops/{$this->shop->public_id}/request-information"]] as [$m, $u]) {
            $this->json($m, $u, ['reason' => 'Trying it', 'message' => 'Trying it out now'])->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');
        }
    }
}
