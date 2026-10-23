<?php

namespace Tests\Feature\Identity;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BrowsesLikeSpa;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Trello #4 E2E 5 (CSRF handshake, 419, session rotation, logout) plus login
 * behaviour: generic failures, throttling, last_login_at, audit.
 */
class SessionTest extends TestCase
{
    use BrowsesLikeSpa, InteractsWithIdentity, RefreshDatabase;

    private const SPA = ['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'];

    private function login(string $email, string $password): TestResponse
    {
        return $this->spa('POST', '/login', ['email' => $email, 'password' => $password]);
    }

    public function test_csrf_cookie_endpoint_issues_xsrf_token(): void
    {
        $this->get('/sanctum/csrf-cookie', self::SPA)->assertNoContent()->assertCookie('XSRF-TOKEN');
    }

    public function test_login_returns_me_with_destination_and_rotates_session(): void
    {
        $user = User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $this->startSession();
        $before = session()->getId();

        $this->login('JAN@example.nl', 'Sterk-wachtwoord-2026')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.destination', 'consumer_dashboard');

        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(1, AuditEvent::where('action', 'identity.login_succeeded')->count());
    }

    public function test_wrong_password_and_unknown_email_get_the_same_generic_error(): void
    {
        User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);

        $wrong = $this->login('jan@example.nl', 'Wrong-password-2026')->assertStatus(422);
        $unknown = $this->login('nobody@example.nl', 'Wrong-password-2026')->assertStatus(422);

        $this->assertSame($wrong->json('error.fields'), $unknown->json('error.fields'));
        $this->assertSame(2, AuditEvent::where('action', 'identity.login_failed')->count());
        $this->assertStringNotContainsString('jan@example.nl', json_encode(AuditEvent::all()->toArray()));
        $this->assertNotNull(AuditEvent::where('action', 'identity.login_failed')->first()->metadata['subject_ref']);
    }

    public function test_login_is_throttled_per_email_and_ip_and_lockout_is_audited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->login('jan@example.nl', 'Wrong-password-2026')->assertStatus(422);
        }

        $this->login('jan@example.nl', 'Wrong-password-2026')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $this->assertSame(1, AuditEvent::where('action', 'identity.login_locked_out')->count());
    }

    public function test_logout_invalidates_session_and_protected_get_returns_401(): void
    {
        User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $this->login('jan@example.nl', 'Sterk-wachtwoord-2026')->assertOk();
        $this->spa('GET', '/api/v1/me')->assertOk(); // the session cookie really authenticates

        $this->spa('POST', '/logout')->assertNoContent();

        $this->spa('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->assertSame(1, AuditEvent::where('action', 'identity.logout')->count());
    }

    public function test_state_changing_request_without_csrf_token_returns_419_envelope(): void
    {
        // Laravel skips CSRF checks in the `testing` environment; enforce them here.
        $this->app->detectEnvironment(fn () => 'local');

        $this->postJson('/login', ['email' => 'jan@example.nl', 'password' => 'x'], ['Sec-Fetch-Site' => 'cross-site'])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch')
            ->assertJsonStructure(['error' => ['request_id']]);
    }

    public function test_session_cookie_is_http_only_and_same_site(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));

        $response = $this->get('/sanctum/csrf-cookie', self::SPA);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_password_change_keeps_this_session_and_revokes_api_tokens(): void
    {
        $user = User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $user->createToken('mobile');
        $this->login('jan@example.nl', 'Sterk-wachtwoord-2026')->assertOk();
        $this->spa('GET', '/api/v1/me')->assertOk();

        // A second device signed in with the old password.
        $device = $this->spaCookies;
        $this->forgetSpaCookies();
        $this->login('jan@example.nl', 'Sterk-wachtwoord-2026')->assertOk();
        $this->spa('GET', '/api/v1/me')->assertOk();
        $other = $this->spaCookies;
        $this->spaCookies = $device;

        $this->spa('PUT', '/user/password', [
            'current_password' => 'Sterk-wachtwoord-2026',
            'password' => 'Nieuw-wachtwoord-2027',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->spa('GET', '/api/v1/me')->assertOk(); // this device stays signed in

        $this->spaCookies = $other;
        $this->spa('GET', '/api/v1/me')->assertUnauthorized(); // the other device is signed out
        $this->spaCookies = $device;
        $this->assertSame(1, AuditEvent::where('action', 'identity.password_changed')->count());

        $this->spa('PUT', '/user/password', [
            'current_password' => 'wrong',
            'password' => 'Nieuw-wachtwoord-2028',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['current_password']]]);
    }

    public function test_guest_only_routes_return_409_json_to_signed_in_clients(): void
    {
        User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
        $this->login('jan@example.nl', 'Sterk-wachtwoord-2026')->assertOk();

        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'already_authenticated');
        $this->spa('POST', '/register', [])->assertStatus(409);
    }

    public function test_bearer_tokens_also_authenticate_api_requests(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('integration')->plainTextToken;

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id);
    }

    public function test_me_exposes_no_internal_ids_or_secrets(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame(
            ['id', 'first_name', 'email', 'email_verified', 'status', 'onboarding_intent', 'locale', 'roles', 'workspaces', 'destination'],
            array_keys($data),
        );
        $this->assertSame($user->public_id, $data['id']);
    }
}
