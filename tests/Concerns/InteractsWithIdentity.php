<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Notifications\EmailVerificationCode;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

trait InteractsWithIdentity
{
    protected function configureLegalVersions(): void
    {
        config([
            'shopbonus.legal.terms_version' => 'terms-2026-10',
            'shopbonus.legal.privacy_version' => 'privacy-2026-10',
            'shopbonus.legal.marketing_consent_version' => 'marketing-2026-10',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'consumer',
            'first_name' => 'Jan',
            'email' => 'jan@example.nl',
            'password' => 'Sterk-wachtwoord-2026',
            'accept_terms' => true,
            'terms_version' => 'terms-2026-10',
            'privacy_version' => 'privacy-2026-10',
            'marketing_opt_in' => false,
            'locale' => 'nl',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/register', $this->registrationPayload($overrides));
    }

    /**
     * Last verification code mailed to the user (requires Notification::fake()).
     */
    protected function lastCodeFor(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, EmailVerificationCode::class, function (EmailVerificationCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    }
}
