<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\CmsAsset;
use App\Models\CmsPage;
use App\Models\User;
use App\Services\Cms\CmsScheduler;
use App\Services\Cms\CmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AboutUsCmsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_public_published_landing_and_draft_invisible(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->assertOk()->json('data');
        $this->assertSame('over-ons', $state['key']);
        $this->assertSame('landing', $state['type']);
        $this->assertNotNull($state['draft']);
        $this->assertNull($state['published']);
        $this->assertFalse($state['draft']['team']['show_profiles'] ?? true);
        $this->assertSame([], $state['draft']['team']['profiles'] ?? ['x']);
        $this->assertSame('', $state['draft']['contact']['kvk'] ?? 'x');
        $this->assertSame(70, $state['draft']['revenue_model']['consumer_percent'] ?? null);
        $this->assertSame(30, $state['draft']['revenue_model']['platform_percent'] ?? null);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertNotFound();

        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk()->assertJsonPath('data.number', 1);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/over-ons?locale=nl')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.content.hero.eyebrow', 'OVER SHOPBONUS')
            ->assertJsonPath('data.content.bottom_cta.consumer_cta.href', '/webshops')
            ->assertJsonPath('data.content.revenue_model.consumer_percent', 70)
            ->assertJsonPath('data.content.revenue_model.platform_percent', 30);
    }

    public function test_alias_content_pages_route_works(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/pages/over-ons/publish?locale=nl')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/pages/over-ons?locale=nl')->assertOk();
    }

    public function test_admin_draft_preview_publish_restore(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'eyebrow' => 'OVER SHOPBONUS',
                'heading' => 'Nieuwe hero over ons',
                'heading_accent' => 'voordeliger.',
                'subtitle' => 'Subtitle',
                'cta_label' => 'Go',
                'cta_href' => '#verhaal',
            ],
        ])->assertOk()->assertJsonPath('data.draft_revision', $rev + 1);

        $this->postJson('/api/v1/admin/content/over-ons/preview?locale=nl')
            ->assertOk()
            ->assertJsonPath('data.preview', true)
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero over ons');

        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/over-ons?locale=nl')
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero over ons');

        $this->asAdmin();
        $rev2 = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $rev2,
            'section' => [
                'heading' => 'Alleen draft',
                'cta_label' => 'X',
                'cta_href' => '#verhaal',
            ],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/over-ons?locale=nl')
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero over ons');

        $this->asAdmin();
        $versions = $this->getJson('/api/v1/admin/content/over-ons/versions?locale=nl')->assertOk()->json('data');
        $this->assertNotEmpty($versions);
        $this->postJson('/api/v1/admin/content/over-ons/restore', [
            'revision_id' => $versions[0]['id'],
        ])->assertOk();
    }

    public function test_guest_cannot_mutate(): void
    {
        $this->putJson('/api/v1/admin/content/over-ons', [
            'revision' => 1,
            'content' => ['title' => 'Hack'],
        ])->assertUnauthorized();
    }

    public function test_rejects_xss_and_unsafe_href(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => '<script>alert(1)</script>',
                'cta_label' => 'Go',
                'cta_href' => 'javascript:alert(1)',
            ],
        ])->assertStatus(422);

        $rev = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Ok heading',
                'cta_label' => 'Go',
                'cta_href' => 'javascript:alert(1)',
            ],
        ])->assertStatus(422);
    }

    public function test_nl_en_drafts_are_independent(): void
    {
        $this->asAdmin();
        $nlRev = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $nlRev,
            'section' => [
                'heading' => 'NL heading only',
                'cta_label' => 'NL',
                'cta_href' => '#verhaal',
            ],
        ])->assertOk();

        $en = $this->getJson('/api/v1/admin/content/over-ons?locale=en')->assertOk()->json('data');
        $this->assertNotSame('NL heading only', $en['draft']['hero']['heading'] ?? null);
    }

    public function test_ai_suggest_disabled_by_default(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/over-ons/ai-suggest', [
            'locale' => 'nl',
            'section' => 'hero',
            'focus' => 'heading',
        ])->assertStatus(503);
    }

    public function test_rejects_purchase_amount_cashback_claim(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Krijg 70% cashback op de aankoop',
                'cta_label' => 'Go',
                'cta_href' => '#verhaal',
            ],
        ])->assertStatus(422);
    }

    public function test_section_patch_does_not_wipe_other_sections(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->assertOk()->json('data');
        $before = $state['draft'];
        $storyHeading = $before['story']['heading'] ?? null;
        $this->assertNotEmpty($storyHeading);

        $this->patchJson('/api/v1/admin/content/over-ons/sections/hero?locale=nl', [
            'revision' => $state['draft_revision'],
            'section' => [
                'heading' => 'Alleen hero gewijzigd',
                'cta_label' => 'Go',
                'cta_href' => '#verhaal',
            ],
        ])->assertOk();

        $after = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->assertOk()->json('data.draft');
        $this->assertSame('Alleen hero gewijzigd', $after['hero']['heading']);
        $this->assertSame($storyHeading, $after['story']['heading']);
        $this->assertSame($before['values']['heading'], $after['values']['heading']);
        $this->assertSame($before['contact']['heading'], $after['contact']['heading']);
    }

    public function test_responsive_assets_profiles_and_selected_history_are_resolved(): void
    {
        $this->asAdmin();
        $asset = CmsAsset::create(['path' => 'cms/approved.webp', 'mime' => 'image/webp', 'width' => 800, 'height' => 800, 'uploaded_by' => $this->admin->id]);
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data');
        $content = $state['draft'];
        $content['media'] = ['hero' => ['desktop_asset_id' => $asset->public_id, 'mobile_asset_id' => $asset->public_id, 'alt' => 'Approved company photography']];
        $content['team']['show_profiles'] = true;
        $content['team']['profiles'] = [['name' => 'Fixture member', 'photo_asset_id' => $asset->public_id]];
        $this->putJson('/api/v1/admin/content/over-ons?locale=nl', ['revision' => $state['draft_revision'], 'content' => $content])->assertOk();
        $url = route('api.v1.public.content-assets.show', ['id' => $asset->public_id]);
        $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->assertJsonPath('data.draft.media.hero.mobile_url', $url)->assertJsonPath('data.draft.team.profiles.0.photo_url', $url);
        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertOk()->assertJsonPath('data.content.media.hero.desktop_url', $url)->assertJsonPath('data.content.team.profiles.0.photo_url', $url);
        $versions = $this->getJson('/api/v1/admin/content/over-ons/versions?locale=nl')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame($url, $versions[0]['content']['media']['hero']['mobile_url']);
        $this->postJson('/api/v1/admin/content/over-ons/restore', ['locale' => 'en', 'revision_id' => $versions[0]['id']])->assertNotFound();
    }

    public function test_invalid_media_reference_and_revision_conflict_do_not_replace_publication(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk();
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data');
        $content = $state['draft'];
        $content['media'] = ['hero' => ['desktop_asset_id' => 'missing', 'alt' => 'Image']];
        $this->putJson('/api/v1/admin/content/over-ons', ['locale' => 'nl', 'revision' => $state['draft_revision'], 'content' => $content])->assertUnprocessable();
        $this->putJson('/api/v1/admin/content/over-ons', ['locale' => 'nl', 'revision' => $state['draft_revision'] + 10, 'content' => $state['draft']])->assertConflict();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertOk()->assertJsonPath('data.content.hero.heading', $state['published']['hero']['heading']);
    }

    public function test_public_visibility_window_and_publication_are_not_stale(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data');
        $content = $state['draft'];
        $content['schedule'] = ['publish_at' => now()->addMinute()->toIso8601String(), 'unpublish_at' => now()->addMinutes(3)->toIso8601String()];
        $this->putJson('/api/v1/admin/content/over-ons', ['locale' => 'nl', 'revision' => $state['draft_revision'], 'content' => $content])->assertOk();
        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertNotFound();
        $this->travel(2)->minutes();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertOk();
        $this->travel(2)->minutes();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertNotFound();
    }

    public function test_queued_about_publication_retains_previous_publication_until_due(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk();
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data');
        $content = $state['draft'];
        $content['hero']['heading'] = 'Scheduled approved heading';
        $page = CmsPage::where('key', 'over-ons')->firstOrFail();
        $saved = app(CmsService::class)->saveDraft($page, 'nl', $content, $state['draft_revision'], null, $this->admin->id);
        $scheduler = app(CmsScheduler::class);
        $schedule = $scheduler->schedule($page, 'publish', 'nl', $saved->draft_revision, now()->addMinutes(2), $this->admin);
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertOk()->assertJsonPath('data.content.hero.heading', $state['published']['hero']['heading']);
        $this->travel(3)->minutes();
        $this->assertSame(1, $scheduler->runDue());
        $this->assertSame('done', $schedule->fresh()->status);
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertOk()->assertJsonPath('data.content.hero.heading', 'Scheduled approved heading');
    }

    public function test_publish_rejects_a_draft_changed_after_the_reviewed_save(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/over-ons/publish?locale=nl')->assertOk();
        $state = $this->getJson('/api/v1/admin/content/over-ons?locale=nl')->json('data');
        $content = $state['draft'];
        $content['hero']['heading'] = 'Another administrator changed this';
        $this->putJson('/api/v1/admin/content/over-ons', ['locale' => 'nl', 'revision' => $state['draft_revision'], 'content' => $content])->assertOk();
        $this->postJson('/api/v1/admin/content/over-ons/publish', ['locale' => 'nl', 'revision' => $state['draft_revision']])->assertConflict();
        $this->getJson('/api/v1/content/over-ons?locale=nl')->assertOk()->assertJsonPath('data.content.hero.heading', $state['published']['hero']['heading']);
    }
}
