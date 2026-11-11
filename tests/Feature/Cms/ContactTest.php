<?php

namespace Tests\Feature\Cms;

use App\Jobs\DeliverContactMail;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private function configureSupport(): void
    {
        $author = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$author, $reviewer] as $user) {
            $user->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        }
        $settings = app(PlatformSettings::class);
        $settings->publish($settings->submit($settings->draft($author, 'public.support_email', 'support@example.test', 'Synthetic contact acceptance'), $author), $reviewer, 'Independently reviewed test destination');
    }

    public function test_all_contact_validation_failures_are_returned_together_for_every_locale(): void
    {
        Queue::fake();
        foreach (['nl' => 'naam', 'en' => 'name', 'de' => 'Name'] as $locale => $name) {
            $response = $this->withHeader('Accept-Language', $locale)->postJson('/api/v1/public/contact', ['email' => 'invalid', 'message' => 'short', 'privacy' => false]);
            $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'subject', 'message', 'privacy'], 'error.fields')->assertHeader('X-Request-Id');
            $this->assertStringContainsString(mb_strtolower($name), mb_strtolower($response->json('error.fields.name.0')));
        }
        Queue::assertNothingPushed();
    }

    public function test_acceptance_queues_sanitized_encrypted_data_and_preserves_locale(): void
    {
        $this->configureSupport();
        Queue::fake();
        $this->withHeader('Accept-Language', 'de')->postJson('/api/v1/public/contact', [
            'name' => '<b>Anna</b>', 'email' => 'anna@example.test', 'subject' => 'Frage', 'message' => '<b>Eine sichere Testnachricht.</b>', 'privacy' => true,
        ])->assertStatus(202)->assertJsonPath('data.accepted', true)->assertHeader('X-Request-Id');
        Queue::assertPushed(DeliverContactMail::class, fn ($job) => $job->locale === 'de' && $job->support === 'support@example.test' && $job->submission['name'] === 'Anna' && $job->submission['message'] === 'Eine sichere Testnachricht.' && $job instanceof ShouldBeEncrypted);
    }

    public function test_honeypot_header_injection_and_missing_support_do_not_send_confirmation(): void
    {
        Queue::fake();
        $input = ['name' => 'Anna', 'email' => 'anna@example.test', 'subject' => 'Question', 'message' => 'A synthetic question.', 'privacy' => true];
        $this->postJson('/api/v1/public/contact', [...$input, 'website' => 'spam.example'])->assertUnprocessable();
        $this->postJson('/api/v1/public/contact', [...$input, 'subject' => "Question\r\nBcc: other@example.test"])->assertUnprocessable();
        $this->postJson('/api/v1/public/contact', $input)->assertStatus(503)->assertJsonPath('error.code', 'contact_unavailable');
        Queue::assertNothingPushed();
    }

    public function test_real_local_transport_renders_both_branded_multipart_messages_in_all_locales(): void
    {
        foreach (['nl', 'en', 'de'] as $locale) {
            Mail::purge();
            (new DeliverContactMail(['name' => 'Anna', 'email' => 'anna@example.test', 'subject' => 'Question', 'message' => '<script>private message</script>'], 'support@example.test', $locale, 'synthetic-request'))->handle();
            $messages = Mail::mailer()->getSymfonyTransport()->messages();
            $this->assertCount(2, $messages);
            foreach ($messages as $message) {
                $email = $message->getOriginalMessage();
                $this->assertStringContainsString('/brand/logo-full.png', $email->getHtmlBody());
                $this->assertStringContainsString('lang="'.$locale.'"', $email->getHtmlBody());
                $this->assertNotEmpty($email->getTextBody());
                $this->assertStringNotContainsString('<script>', $email->getHtmlBody());
            }
            $this->assertStringNotContainsString('private message', $messages->last()->getOriginalMessage()->getHtmlBody());
        }
    }
}
