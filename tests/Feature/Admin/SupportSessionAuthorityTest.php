<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Admin\SupportSessions;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupportSessionAuthorityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('authorityChanges')]
    public function test_every_request_revalidates_current_initiator_authority(string $change): void
    {
        config(['shopbonus.admin.mfa_required' => $change === 'mfa_removed']);
        $admin = User::factory()->create(['two_factor_secret' => encrypt('SYNTHETIC'), 'two_factor_confirmed_at' => now()]);
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $target = User::factory()->create();
        [$session, $plain] = app(SupportSessions::class)->start($admin, $target, 'Synthetic request authority regression');
        $session->load('admin.platformRoles'); // Deliberately stale relationships must not authorize.
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        match ($change) {
            'suspended', 'deleted' => User::whereKey($admin->id)->update(['status' => $change]),
            'role_revoked' => $admin->platformRoles()->update(['revoked_at' => now()]),
            'unverified' => User::whereKey($admin->id)->update(['email_verified_at' => null]),
            'mfa_removed' => User::whereKey($admin->id)->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]),
        };
        $this->assertFalse($session->isActive());
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'support_session_ended');
        $this->assertSame('admin_authority_lost', $session->fresh()->ended_reason);
        $this->assertSame(0, $target->tokens()->count());
        $denial = AuditEvent::where('action', 'support_session.request')->where('http_status', 401)->sole();
        $this->assertSame($admin->id, $denial->real_actor_user_id);
        $this->assertSame($target->id, $denial->actor_user_id);
        $this->assertSame('denied', $denial->outcome->value);
        // Restoring authority must never resurrect the revoked support token.
        User::whereKey($admin->id)->update(['status' => 'active', 'email_verified_at' => now(), 'two_factor_secret' => encrypt('SYNTHETIC'), 'two_factor_confirmed_at' => now()]);
        $admin->platformRoles()->update(['revoked_at' => null]);
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public static function authorityChanges(): array
    {
        return array_map(fn ($v) => [$v], ['suspended', 'deleted', 'role_revoked', 'unverified', 'mfa_removed']);
    }

    public function test_service_start_rejects_a_stale_or_unprivileged_administrator(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        User::whereKey($admin->id)->update(['status' => 'suspended']);
        try {
            app(SupportSessions::class)->start($admin, $target, 'Synthetic direct service call');
            $this->fail('Stale administrator must not issue a token.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->assertSame(0, $target->tokens()->count());
        // An ordinary caller must be refused before learning a privileged target's state.
        $ordinary = User::factory()->create();
        try {
            app(SupportSessions::class)->start($ordinary, $admin, 'Synthetic unauthorized target probe');
            $this->fail('Ordinary callers must not inspect or impersonate a target.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function test_a_mismatched_support_token_is_revoked_but_ordinary_tokens_are_unaffected(): void
    {
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $target = User::factory()->create();
        [$session, $plain] = app(SupportSessions::class)->start($admin, $target, 'Synthetic token binding regression');
        $other = User::factory()->create();
        $target->tokens()->whereKey($session->token_id)->update(['tokenable_id' => $other->id]);
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame('invalid_token_binding', $session->fresh()->ended_reason);
        $this->app['auth']->forgetGuards();
        $this->withToken($target->createToken('ordinary')->plainTextToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_authority_lost_after_token_resolution_still_closes_and_audits_session(): void
    {
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $target = User::factory()->create();
        [$session, $plain] = app(SupportSessions::class)->start($admin, $target, 'Synthetic revocation race regression');
        $this->app->instance(SupportSessions::class, new class($admin->id, app(AuditLogger::class)) extends SupportSessions
        {
            public function __construct(private readonly int $adminId, AuditLogger $audit)
            {
                parent::__construct($audit);
            }

            public function forToken(PersonalAccessToken $token): ?ImpersonationSession
            {
                $session = parent::forToken($token);
                User::findOrFail($this->adminId)->platformRoles()->update(['revoked_at' => now()]);

                return $session;
            }
        });
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame('admin_authority_lost', $session->fresh()->ended_reason);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(1, AuditEvent::where('action', 'support_session.ended')->count());
    }
}
