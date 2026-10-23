<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_mail_is_rendered_in_the_users_locale(): void
    {
        $nl = User::factory()->create(['locale' => 'nl', 'first_name' => 'Jan']);
        $en = User::factory()->create(['locale' => 'en', 'first_name' => 'Ann']);

        $this->assertSame('nl', $nl->preferredLocale());
        $this->assertSame('en', $en->preferredLocale());

        app()->setLocale($nl->preferredLocale());
        $mail = (new EmailVerificationCode('123456', 10))->toMail($nl);
        $this->assertSame('Je Shopbonus-verificatiecode', $mail->subject);
        $this->assertSame('Hallo Jan,', $mail->greeting);
        $this->assertContains('**123456**', $mail->introLines);

        app()->setLocale($en->preferredLocale());
        $this->assertSame('Your Shopbonus verification code', (new EmailVerificationCode('123456', 10))->toMail($en)->subject);
        $this->assertSame('Reset your Shopbonus password', (new ResetPasswordLink('t'))->toMail($en)->subject);
    }

    public function test_unsupported_locale_falls_back_to_dutch(): void
    {
        $this->assertSame('nl', User::factory()->make(['locale' => 'de'])->preferredLocale());
    }

    public function test_every_identity_string_exists_in_both_languages(): void
    {
        $en = require lang_path('en/identity.php');
        $nl = require lang_path('nl/identity.php');

        $this->assertSame(array_keys($en), array_keys($nl));
    }
}
