<?php

namespace Tests\Feature\Identity;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BrowsesLikeSpa;
use Tests\TestCase;

/**
 * Trello #4 E2E 8: unverified, suspended or deleted accounts cannot bypass
 * API guards or keep using old sessions/tokens.
 */
class AccountStatusTest extends TestCase
{
    use BrowsesLikeSpa, RefreshDatabase;

    /** @return list<array{string}> */
    public static function inactiveStates(): array
    {
        return [['suspended'], ['deleted']];
    }

    #[DataProvider('inactiveStates')]
    public function test_inactive_account_cannot_log_in_and_gets_the_generic_error(string $state): void
    {
        User::factory()->{$state}()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);

        $inactive = $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $wrong = $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Wrong-password-2026']);

        $inactive->assertStatus(422);
        $this->assertSame($wrong->json('error.fields'), $inactive->json('error.fields'));
    }

    #[DataProvider('inactiveStates')]
    public function test_existing_session_is_ended_when_account_becomes_inactive(string $state): void
    {
        $user = User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026'])->assertOk();
        $this->spa('GET', '/api/v1/me')->assertOk();

        $user->forceFill(['status' => $state])->save();

        $this->spa('GET', '/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'account_inactive');
        // The session was invalidated, not merely refused once.
        $user->forceFill(['status' => 'active'])->save();
        $this->spa('GET', '/api/v1/me')->assertUnauthorized();

        $this->assertGreaterThanOrEqual(1, AuditEvent::where('action', 'auth.forbidden')->count());
    }

    public function test_suspended_session_cannot_change_password_via_fortify_routes(): void
    {
        $user = User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026'])->assertOk();
        $user->forceFill(['status' => 'suspended'])->save();

        $this->spa('PUT', '/user/password', [
            'current_password' => 'Sterk-wachtwoord-2026',
            'password' => 'Nieuw-wachtwoord-2027',
        ])->assertForbidden()->assertJsonPath('error.code', 'account_inactive');

        $this->assertTrue(Hash::check('Sterk-wachtwoord-2026', $user->fresh()->password));
    }

    public function test_api_token_of_suspended_account_is_refused_and_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('integration')->plainTextToken;
        $user->forceFill(['status' => 'suspended'])->save();

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_inactive');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unverified_user_may_use_me_verify_resend_and_logout_only(): void
    {
        User::factory()->unverified()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        Route::middleware(['api', 'auth:sanctum', 'account.active', 'verified'])
            ->get('api/v1/_test/private', fn () => ['ok' => true]);
        Notification::fake();

        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026'])
            ->assertOk()
            ->assertJsonPath('data.destination', 'verify_email');

        $this->spa('GET', '/api/v1/me')->assertOk();
        $this->spa('POST', '/api/v1/auth/email/resend-code')->assertStatus(202);
        $this->spa('GET', '/api/v1/_test/private')->assertForbidden()->assertJsonPath('error.code', 'email_not_verified');
        $this->spa('POST', '/logout')->assertNoContent();
    }
}
