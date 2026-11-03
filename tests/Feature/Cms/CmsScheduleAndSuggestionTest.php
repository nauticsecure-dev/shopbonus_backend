<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPublicationSchedule;
use App\Models\CmsRevision;
use App\Models\User;
use App\Services\Cms\AboutUsSchema;
use App\Services\Cms\CmsSuggestions;
use App\Services\Cms\HomepageSchema;
use App\Services\Cms\MerchantLandingSchema;
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
        config(['cms.ai_enabled' => true, 'cms.api_key' => 'sk-test-cms', 'cms.model' => 'gpt-test', 'cms.daily_limit' => 5, 'cms.queue_connection' => 'sync', 'cms.auto_translate' => false]);
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
        Http::fake(['api.openai.com/*' => function ($request) use ($segments) {
            $input = json_decode($request['input'], true);

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 80], 'output' => [
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'key' => $input['key'], 'source_hash' => $input['source_hash'], 'locale' => $input['locale'],
                    'segments' => $segments, 'review' => true, 'warnings' => [],
                ])]]],
            ]]);
        }]);
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
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish", ['revision' => 1])->assertOk();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/schedules", ['action' => 'archive', 'due_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->getJson('/api/v1/admin/cms/schedules?status=done')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'archive');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertNotFound();
    }

    // ------------------------------------------------------------ AI suggestions

    public function test_structured_landing_translations_keep_sections_and_apply_only_reviewed_german_drafts(): void
    {
        foreach (['homepage', 'over-ons', 'voor-webshops'] as $key) {
            $this->asAdmin();
            $content = ['title' => 'Welkom bij ShopBonus', 'hero' => ['heading' => 'Welkom'],
                'nav' => ['links' => [['label' => 'Voordelen', 'href' => '/voordelen']]],
                'body' => ['type' => 'doc', 'content' => []]];
            $response = $this->postJson('/api/v1/admin/cms/pages', ['key' => $key, 'type' => 'landing',
                'locales' => ['nl' => ['slug' => $key, 'content' => $content]]]);
            $this->assertSame(201, $response->status(), $response->getContent());
            $id = $response->json('data.id');
            $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish", ['revision' => 1])->assertOk();
            $translated = ['Welkom bij ShopBonus' => 'Willkommen bei ShopBonus', 'Voordelen' => 'Vorteile', 'Welkom' => 'Willkommen'];
            $this->fakeProvider(array_map(fn ($segment) => $translated[$segment[1]], CmsSuggestions::segments($content, 'landing', $key)));
            $suggestion = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate',
                'target_locale' => 'de', 'idempotency_key' => 'structured-'.$key])->assertCreated()
                ->assertJsonPath('data.proposed.hero.heading', 'Willkommen')
                ->assertJsonPath('data.proposed.nav.links.0.href', '/voordelen')->json('data.id');
            Http::assertSent(fn ($r) => str_contains($r['input'], 'hero.heading') && ! str_contains($r['input'], '/voordelen'));
            $this->postJson("/api/v1/admin/cms/suggestions/{$suggestion}/apply", ['slug' => $key.'-de'])->assertOk();
            $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertOk()
                ->assertJsonPath('data.locales_detail.de.draft.hero.heading', 'Willkommen')
                ->assertJsonPath('data.locales_detail.de.published', null)
                ->assertJsonPath('data.locales_detail.nl.published.hero.heading', 'Welkom');
        }
    }

    public function test_full_landing_defaults_round_trip_through_queue_without_losing_copy_or_settings(): void
    {
        foreach (['homepage' => HomepageSchema::class, 'over-ons' => AboutUsSchema::class,
            'voor-webshops' => MerchantLandingSchema::class] as $key => $schema) {
            $this->asAdmin();
            $content = app($schema)->defaults('nl');
            $response = $this->postJson('/api/v1/admin/cms/pages', ['key' => $key, 'type' => 'landing',
                'locales' => ['nl' => ['slug' => $key, 'content' => $content]]]);
            $this->assertSame(201, $response->status(), $response->getContent());
            $id = $response->json('data.id');
            $draft = $this->getJson("/api/v1/admin/cms/pages/{$id}")->json('data.locales_detail.nl.draft');
            $this->fakeProvider(array_column(CmsSuggestions::segments($draft, 'landing', $key), 1));
            $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'full-'.$key])
                ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.proposed', $draft);
        }
    }

    public function test_improve_suggestion_keeps_structure_needs_human_approval_and_never_publishes(): void
    {
        $id = $this->page();
        $this->fakeProvider(['Over ShopBonus: samen voordeliger', 'ShopBonus: samen voordeliger', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Wij brengen consumenten en webshops samen.', ' Lees verder', 'Voor webshops']);
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
        $this->assertSame(300, (int) CmsAiSuggestion::where('status', 'failed')->sum('input_tokens'));
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft_revision', 1);

        // A suggestion made on revision 1 cannot overwrite a newer human edit.
        $this->fakeProvider(['Over ShopBonus', 'Over ShopBonus', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Tekst.', ' Lees meer', 'Voor webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'ok-1'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Handmatig', 'Handmatige tekst.')])->assertOk();
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply")->assertStatus(409)->assertJsonPath('error.code', 'source_revision_conflict');
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
        // Identical pending work reuses the existing proposal even with a different key.
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'q-2'])->assertOk();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'en', 'idempotency_key' => 'q-3'])->assertStatus(429)->assertJsonPath('error.code', 'cms_ai_daily_limit');

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

    public function test_german_translation_is_private_and_nl_changes_invalidate_its_proposal(): void
    {
        $id = $this->page();
        $this->fakeProvider(['Über ShopBonus', 'Über ShopBonus', 'Spare 10% bei Webshops.', 'Unsere Geschichte', 'Wir verbinden Kunden und Webshops.', ' Mehr lesen', 'Für Webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'de', 'idempotency_key' => 'de-1'])
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->json('data.id');
        $row = CmsAiSuggestion::where('public_id', $s)->firstOrFail();
        $this->assertSame(100, $row->input_tokens);
        $this->assertSame(80, $row->output_tokens);
        $this->assertSame('gpt-test', $row->model);
        $this->assertSame(64, strlen($row->source_hash));
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Nieuwe bron', 'Nieuwe tekst.')])->assertOk();
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply", ['slug' => 'uber-uns'])->assertStatus(409)->assertJsonPath('error.code', 'source_revision_conflict');
        $this->assertDatabaseMissing('cms_page_locales', ['page_id' => $row->page_id, 'locale' => 'de']);
        $this->assertSame(0, CmsRevision::count());
    }

    public function test_queued_work_deduplicates_and_rechecks_the_source_before_any_provider_call(): void
    {
        $id = $this->page();
        config(['cms.queue_connection' => 'database']);
        Http::preventStrayRequests();
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'de', 'idempotency_key' => 'queued-1'])
            ->assertCreated()->assertJsonPath('data.status', 'queued')->json('data.id');
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'de', 'idempotency_key' => 'queued-2'])
            ->assertOk()->assertJsonPath('data.id', $s);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('cms_ai_quotas', ['requests' => 1]);
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Wijziging', 'Nieuw.')])->assertOk();
        $row = CmsAiSuggestion::where('public_id', $s)->firstOrFail();
        app(CmsSuggestions::class)->process($row->id);
        app(CmsSuggestions::class)->process($row->id);
        $this->assertSame('failed', $row->fresh()->status);
        Http::assertNothingSent();
        $this->assertTrue(AuditEvent::where('action', 'cms.suggestion_failed')->exists());
    }

    public function test_successful_german_application_and_other_locales_remain_isolated(): void
    {
        $id = $this->page();
        $this->fakeProvider(['Über ShopBonus', 'Über ShopBonus', 'Spare 10% bei Webshops.', 'Unsere Geschichte', 'Wir verbinden Kunden und Webshops.', ' Mehr lesen', 'Für Webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'de', 'idempotency_key' => 'de-ok'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply", ['slug' => 'uber-uns'])->assertOk()->assertJsonPath('data.locale', 'de');
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.de.draft.title', 'Über ShopBonus')
            ->assertJsonPath('data.locales_detail.de.published', null)->assertJsonPath('data.locales_detail.nl.draft.title', 'Over ShopBonus');
        $this->assertSame(0, CmsRevision::count());
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'fr', 'idempotency_key' => 'fr-no'])->assertUnprocessable();
    }

    public function test_legal_content_and_revoked_admin_jobs_never_reach_the_provider(): void
    {
        $terms = $this->page('voorwaarden', 'terms');
        Http::preventStrayRequests();
        $this->postJson("/api/v1/admin/cms/pages/{$terms}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'legal-no'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'cms_ai_legal_excluded');
        $id = $this->page();
        config(['cms.queue_connection' => 'database']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'translate', 'target_locale' => 'de', 'idempotency_key' => 'revoked'])->assertCreated()->json('data.id');
        $this->admin->platformRoles()->delete();
        app(CmsSuggestions::class)->process(CmsAiSuggestion::where('public_id', $s)->value('id'));
        $this->assertDatabaseHas('cms_ai_suggestions', ['public_id' => $s, 'status' => 'failed']);
        Http::assertNothingSent();
    }

    public function test_invalid_identity_contract_or_provider_outage_retains_the_published_revision(): void
    {
        $id = $this->page();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish", ['revision' => 1])->assertOk();
        Http::swap(new Factory);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['segments' => []])]]],
        ]])]);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'contract-no'])->assertStatus(503);
        Http::swap(new Factory);
        Http::fake(['api.openai.com/*' => Http::response([], 503)]);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'outage'])->assertStatus(503);
        $this->assertSame(1, CmsRevision::count());
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.published.title', 'Over ShopBonus')->assertJsonPath('data.locales_detail.nl.draft_revision', 1);
    }

    public function test_master_revision_invalidates_translations_even_in_the_same_second_and_restore_retains_provenance(): void
    {
        $this->freezeTime();
        $id = $this->page();
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/en", ['revision' => 1, 'slug' => 'about', 'content' => $this->content('ShopBonus', 'English content.')])->assertOk();
        $r = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/en/publish", ['revision' => 1])->assertOk()->json('data.id');
        $rows = $this->getJson('/api/v1/admin/cms/translations')->assertOk()->json('data');
        $this->assertSame('published', collect($rows)->firstWhere('locale', 'en')['status']);
        $this->assertSame('missing', collect($rows)->firstWhere('locale', 'de')['status']);
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Nieuwe bron', 'Nieuwe tekst.')])->assertOk();
        $rows = $this->getJson('/api/v1/admin/cms/translations')->assertOk()->json('data');
        $this->assertSame('stale', collect($rows)->firstWhere('locale', 'en')['status']);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/revisions/{$r}/restore")->assertOk();
        $rows = $this->getJson('/api/v1/admin/cms/translations')->assertOk()->json('data');
        $this->assertSame('stale', collect($rows)->firstWhere('locale', 'en')['status']);
        $this->assertSame([1, 1], CmsRevision::where('locale', 'en')->orderBy('id')->pluck('source_master_revision')->all());
    }

    public function test_nl_save_automatically_queues_en_and_de_and_budget_failure_preserves_the_save(): void
    {
        $id = $this->page();
        config(['cms.auto_translate' => true, 'cms.queue_connection' => 'database']);
        Http::preventStrayRequests();
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('ShopBonus', 'Nieuwe tekst.')])
            ->assertOk()->assertJsonPath('data.draft_revision', 2);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(['de', 'en'], CmsAiSuggestion::orderBy('target_locale')->pluck('target_locale')->all());
        $this->assertSame([2, 2], CmsAiSuggestion::pluck('source_revision')->all());
        Http::assertNothingSent();
        config(['cms.daily_limit' => 0]);
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 2, 'content' => $this->content('ShopBonus', 'Nog een wijziging.')])
            ->assertOk()->assertJsonPath('data.draft_revision', 3);
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft.body.content.1.content.0.text', 'Nog een wijziging.');
        $this->assertSame(2, AuditEvent::where('action', 'cms.translation_deferred')->count());
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(0, CmsRevision::count());
    }

    public function test_legacy_unverified_proposal_cannot_be_applied(): void
    {
        $id = $this->page();
        $this->fakeProvider(['Over ShopBonus', 'Over ShopBonus', 'Spaar 10% bij webshops.', 'Ons verhaal', 'Tekst.', ' Lees meer', 'Voor webshops']);
        $s = $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/suggestions", ['kind' => 'improve', 'idempotency_key' => 'legacy'])->assertCreated()->json('data.id');
        CmsAiSuggestion::where('public_id', $s)->update(['source_hash' => null, 'model' => null, 'input_tokens' => null, 'output_tokens' => null]);
        $this->postJson("/api/v1/admin/cms/suggestions/{$s}/apply")->assertStatus(409)->assertJsonPath('error.code', 'suggestion_unverified');
        $this->getJson("/api/v1/admin/cms/pages/{$id}")->assertJsonPath('data.locales_detail.nl.draft_revision', 1);
        $this->assertSame(0, CmsRevision::count());
    }
}
