<?php

namespace Tests\Feature\Identity;

use App\Models\AuditEvent;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Services\Identity\EmailVerificationCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Trello #4 E2E 4: invalid, expired, replayed, superseded and repeated
 * attempts fail safely; one success maximum; 429 on limits; no OTP in logs.
 */
class EmailVerificationCodeTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->user = User::factory()->unverified()->create();
        Sanctum::actingAs($this->user);
    }

    private function issue(bool $cooldown = false): string
    {
        $this->app->make(EmailVerificationCodes::class)->issue($this->user, enforceCooldown: $cooldown);

        return $this->lastCodeFor($this->user);
    }

    private function verify(string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/email/verify-code', ['code' => $code]);
    }

    private function wrongCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function test_code_is_six_digits_and_only_its_hmac_is_stored(): void
    {
        $code = $this->issue();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $challenge = EmailVerificationChallenge::sole();
        $this->assertSame(64, strlen($challenge->code_hash));
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertStringNotContainsString($code, json_encode($challenge->getAttributes()));
        $this->assertTrue($challenge->expires_at->between(now()->addMinutes(9), now()->addMinutes(10)));
    }

    public function test_invalid_code_fails_and_counts_an_attempt(): void
    {
        $code = $this->issue();

        $this->verify($this->wrongCode($code))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'verification_code_invalid');

        $this->assertSame(1, EmailVerificationChallenge::sole()->attempt_count);
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_malformed_code_is_a_validation_error(): void
    {
        $this->issue();

        $this->verify('12ab56')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, EmailVerificationChallenge::sole()->attempt_count);
    }

    public function test_expired_code_fails(): void
    {
        $code = $this->issue();
        $this->travel(11)->minutes();

        $this->verify($code)->assertStatus(422)->assertJsonPath('error.code', 'verification_code_expired');
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_superseded_code_fails_after_resend(): void
    {
        $old = $this->issue();
        $this->travel(61)->seconds();
        $new = $this->issue(cooldown: true);
        $this->assertNotSame($old, $new); // 1-in-a-million collision would fail loudly, not silently pass

        $this->verify($old)->assertStatus(422)->assertJsonPath('error.code', 'verification_code_invalid');

        $this->verify($new)->assertOk();
        $this->assertSame(1, EmailVerificationChallenge::whereNotNull('superseded_at')->count());
    }

    public function test_attempt_limit_burns_the_code_even_if_correct_afterwards(): void
    {
        $code = $this->issue();

        foreach (range(1, 4) as $i) {
            $this->verify($this->wrongCode($code))->assertJsonPath('error.code', 'verification_code_invalid');
        }

        $this->verify($this->wrongCode($code))->assertJsonPath('error.code', 'verification_code_attempts_exceeded');
        $this->verify($code)->assertStatus(422)->assertJsonPath('error.code', 'verification_code_attempts_exceeded');

        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_replayed_code_cannot_succeed_twice(): void
    {
        $code = $this->issue();

        $this->verify($code)->assertOk();
        $this->verify($code)->assertStatus(409)->assertJsonPath('error.code', 'email_already_verified');

        $this->assertSame(1, EmailVerificationChallenge::whereNotNull('consumed_at')->count());
        $this->assertSame(1, AuditEvent::where('action', 'identity.email_verified')->count());
    }

    public function test_a_consumed_challenge_cannot_be_consumed_again_by_a_racing_request(): void
    {
        $code = $this->issue();
        $challenge = EmailVerificationChallenge::sole();

        // A competing request consumed the challenge between our read and update.
        EmailVerificationChallenge::whereKey($challenge->id)->update(['consumed_at' => now()]);

        // With the challenge consumed, it is no longer open: no second success.
        $this->verify($code)->assertStatus(422)->assertJsonPath('error.code', 'verification_code_invalid');
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_code_sent_to_a_previous_email_does_not_verify_a_changed_email(): void
    {
        $code = $this->issue();
        $this->user->forceFill(['email' => 'changed@example.nl'])->save();

        $this->verify($code)->assertStatus(422)->assertJsonPath('error.code', 'verification_code_invalid');
    }

    public function test_resend_is_accepted_then_cooldown_returns_429_with_retry_after(): void
    {
        $this->postJson('/api/v1/auth/email/resend-code')
            ->assertStatus(202)
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.resend_available_in_seconds', 60);

        $this->postJson('/api/v1/auth/email/resend-code')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'verification_resend_too_soon')
            ->assertHeader('Retry-After');

        Notification::assertSentToTimes($this->user, EmailVerificationCode::class, 1);
    }

    public function test_resend_hourly_limit_returns_429(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/email/resend-code')->assertStatus(202);
            $this->travel(61)->seconds();
        }

        $this->postJson('/api/v1/auth/email/resend-code')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_verify_endpoint_is_rate_limited(): void
    {
        $this->issue();

        foreach (range(1, 10) as $i) {
            $this->verify('000000');
        }

        $this->verify('000000')->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_verified_user_cannot_request_a_new_code(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->postJson('/api/v1/auth/email/resend-code')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'email_already_verified');
    }

    public function test_code_never_appears_in_logs_or_audit_trail(): void
    {
        $logPath = storage_path('logs/otp-test-'.uniqid().'.log');
        config(['logging.channels.single.path' => $logPath, 'logging.default' => 'single']);
        Log::forgetChannel('single');

        $code = $this->issue();
        $this->verify($this->wrongCode($code));
        $this->verify($code)->assertOk();

        $logs = file_exists($logPath) ? (string) file_get_contents($logPath) : '';
        @unlink($logPath);

        $this->assertStringNotContainsString($code, $logs);
        $this->assertStringNotContainsString($code, json_encode(AuditEvent::all()->toArray()));
        $this->assertSame(
            ['identity.verification_code_sent', 'identity.verification_code_failed', 'identity.email_verified'],
            AuditEvent::orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/email/verify-code', ['code' => '123456'])->assertUnauthorized();
    }
}
