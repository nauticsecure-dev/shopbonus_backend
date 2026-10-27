<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\AuditEvent;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Notifications\StepUpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BrowsesLikeSpa;
use Tests\TestCase;

/**
 * B16a: Super Admin TOTP gate, emailed step-up code and support sessions
 * (Trello #21 "Impersonation", E2E 4; #4 "MFA for Super Admin").
 */
class AdminSecurityTest extends TestCase
{
    use BrowsesLikeSpa;
    use RefreshDatabase;

    private const PASSWORD = 'Sterk-wachtwoord-2026';

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['email' => 'admin@shopbonus.test', 'password' => Hash::make(self::PASSWORD)]);
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->target = User::factory()->create(['email' => 'jan@example.nl', 'first_name' => 'Jan']);
    }

    private function signInAdmin(): void
    {
        $this->spa('POST', '/login', ['email' => 'admin@shopbonus.test', 'password' => self::PASSWORD])->assertOk();
        $this->spa('POST', '/user/confirm-password', ['password' => self::PASSWORD])->assertCreated();
    }

    private function sentCode(): string
    {
        $code = null;
        Notification::assertSentTo($this->admin, StepUpCode::class, function (StepUpCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    }

    private function stepUp(): void
    {
        $this->travel(61)->seconds(); // resend cooldown between codes
        $this->spa('POST', '/api/v1/admin/step-up/code')->assertStatus(202);
        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $this->sentCode()])->assertOk()->assertJsonPath('data.confirmed', true);
    }

    /** @return array{0: string, 1: string} session id and support token */
    private function startSupportSession(): array
    {
        $this->stepUp();
        $r = $this->spa('POST', '/api/v1/admin/support-sessions', ['user_id' => $this->target->public_id, 'reason' => 'Ticket 4411: cannot see voucher'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.target.email', 'jan@example.nl');

        return [$r->json('data.id'), $r->json('data.token')];
    }

    private function asSupport(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->flushHeaders();

        // No cookies and no SPA origin: the token alone authenticates, as the admin UI must send it.
        $response = $this->withToken($token)->json($method, $uri, $data);
        $this->flushHeaders();

        return $response;
    }

    public function test_admin_routes_require_confirmed_totp_and_login_asks_for_the_second_factor(): void
    {
        config(['shopbonus.admin.mfa_required' => true]);
        $this->signInAdmin();

        $this->spa('GET', '/api/v1/admin/disputes')->assertForbidden()->assertJsonPath('error.code', 'mfa_required');

        // Fortify enrolment: enable, read the secret, confirm with a real TOTP code.
        $this->spa('POST', '/user/two-factor-authentication')->assertOk();
        $this->spa('GET', '/api/v1/admin/disputes')->assertForbidden(); // enabled but not confirmed
        $secret = $this->spa('GET', '/user/two-factor-secret-key')->assertOk()->json('secretKey');
        $this->spa('GET', '/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg', 'url']);
        $this->spa('POST', '/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertStatus(422);
        $this->spa('POST', '/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $recovery = $this->spa('GET', '/user/two-factor-recovery-codes')->assertOk()->json();
        $this->assertCount(8, $recovery);

        $me = $this->spa('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_enabled', true);
        $this->assertStringNotContainsString($secret, $me->getContent());
        $this->spa('GET', '/api/v1/admin/disputes')->assertOk();

        // A new login stops at the challenge; nothing is signed in until it passes.
        $this->spa('POST', '/logout')->assertNoContent();
        $this->spa('POST', '/login', ['email' => 'admin@shopbonus.test', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('two_factor', true);
        $this->spa('GET', '/api/v1/me')->assertUnauthorized();
        $this->spa('POST', '/two-factor-challenge', ['code' => '000000'])->assertStatus(422);
        $this->spa('POST', '/two-factor-challenge', ['recovery_code' => $recovery[0]])->assertOk()->assertJsonPath('data.email', 'admin@shopbonus.test');
        $this->spa('GET', '/api/v1/admin/disputes')->assertOk();

        // The challenge is rate limited (5 per minute per pending login).
        $this->travel(61)->seconds();
        $this->spa('POST', '/logout')->assertNoContent();
        $this->spa('POST', '/login', ['email' => 'admin@shopbonus.test', 'password' => self::PASSWORD])->assertJsonPath('two_factor', true);
        for ($i = 0; $i < 5; $i++) {
            $this->spa('POST', '/two-factor-challenge', ['code' => '000000'])->assertStatus(422);
        }
        $this->spa('POST', '/two-factor-challenge', ['code' => '000000'])->assertStatus(429);

        // A used recovery code is replaced and cannot be used again.
        $this->assertNotContains($recovery[0], $this->admin->refresh()->recoveryCodes());
    }

    public function test_step_up_code_is_required_single_use_and_limited(): void
    {
        $this->spa('POST', '/login', ['email' => 'admin@shopbonus.test', 'password' => self::PASSWORD])->assertOk();
        // Password first.
        $this->spa('POST', '/api/v1/admin/step-up/code')->assertStatus(423)->assertJsonPath('error.code', 'password_confirmation_required');
        $this->spa('POST', '/user/confirm-password', ['password' => self::PASSWORD])->assertCreated();

        // Then the code.
        $start = ['user_id' => $this->target->public_id, 'reason' => 'Ticket 4411: cannot see voucher'];
        $this->spa('POST', '/api/v1/admin/support-sessions', $start)->assertStatus(423)->assertJsonPath('error.code', 'step_up_code_required');

        $this->spa('POST', '/api/v1/admin/step-up/code')->assertStatus(202);
        $this->spa('POST', '/api/v1/admin/step-up/code')->assertStatus(429)->assertJsonPath('error.code', 'step_up_resend_too_soon');
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'step_up_code_invalid');
        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $code])->assertOk();
        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'step_up_code_invalid'); // used

        // The confirmation authorises one start only.
        $this->spa('POST', '/api/v1/admin/support-sessions', $start)->assertCreated();
        $this->spa('POST', '/api/v1/admin/support-sessions', $start)->assertStatus(423)->assertJsonPath('error.code', 'step_up_code_required');

        // Expiry and the attempt limit.
        $this->travel(61)->seconds();
        $this->spa('POST', '/api/v1/admin/step-up/code')->assertStatus(202);
        $this->travel(11)->minutes();
        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $this->sentCode()])->assertStatus(422)->assertJsonPath('error.code', 'step_up_code_expired');

        $this->spa('POST', '/user/confirm-password', ['password' => self::PASSWORD])->assertCreated();
        Notification::fake();
        $this->spa('POST', '/api/v1/admin/step-up/code')->assertStatus(202);
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 4; $i++) {
            $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'step_up_code_invalid');
        }
        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $wrong])->assertJsonPath('error.code', 'step_up_code_attempts_exceeded');
        $this->spa('POST', '/api/v1/admin/step-up/verify', ['code' => $code])->assertJsonPath('error.code', 'step_up_code_attempts_exceeded');

        $this->assertSame(0, AuditEvent::where('action', 'like', 'admin.step_up%')->where('metadata', 'like', '%'.$code.'%')->count());
        $this->assertGreaterThanOrEqual(6, AuditEvent::where('action', 'admin.step_up_failed')->count());
    }

    public function test_card21_e2e4_support_session_acts_as_target_and_audits_the_real_admin(): void
    {
        $this->signInAdmin();
        [$id, $token] = $this->startSupportSession();

        $me = $this->asSupport($token, 'GET', '/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $this->target->public_id)
            ->assertJsonPath('data.support_session.id', $id)
            ->assertJsonPath('data.support_session.admin.email', 'admin@shopbonus.test')
            ->assertJsonPath('data.support_session.reason', 'Ticket 4411: cannot see voucher');
        $this->assertNotNull($me->json('data.support_session.expires_at'));

        // Allowed support action, attributed to the target with the admin as real actor.
        $this->asSupport($token, 'PATCH', '/api/v1/consumer/profile', ['first_name' => 'Johannes'])->assertOk();
        $event = AuditEvent::where('action', 'consumer.profile_updated')->sole();
        $this->assertSame($this->target->id, $event->actor_user_id);
        $this->assertSame($this->admin->id, $event->real_actor_user_id);

        // The admin's own browser session is untouched and still the admin.
        $this->spa('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.email', 'admin@shopbonus.test')->assertJsonPath('data.support_session', null);

        $history = $this->spa('GET', "/api/v1/admin/support-sessions/{$id}")->assertOk()->json('data.actions');
        $this->assertSame(['support_session.started', 'support_session.request', 'support_session.request'], array_column($history, 'action'));
        $this->assertSame(['api.v1.me', 'api.v1.consumer.profile.update'], array_values(array_filter(array_column($history, 'route_name'))));
        $this->assertTrue(AuditEvent::where('action', 'support_session.request')->get()->every(fn (AuditEvent $e) => $e->real_actor_user_id === $this->admin->id && $e->actor_user_id === $this->target->id));
    }

    public function test_sensitive_actions_are_blocked_during_a_support_session(): void
    {
        $this->signInAdmin();
        [$id, $token] = $this->startSupportSession();

        $blocked = [
            ['POST', '/api/v1/consumer/checkouts', ['offer' => 'x']],
            ['POST', '/api/v1/consumer/offers/sample/claim', []],
            ['GET', '/api/v1/consumer/vouchers/01ARZ3NDEKTSV4RRFFQ69G5FAV', []],
            ['POST', '/api/v1/consumer/email-change', ['email' => 'evil@example.com']],
            ['POST', '/api/v1/consumer/privacy/deletion-request', []],
            ['GET', '/api/v1/consumer/privacy/export', []],
            ['POST', '/api/v1/workspaces', ['name' => 'X']],
            ['POST', '/api/v1/workspaces/current/funding/top-ups', ['amount_minor' => 1000]],
            ['POST', '/api/v1/workspaces/current/invitations', ['email' => 'x@example.com', 'role' => 'manager']],
            ['POST', '/api/v1/auth/email/resend-code', []],
            ['GET', '/api/v1/admin/disputes', []],
            ['GET', '/api/v1/admin/monitoring', []],
        ];

        foreach ($blocked as [$method, $uri, $data]) {
            $response = $this->asSupport($token, $method, $uri, $data);
            $this->assertSame(403, $response->status(), "{$method} {$uri}");
            $this->assertSame('blocked_during_impersonation', $response->json('error.code'), "{$method} {$uri}");
        }

        // Session plus support token is refused rather than silently acting as the admin.
        $this->spa('GET', '/api/v1/me', [])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->spa('GET', '/api/v1/me')->assertStatus(400)->assertJsonPath('error.code', 'ambiguous_credentials');
        $this->flushHeaders();

        // Session-guard routes never accept the token at all.
        $this->asSupport($token, 'PUT', '/user/password', ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])->assertUnauthorized();
        $this->asSupport($token, 'POST', '/user/two-factor-authentication')->assertUnauthorized();

        // Reading stays possible, and every refusal is in the history.
        $this->asSupport($token, 'GET', '/api/v1/consumer/wallet')->assertOk();
        $this->assertSame(count($blocked), AuditEvent::where('action', 'support_session.request')->where('outcome', 'denied')->where('real_actor_user_id', $this->admin->id)->count());
    }

    public function test_ending_or_expiry_revokes_the_token_immediately(): void
    {
        $this->signInAdmin();
        [$id, $token] = $this->startSupportSession();
        $this->asSupport($token, 'GET', '/api/v1/me')->assertOk();

        $this->spa('POST', "/api/v1/admin/support-sessions/{$id}/end")->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.ended_reason', 'ended_by_admin');
        $this->assertSame(0, $this->target->tokens()->count()); // revoked at once, not on next use
        $this->asSupport($token, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->spa('POST', "/api/v1/admin/support-sessions/{$id}/end")->assertOk()->assertJsonPath('data.ended_reason', 'ended_by_admin'); // idempotent
        $this->assertSame(1, AuditEvent::where('action', 'support_session.ended')->count());

        // Self-end from the banner.
        [$id2, $token2] = $this->startSupportSession();
        $this->asSupport($token2, 'DELETE', '/api/v1/support-session')->assertOk()->assertJsonPath('data.ended_reason', 'ended_by_session');
        $this->asSupport($token2, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->spa('DELETE', '/api/v1/support-session')->assertNotFound()->assertJsonPath('error.code', 'not_in_support_session');

        // Expiry after 30 minutes; starting a new one replaces the previous.
        [$id3, $token3] = $this->startSupportSession();
        [$id4] = $this->startSupportSession();
        $this->assertSame('replaced', ImpersonationSession::where('public_id', $id3)->value('ended_reason'));
        $this->asSupport($token3, 'GET', '/api/v1/me')->assertUnauthorized();

        $this->travel(31)->minutes();
        $this->spa('POST', '/user/confirm-password', ['password' => self::PASSWORD])->assertCreated();
        $list = $this->spa('GET', '/api/v1/admin/support-sessions')->assertOk()->json('data');
        $this->assertSame(['expired', 'ended', 'ended', 'ended'], array_column($list, 'status'));
        $this->assertSame([$id4], array_column($this->spa('GET', '/api/v1/admin/support-sessions?status=expired')->json('data'), 'id'));
    }

    public function test_support_session_targets_are_restricted(): void
    {
        $this->signInAdmin();
        $other = User::factory()->create();
        $other->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $suspended = User::factory()->create(['status' => UserStatus::Suspended]);

        foreach ([[$this->admin, 'support_session_self'], [$other, 'support_session_target_admin'], [$suspended, 'support_session_target_inactive']] as [$user, $code]) {
            $this->stepUp();
            $this->spa('POST', '/api/v1/admin/support-sessions', ['user_id' => $user->public_id, 'reason' => 'Checking an account issue'])
                ->assertStatus(422)->assertJsonPath('error.code', $code);
        }

        $this->stepUp();
        $this->spa('POST', '/api/v1/admin/support-sessions', ['user_id' => $this->target->public_id, 'reason' => 'short'])->assertStatus(422);
        $this->spa('POST', '/api/v1/admin/support-sessions', ['user_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'reason' => 'Checking an account issue'])->assertNotFound();

        // Not an admin: no access to the admin API at all.
        $this->forgetSpaCookies();
        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'password'])->assertOk();
        $this->spa('GET', '/api/v1/admin/support-sessions')->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');
        $this->assertSame(0, ImpersonationSession::count());
    }
}
