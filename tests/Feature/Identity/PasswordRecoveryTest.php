<?php

namespace Tests\Feature\Identity;

use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BrowsesLikeSpa;
use Tests\TestCase;

/**
 * Trello #4 E2E 6: same response for known/unknown email; reset works once;
 * expired/reused token fails; old sessions and tokens stop working.
 */
class PasswordRecoveryTest extends TestCase
{
    use BrowsesLikeSpa, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->user = User::factory()->create(['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026']);
    }

    private function requestToken(): string
    {
        $this->postJson('/forgot-password', ['email' => 'jan@example.nl'])->assertOk();

        $token = null;
        Notification::assertSentTo($this->user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return (string) $token;
    }

    private function reset(string $token, string $password = 'Nieuw-wachtwoord-2027'): TestResponse
    {
        return $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'jan@example.nl',
            'password' => $password,
        ]);
    }

    public function test_known_and_unknown_email_get_identical_responses(): void
    {
        $known = $this->postJson('/forgot-password', ['email' => 'JAN@example.nl']);
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.nl']);

        $known->assertOk();
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());

        Notification::assertSentTo($this->user, ResetPasswordLink::class);
        Notification::assertCount(1);
        $this->assertSame(1, AuditEvent::where('action', 'identity.password_reset_requested')->count());
    }

    public function test_broker_throttled_repeat_request_gets_the_same_response(): void
    {
        $first = $this->postJson('/forgot-password', ['email' => 'jan@example.nl']);
        $second = $this->postJson('/forgot-password', ['email' => 'jan@example.nl']); // broker throttle (60s)

        $this->assertSame($first->json(), $second->json());
        $second->assertOk();
    }

    public function test_reset_mail_is_queued_encrypted_and_links_to_frontend(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(ResetPasswordLink::class));
        $this->assertContains(ShouldBeEncrypted::class, class_implements(ResetPasswordLink::class));

        $token = $this->requestToken();
        $mail = (new ResetPasswordLink($token))->toMail($this->user);

        $this->assertStringStartsWith('http://localhost:3000/reset-password?token=', $mail->actionUrl);
    }

    public function test_reset_works_once_and_new_password_logs_in(): void
    {
        $token = $this->requestToken();

        $this->reset($token)->assertOk();
        $this->reset($token, 'Ander-wachtwoord-2028')->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);

        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026'])->assertStatus(422);
        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Nieuw-wachtwoord-2027'])->assertOk();
        $this->assertSame(1, AuditEvent::where('action', 'identity.password_reset')->count());
    }

    public function test_expired_token_fails(): void
    {
        $token = $this->requestToken();
        $this->travel(61)->minutes();

        $this->reset($token)->assertStatus(422);
    }

    public function test_weak_new_password_is_rejected(): void
    {
        $this->reset($this->requestToken(), 'short')->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }

    public function test_reset_signs_out_existing_sessions_and_revokes_tokens(): void
    {
        $token = $this->user->createToken('mobile')->plainTextToken;
        // The reset is requested from a signed-out device...
        $resetToken = $this->requestToken();

        // ...while another device has a live session.
        $this->spa('POST', '/login', ['email' => 'jan@example.nl', 'password' => 'Sterk-wachtwoord-2026'])->assertOk();
        $this->spa('GET', '/api/v1/me')->assertOk();
        $device = $this->spaCookies;

        $this->forgetSpaCookies();
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
        $this->reset($resetToken)->assertOk();

        $this->spaCookies = $device;

        $this->spa('GET', '/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/forgot-password', ['email' => 'jan@example.nl'])->assertOk();
        }

        $this->postJson('/forgot-password', ['email' => 'jan@example.nl'])->assertStatus(429);
    }
}
