<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Admin\StepUpCodes;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Notifications\NotificationSources;
use App\Services\Notifications\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTemplateTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/api/v1/platform/notifications';

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);

        return $u;
    }

    private function acting(User $u, bool $otp = true): void
    {
        Sanctum::actingAs($u);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => $otp ? time() : 0]);
    }

    private function value(string $title = 'Support update'): array
    {
        return ['title' => $title, 'body' => 'View the current details in your account.', 'action_label' => 'View details'];
    }

    private function fixture(): void
    {
        config(['notifications.ai_enabled' => true, 'notifications.api_key' => 'fixture-key', 'notifications.model' => 'fixture-model']);
        Http::fake(fn () => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['nl' => $this->value('Supportnieuws'), 'en' => $this->value()])]]]]]));
    }

    public function test_test_gate_second_admin_immutable_edit_rollback_and_locale_fallback(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $this->acting($a);
        $v = $this->postJson($this->base.'/templates', ['event_type' => 'support_reply', 'locale' => 'nl', 'value' => $this->value('Supportnieuws'), 'reason' => 'Reviewed initial template'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->acting($b);
        $this->postJson($this->base.'/templates/'.$v['id'].'/publish', ['note' => 'Independent review complete'])->assertConflict()->assertJsonPath('error.code', 'notification_template_test_required');
        $this->postJson($this->base.'/templates/'.$v['id'].'/test')->assertOk()->assertJsonPath('data.synthetic', true)->assertJsonPath('data.failed', 0);
        $this->acting($a);
        $this->postJson($this->base.'/templates/'.$v['id'].'/publish', ['note' => 'Self publish rejected'])->assertForbidden();
        $this->acting($b);
        $this->postJson($this->base.'/templates/'.$v['id'].'/publish', ['note' => 'Independent review complete'])->assertOk()->assertJsonPath('data.is_current', true);
        $resolved = app(NotificationTemplates::class)->resolve('support_reply', 'en');
        $this->assertSame('nl', $resolved['locale']);
        $this->assertTrue($resolved['locale_fallback']);
        $this->assertSame(1, (int) DB::table('notification_template_fallbacks')->value('occurrences'));
        $next = $this->putJson($this->base.'/templates/'.$v['id'], ['expected_checksum' => $v['checksum'], 'value' => $this->value('New support text'), 'reason' => 'Next reviewed template'])->assertCreated()->json('data');
        $this->putJson($this->base.'/templates/'.$v['id'], ['expected_checksum' => $v['checksum'], 'value' => $this->value(), 'reason' => 'Stale editing fails'])->assertConflict();
        $this->postJson($this->base.'/templates/'.$next['id'].'/test')->assertOk();
        $this->acting($a);
        $this->postJson($this->base.'/templates/'.$next['id'].'/publish', ['note' => 'Independent second version review'])->assertOk();
        $rollback = $this->postJson($this->base.'/templates/'.$v['id'].'/rollback', ['reason' => 'Restore reviewed historical text'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson($this->base.'/templates/'.$rollback.'/test')->assertOk();
        $this->acting($b);
        $this->postJson($this->base.'/templates/'.$rollback.'/publish', ['note' => 'Independent rollback review'])->assertOk();
        $this->assertSame('Supportnieuws', app(NotificationTemplates::class)->resolve('support_reply', 'nl')['title']);
        $this->getJson($this->base.'/templates/'.$v['id'])->assertOk()->assertJsonPath('data.value.title', 'Supportnieuws');
        $this->assertStringNotContainsString('notifications.template', $this->getJson('/api/v1/public/settings')->assertOk()->getContent());
    }

    public function test_unsafe_variables_literals_and_lengths_are_rejected(): void
    {
        $this->acting($this->admin());
        foreach (['<script>bad</script>', 'Pay €700 today', '{{voucher_code}}', 'Visit https://evil.test', 'Contact a@b.test', str_repeat('x', 181)] as $text) {
            $this->postJson($this->base.'/templates', ['event_type' => 'support_reply', 'locale' => 'en', 'value' => array_replace($this->value(), ['body' => $text]), 'reason' => 'Invalid copy rejected'])->assertUnprocessable();
        }
        $this->postJson($this->base.'/templates', ['event_type' => 'cashback_credited', 'locale' => 'en', 'value' => $this->value(), 'reason' => 'Missing verified amount'])->assertUnprocessable();
    }

    public function test_name_placeholders_are_allowed_and_substituted_as_plain_text(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $this->acting($a);
        $value = ['title' => 'Nieuws van {{webshop_name}}', 'body' => 'Hoi {{first_name}}, bekijk de details in je account.', 'action_label' => 'Bekijk details'];
        $v = $this->postJson($this->base.'/templates', ['event_type' => 'support_reply', 'locale' => 'nl', 'value' => $value, 'reason' => 'Personalised support copy'])->assertCreated()->json('data');
        $this->acting($b);
        $this->postJson($this->base.'/templates/'.$v['id'].'/test')->assertOk()->assertJsonPath('data.preview.title', 'Nieuws van Woon & Co');
        $this->postJson($this->base.'/templates/'.$v['id'].'/publish', ['note' => 'Independent review complete'])->assertOk();

        $resolved = app(NotificationTemplates::class)->resolve('support_reply', 'nl', null, ['webshop_name' => '<b>Shop{{x}}</b>', 'first_name' => null]);
        $this->assertSame(['bShopx/b', 'Hoi , bekijk de details in je account.'], [str_replace('Nieuws van ', '', $resolved['title']), $resolved['body']]);
    }

    public function test_ai_is_budgeted_idempotent_private_and_receives_only_event_schema(): void
    {
        $this->fixture();
        config(['notifications.daily_limit' => 1]);
        $this->acting($this->admin());
        $key = (string) Str::uuid();
        $response = $this->withHeader('Idempotency-Key', $key)->postJson($this->base.'/templates/generate', ['event_type' => 'support_reply'])->assertCreated()->assertJsonPath('data.0.status', 'draft');
        $this->withHeader('Idempotency-Key', $key)->postJson($this->base.'/templates/generate', ['event_type' => 'support_reply'])->assertCreated()->assertJsonPath('data.0.id', $response->json('data.0.id'));
        $this->withHeader('Idempotency-Key', $key)->postJson($this->base.'/templates/generate', ['event_type' => 'voucher_received'])->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base.'/templates/generate', ['event_type' => 'support_reply'])->assertStatus(429);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['store'] === false && array_keys(json_decode($r['input'], true)) === ['event_type', 'audience', 'allowed_variables']);
        $this->assertSame(0, DB::table('platform_settings')->whereNotNull('published_version_id')->count());
    }

    public function test_admin_guards_settings_and_delivery_review_are_audited(): void
    {
        $this->getJson($this->base.'/events')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->base.'/events')->assertForbidden();
        $a = $this->admin();
        $this->acting($a, false);
        $this->putJson($this->base.'/settings', ['value' => ['retention_days' => 30, 'email_enabled' => false], 'reason' => 'Reviewed delivery policy'])->assertStatus(423);
        $this->acting($a);
        $this->getJson($this->base.'/events')->assertOk()->assertJsonCount(23, 'data');
        $this->getJson($this->base.'/deliveries')->assertOk();
        $this->getJson($this->base.'/settings')->assertOk();
        $this->putJson($this->base.'/settings', ['value' => ['retention_days' => 30, 'email_enabled' => false], 'reason' => 'Reviewed delivery policy'])->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertSame(90, app(NotificationPolicy::class)->retentionDays());
        $u = User::factory()->create();
        app(NotificationSources::class)->capture('voucher_received', 'review-fixture', [$u->id]);
        app(NotificationDispatcher::class)->drain();
        $delivery = NotificationDelivery::firstOrFail();
        $delivery->forceFill(['status' => 'uncertain'])->save();
        $this->postJson($this->base.'/deliveries/'.$delivery->public_id.'/retry', ['note' => 'Reviewed transport evidence'])->assertConflict();
        $this->postJson($this->base.'/deliveries/'.$delivery->public_id.'/retry', ['note' => 'Reviewed transport evidence', 'duplicate_risk_acknowledged' => true])->assertOk()->assertJsonPath('data.status', 'retry');
        $delivery->refresh()->forceFill(['status' => 'uncertain'])->save();
        $this->postJson($this->base.'/deliveries/'.$delivery->public_id.'/resolve', ['note' => 'Confirmed from provider evidence', 'outcome' => 'confirmed_sent'])->assertOk()->assertJsonPath('data.status', 'confirmed_sent');
        $this->assertDatabaseHas('audit_events', ['action' => 'notifications.delivery_reviewed']);
    }

    public function test_provider_refusal_tools_malformed_and_invented_values_fail_without_publication_or_secret_echo(): void
    {
        config(['notifications.ai_enabled' => true, 'notifications.api_key' => 'fixture-key', 'notifications.model' => 'fixture-model', 'notifications.daily_limit' => 100]);
        $this->acting($this->admin());
        $payload = [];
        Http::fake(function () use (&$payload) {
            return Http::response($payload);
        });
        $message = fn ($text) => ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
        $cases = [
            ['status' => 'incomplete', 'output' => []],
            ['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'invented_action']]],
            ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'No']]]]],
            $message('{broken'),
            $message(json_encode(['nl' => $this->value('Pay €999'), 'en' => $this->value()])),
            $message(json_encode(['nl' => $this->value('fixture-key'), 'en' => $this->value()])),
        ];
        foreach ($cases as $case) {
            $payload = $case;
            $r = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base.'/templates/generate', ['event_type' => 'support_reply'])->assertStatus(503);
            $this->assertStringNotContainsString('fixture-key', $r->getContent());
        }
        Http::assertSentCount(count($cases));
        $this->assertSame(0, DB::table('platform_setting_versions')->count());
        $this->assertSame(count($cases), (int) DB::table('notification_ai_quotas')->value('requests'));
    }

    public function test_admin_revocation_while_provider_runs_discards_generated_drafts(): void
    {
        config(['notifications.ai_enabled' => true, 'notifications.api_key' => 'fixture-key', 'notifications.model' => 'fixture-model']);
        $actor = $this->admin();
        $this->acting($actor);
        Http::fake(function () use ($actor) {
            $actor->platformRoles()->update(['revoked_at' => now()]);

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['nl' => $this->value(), 'en' => $this->value()])]]]]]);
        });
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base.'/templates/generate', ['event_type' => 'support_reply'])->assertStatus(503);
        $this->assertSame(0, DB::table('platform_setting_versions')->count());
    }
}
