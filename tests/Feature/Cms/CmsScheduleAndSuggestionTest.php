<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPublicationSchedule;
use App\Models\CmsRevision;
use App\Models\User;
use App\Services\Cms\CmsSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * B29: scheduled publication and "Improve with AI" suggestions for CMS pages
 * (Trello #33 homepage, #34 voor-webshops, #35 over-ons). The AI provider is
 * faked here; live model behaviour is a staging check.
 */
class CmsScheduleAndSuggestionTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Storage::fake('local');
        $this->admin = $this->superAdmin();
        config(['cms.ai_enabled' => true, 'cms.api_key' => 'sk-test-cms', 'cms.model' => 'gpt-test', 'cms.daily_limit' => 5]);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);

        return $u;
    }

    private function asAdmin(?User $user = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user ?? $this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function content(string $title, string $text, array $extra = []): array
    {
        return ['title' => $title, 'seo_title' => $title, 'seo_description' => 'Spaar 10% bij webshops.', 'body' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Ons verhaal']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text], ['type' => 'text', 'text' => ' Lees meer', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/hulp']]]]]],
            ['type' => 'button', 'attrs' => ['label' => 'Voor webshops', 'href' => '/voor-webshops']], ...$extra]]];
    }

    private function page(string $key = 'over-ons', string $type = 'landing', bool $withEnglish = false): string
    {
        $this->asAdmin();
        $locales = ['nl' => ['slug' => $key, 'content' => $this->content('Over ShopBonus', 'Wij verbinden consumenten en webshops.')]];
        if ($withEnglish) {
            $locales['en'] = ['slug' => $key.'-en', 'content' => $this->content('About ShopBonus', 'We connect shoppers and webshops.')];
        }

        return $this->postJson('/api/v1/admin/cms/pages', ['key' => $key, 'type' => $type, 'locales' => $locales])->assertCreated()->json('data.id');
    }

    /** @param list<string> $segments */
    private function fakeProvider(array $segments): void
    {
        Http::swap(new Factory);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['segments' => $segments])]]],
        ]])]);
    }

    // ------------------------------------------------------------ scheduling

    public function test_scheduled_publication_publishes_exactly_the_reviewed_draft_at_its_time(): void
    {
        $id = $this->page();
        $due = now()->addHours(2)->startOfMinute();
        $schedule = $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => $due->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.draft_revision', 1)->json('data.id');

        // A second pending publication for the same locale is refused.
        $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => $due->addHour()->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('error.code', 'schedule_exists');

        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertNotFound(); // not due yet

        $this->travelTo($due->addMinute());
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertOk()->assertJsonPath('data.content.title', 'Over ShopBonus');

        $row = CmsPublicationSchedule::where('public_id', $schedule)->sole();
        $this->assertSame('done', $row->status);
        $this->assertNotNull($row->revision_id);
        $this->assertTrue(AuditEvent::where('action', 'cms.schedule_executed')->where('actor_user_id', $this->admin->id)->exists());

        // Re-running never publishes twice.
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->assertSame(1, CmsRevision::count());
    }

    public function test_a_draft_edited_after_scheduling_is_not_published_and_the_schedule_goes_stale(): void
    {
        $id = $this->page();
        $due = now()->addHour();
        $schedule = $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => $due->toIso8601String()])->json('data.id');
        // Scheduling an out-of-date revision is refused up front.
        $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'archive', 'due_at' => $due->toIso8601String(), 'locale' => 'nl'])->assertUnprocessable();
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Ongecontroleerd', 'Nieuwe tekst.')])->assertOk();

        $this->travelTo($due->addMinute());
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->assertSame(['stale', 'draft_changed'], [CmsPublicationSchedule::where('public_id', $schedule)->value('status'), CmsPublicationSchedule::where('public_id', $schedule)->value('outcome_code')]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertNotFound();
    }

    public function test_schedules_can_be_cancelled_and_validate_time_revision_legal_and_scheduler_rights(): void
    {
        $id = $this->page();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 9, 'due_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('error.code', 'draft_revision_conflict');

        $schedule = $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => now()->addDay()->toIso8601String()])->json('data.id');
        $this->postJson("/api/v1/admin/cms/schedules/{$schedule}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/admin/cms/schedules/{$schedule}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'schedule_not_pending');
        $this->travel(2)->days();
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->assertSame(0, CmsRevision::count());

        // Legal pages need the version, date and explicit approval when scheduling; Terms can never be archived.
        $terms = $this->page('voorwaarden', 'terms');
        $this->postJson("/api/v1/admin/cms/pages/{$terms}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('error.code', 'legal_approval_required');
        $this->postJson("/api/v1/admin/cms/pages/{$terms}/schedules", ['action' => 'archive', 'due_at' => now()->addDay()->toIso8601String()])->assertStatus(409)->assertJsonPath('error.code', 'legal_archive_not_allowed');
        $this->postJson("/api/v1/admin/cms/pages/{$terms}/schedules", ['action' => 'publish', 'locale' => 'nl', 'revision' => 1, 'due_at' => now()->addHour()->toIso8601String(),
            'legal_version' => '2027.1', 'effective_date' => '2027-01-01', 'approve' => true])->assertCreated()->assertJsonPath('data.legal_version', '2027.1');

        // If the scheduling admin loses Super Admin before it is due, nothing is published.
        $this->admin->platformRoles()->delete();
        $this->travel(2)->hours();
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->assertSame('scheduler_not_authorized', CmsPublicationSchedule::where('action', 'publish')->where('status', 'failed')->value('outcome_code'));
        $this->assertSame(0, CmsRevision::count());
    }

    public function test_scheduled_archive_hides_the_page(): void
    {
        $id = $this->page();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertOk();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'archive', 'due_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->getJson('/api/v1/admin/cms/schedules?status=done')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'archive');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertNotFound();
    }

    // ------------------------------------------------------------ AI suggestions

    public function test_improve_suggestion_keeps_structure_needs_human_approval_and_never_publishes(): void
    {
        $id = $this->page();
        $this->fakeProvider(['Over ShopBonus: samen voordeliger', 'Samen voordeliger shoppen', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Wij brengen consumenten en webshops samen.', ' Lees verder', 'Voor webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'improve-1'])
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.requires_human_approval', true)
            ->assertJsonPath('data.proposed.title', 'Over ShopBonus: samen voordeliger')
            ->assertJsonPath('data.proposed.body.content.1.content.1.marks.0.attrs.href', '/hulp')   // link kept
            ->assertJsonPath('data.proposed.body.content.2.attrs.href', '/voor-webshops')               // button target kept
            ->json('data.id');

        // The provider saw only the page text, never the API key or other data.
        Http::assertSent(fn ($r) => $r['store'] === false && str_contains($r['input'], 'Wij verbinden consumenten') && ! str_contains($r['input'], 'sk-test-cms'));
        // Same key replays; no second provider call or quota use.
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'improve-1'])->assertOk()->assertJsonPath('data.id', $s);
        Http::assertSentCount(1);

        // Nothing changed until a human applies it; applying saves a draft only.
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft.title', 'Over ShopBonus');
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply")->assertOk()->assertJsonPath('data.draft_revision', 2);
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft.title', 'Over ShopBonus: samen voordeliger')->assertJsonPath('data.locales_detail.nl.published', null);
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply")->assertStatus(409)->assertJsonPath('error.code', 'suggestion_not_open');
        $this->assertTrue(AuditEvent::where('action', 'cms.suggestion_applied')->exists());
    }

    public function test_suggestions_that_invent_facts_break_structure_or_go_stale_are_refused(): void
    {
        $id = $this->page();
        // A new percentage and a new link are invented facts.
        $this->fakeProvider(['Over ShopBonus', 'Nu 70% cashback', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Kijk op https://evil.example', ' Lees meer', 'Voor webshops']);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'bad-1'])
            ->assertStatus(503)->assertJsonPath('error.code', 'cms_suggestion_failed');
        // Wrong number of segments.
        $this->fakeProvider(['Alleen een titel']);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'bad-2'])->assertStatus(503);
        // HTML is never accepted.
        $this->fakeProvider(['<b>Over</b>', 'x', 'Spaar 10% bij webshops.', 'Ons verhaal', 'tekst', ' meer', 'Voor webshops']);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'bad-3'])->assertStatus(503);
        $this->assertSame(3, CmsAiSuggestion::where('status', 'failed')->count());
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft_revision', 1);

        // A suggestion made on revision 1 cannot overwrite a newer human edit.
        $this->fakeProvider(['Over ShopBonus', 'Over ShopBonus', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Tekst.', ' Lees meer', 'Voor webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'ok-1'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Handmatig', 'Handmatige tekst.')])->assertOk();
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply")->assertStatus(409)->assertJsonPath('error.code', 'draft_revision_conflict');
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft.title', 'Handmatig');
    }

    public function test_translation_creates_the_missing_language_as_a_draft_with_a_slug(): void
    {
        $id = $this->page();
        $this->fakeProvider(['About ShopBonus', 'About ShopBonus', 'Save 10% at webshops.', 'Our story', 'We connect shoppers and webshops.', ' Read more', 'For webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'en', 'idempotency_key' => 'tr-1'])
            ->assertCreated()->assertJsonPath('data.target_locale', 'en')->assertJsonPath('data.target_revision', null)->json('data.id');
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply")->assertUnprocessable(); // a new language needs an address
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply", ['slug' => 'about-us'])->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.slug', 'about-us');
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.en.draft.title', 'About ShopBonus');

        // improve must keep the language; translate must change it.
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'nl', 'idempotency_key' => 'tr-2'])->assertUnprocessable();
    }

    public function test_ai_is_off_unless_configured_rate_and_quota_limited_and_super_admin_only(): void
    {
        $id = $this->page();
        config(['cms.ai_enabled' => false]);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'off'])->assertStatus(503)->assertJsonPath('error.code', 'cms_ai_not_configured');
        config(['cms.ai_enabled' => true, 'cms.daily_limit' => 1]);
        $this->fakeProvider(['Over ShopBonus', 'Over ShopBonus', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Tekst.', ' Lees meer', 'Voor webshops']);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'q-1'])->assertCreated();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'q-2'])->assertStatus(429)->assertJsonPath('error.code', 'cms_ai_daily_limit');

        $consumer = User::factory()->create();
        $this->asAdmin($consumer);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/admin/cms/schedules')->assertForbidden();
    }

    public function test_segments_round_trip_and_fact_extraction(): void
    {
        $c = $this->content('T', 'Tekst', [['type' => 'image', 'attrs' => ['asset_id' => 'x', 'alt' => 'Foto']]]);
        $seg = CmsSuggestions::segments($c);
        $this->assertSame(['title', 'seo_title', 'seo_description', 'text', 'text', 'text', 'label', 'alt'], array_column($seg, 0));
        $this->assertSame($c, CmsSuggestions::replace($c, array_column($seg, 1)));
        $this->assertSame(['70', '%', 'https://x.nl', 'a@b.nl', '€', '12,50'], CmsSuggestions::facts('70% op https://x.nl mail a@b.nl voor €12,50'));
    }
}
