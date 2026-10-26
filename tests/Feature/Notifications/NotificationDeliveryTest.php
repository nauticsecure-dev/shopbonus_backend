<?php

namespace Tests\Feature\Notifications;

use App\Models\CentralNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationMail;
use App\Services\Notifications\NotificationMaintenance;
use App\Services\Notifications\NotificationSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function notification(string $type = 'voucher_received'): array
    {
        $u = User::factory()->create();
        app(NotificationSources::class)->capture($type, 'fixture:'.$u->public_id, [$u->id]);
        app(NotificationDispatcher::class)->drain();
        $n = $u->notifications()->firstOrFail();

        return [$u, $n, $n->delivery()->firstOrFail()];
    }

    public function test_actual_multipart_mail_has_safe_content_stable_message_id_and_one_delivery(): void
    {
        config(['notifications.email_enabled' => true]);
        [$u, $n, $d] = $this->notification();
        $mail = app(NotificationMail::class);
        $views = $mail->render($n);
        $this->assertStringContainsString('ShopBonus', $views['html']);
        $this->assertStringContainsString('/app/benefits', $views['text']);
        $mail->send($d->id);
        $mail->send($d->id);
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame(1, $d->fresh()->attempts);
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $raw = $messages->first()->getOriginalMessage()->toString();
        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('notification-'.$d->public_id.'@shopbonus.invalid', $raw);
        $this->assertStringNotContainsString('openai', strtolower($raw));
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.delivery_channels.email', 'sent');
    }

    public function test_optional_preferences_and_revocation_are_rechecked_at_delivery(): void
    {
        config(['notifications.email_enabled' => true]);
        [$u, $n, $d] = $this->notification('support_reply');
        app(NotificationMail::class)->send($d->id);
        $this->assertSame('skipped', $d->fresh()->status);
        [$u2, $n2, $d2] = $this->notification();
        $u2->forceFill(['status' => 'suspended'])->save();
        app(NotificationMail::class)->send($d2->id);
        $this->assertSame('skipped', $d2->fresh()->status);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_disabled_transport_keeps_durable_pending_record_for_recovery(): void
    {
        [$u, $n, $d] = $this->notification();
        app(NotificationMail::class)->send($d->id);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame(0, $d->fresh()->attempts);
    }

    public function test_ambiguous_transport_is_never_automatically_resent(): void
    {
        config(['notifications.email_enabled' => true]);
        [$u, $n, $d] = $this->notification();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic accepted-then-disconnected transport'));
        app(NotificationMail::class)->send($d->id);
        app(NotificationMail::class)->send($d->id);
        $this->assertSame('uncertain', $d->fresh()->status);
        $this->assertSame(1, $d->fresh()->attempts);
        Sanctum::actingAs($u);
        $this->deleteJson('/api/v1/notifications/'.$n->id)->assertConflict();
    }

    public function test_pre_transport_render_errors_retry_boundedly_without_sending(): void
    {
        config(['notifications.email_enabled' => true]);
        [$u, $n, $d] = $this->notification();
        $data = $n->data;
        $data['action_route'] = '//evil.test';
        $n->forceFill(['data' => $data])->save();
        for ($i = 0; $i < 3; $i++) {
            app(NotificationMail::class)->send($d->id);
            $this->travel(6)->minutes();
        }
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(3, $d->fresh()->attempts);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_retention_keeps_uncertain_claims_and_owned_deletion_is_isolated(): void
    {
        [$u, $n, $d] = $this->notification();
        $n->forceFill(['created_at' => now()->subDays(100)])->save();
        $d->forceFill(['status' => 'sending', 'claimed_at' => now()->subMinutes(6)])->save();
        $report = app(NotificationMaintenance::class)->recoverAndPrune();
        $this->assertSame(1, $report['uncertain']);
        $this->assertSame(0, $report['pruned']);
        $this->assertNotNull($n->fresh());
        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson('/api/v1/notifications/'.$n->id)->assertNotFound();
        $d->forceFill(['status' => 'skipped'])->save();
        Sanctum::actingAs($u);
        $this->deleteJson('/api/v1/notifications/'.$n->id)->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(NotificationDelivery::find($d->id));
        $this->assertNull(CentralNotification::find($n->id));
    }

    public function test_delete_cannot_cancel_mandatory_pending_mail_or_an_active_send(): void
    {
        [$u, $n, $d] = $this->notification();
        Sanctum::actingAs($u);
        $this->deleteJson('/api/v1/notifications/'.$n->id)->assertConflict();
        config(['notifications.email_enabled' => true]);
        Event::listen(MessageSending::class, function () use ($n) {
            $this->deleteJson('/api/v1/notifications/'.$n->id)->assertConflict();
        });
        app(NotificationMail::class)->send($d->id);
        $this->assertSame('sent', $d->fresh()->status);
    }
}
