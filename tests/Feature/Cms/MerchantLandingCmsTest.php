<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\CmsAsset;
use App\Models\User;
use App\Services\Cms\CmsScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class MerchantLandingCmsTest extends TestCase
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
        $state = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->assertOk()->json('data');
        $this->assertSame('voor-webshops', $state['key']);
        $this->assertNotNull($state['draft']);
        $this->assertNull($state['published']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/voor-webshops?locale=nl')->assertNotFound();

        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/voor-webshops/publish?locale=nl')->assertOk()->assertJsonPath('data.number', 1);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/voor-webshops?locale=nl')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.content.hero.eyebrow', 'VOOR WEBSHOPS')
            ->assertJsonPath('data.content.bottom_cta.cta_href', '/voor-webshops/aanmelden');
    }

    public function test_alias_content_pages_route_works(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/pages/voor-webshops/publish?locale=nl')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/pages/voor-webshops?locale=nl')->assertOk();
    }

    public function test_admin_draft_preview_publish_restore(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/voor-webshops/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'eyebrow' => 'VOOR WEBSHOPS',
                'heading' => 'Nieuwe hero voor webshops',
                'subtitle' => 'Subtitle',
                'primary_cta' => ['label' => 'Go', 'href' => '/voor-webshops/aanmelden'],
                'trust' => ['A', 'B'],
            ],
        ])->assertOk()->assertJsonPath('data.draft_revision', $rev + 1);

        $this->postJson('/api/v1/admin/content/voor-webshops/preview?locale=nl')
            ->assertOk()
            ->assertJsonPath('data.preview', true)
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero voor webshops');

        $this->postJson('/api/v1/admin/content/voor-webshops/publish?locale=nl')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/voor-webshops?locale=nl')
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero voor webshops');

        $this->asAdmin();
        $rev2 = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/voor-webshops/sections/hero?locale=nl', [
            'revision' => $rev2,
            'section' => [
                'heading' => 'Alleen draft',
                'primary_cta' => ['label' => 'X', 'href' => '/voor-webshops/aanmelden'],
            ],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/voor-webshops?locale=nl')
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero voor webshops');

        $this->asAdmin();
        $versions = $this->getJson('/api/v1/admin/content/voor-webshops/versions?locale=nl')->assertOk()->json('data');
        $this->assertNotEmpty($versions);
        $this->assertArrayNotHasKey('content', $versions[0]);
        $snapshot = $this->getJson('/api/v1/admin/content/voor-webshops/versions?locale=nl&include_content=1')
            ->assertOk()->json('data.0');
        $this->assertSame($versions[0]['id'], $snapshot['id']);
        $this->assertSame('Nieuwe hero voor webshops', $snapshot['content']['hero']['heading']);
        $this->assertNotSame('Alleen draft', $snapshot['content']['hero']['heading']);
        $this->getJson('/api/v1/admin/content/pages/voor-webshops/versions?locale=nl&include_content=1')
            ->assertOk()->assertJsonPath('data.0.content.hero.heading', 'Nieuwe hero voor webshops');
        $this->postJson('/api/v1/admin/content/voor-webshops/restore', [
            'revision_id' => $versions[0]['id'],
        ])->assertOk();
    }

    public function test_revision_content_is_not_accessible_to_guests_or_merchants(): void
    {
        $this->getJson('/api/v1/admin/content/voor-webshops/versions?include_content=1')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/content/voor-webshops/versions?include_content=1')->assertForbidden();
    }

    public function test_guest_cannot_mutate(): void
    {
        $this->putJson('/api/v1/admin/content/voor-webshops', [
            'revision' => 1,
            'content' => ['title' => 'Hack'],
        ])->assertUnauthorized();
    }

    public function test_rejects_xss_and_unsafe_href(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/voor-webshops/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => '<script>alert(1)</script>',
                'primary_cta' => ['label' => 'Go', 'href' => 'javascript:alert(1)'],
            ],
        ])->assertStatus(422);

        $rev = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/voor-webshops/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Ok heading',
                'primary_cta' => ['label' => 'Go', 'href' => 'javascript:alert(1)'],
            ],
        ])->assertStatus(422);
    }

    public function test_nl_en_drafts_are_independent(): void
    {
        $this->asAdmin();
        $nlRev = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/voor-webshops/sections/hero?locale=nl', [
            'revision' => $nlRev,
            'section' => [
                'heading' => 'NL heading only',
                'primary_cta' => ['label' => 'NL', 'href' => '/voor-webshops/aanmelden'],
            ],
        ])->assertOk();

        $en = $this->getJson('/api/v1/admin/content/voor-webshops?locale=en')->assertOk()->json('data');
        $this->assertNotSame('NL heading only', $en['draft']['hero']['heading'] ?? null);
    }

    public function test_platform_enrichment_overrides_false_available(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/voor-webshops/sections/platforms?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Platforms',
                'tiles' => [
                    [
                        'platform' => 'mijnwebwinkel',
                        'name' => 'Mijnwebwinkel',
                        'status' => 'AVAILABLE',
                        'href' => '/voor-webshops/aanmelden?platform=mijnwebwinkel',
                    ],
                ],
            ],
        ])->assertOk();

        $preview = $this->postJson('/api/v1/admin/content/voor-webshops/preview?locale=nl')->assertOk()->json('data.content');
        $tile = $preview['platforms']['tiles'][0];
        $this->assertSame('COMING_SOON', $tile['status']);
        $this->assertFalse($tile['connectable']);
    }

    public function test_ai_suggest_disabled_by_default(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/voor-webshops/ai-suggest', [
            'locale' => 'nl',
            'section' => 'hero',
            'focus' => 'heading',
        ])->assertStatus(503);
    }

    public function test_media_library_requires_admin_and_validates_pagination(): void
    {
        $this->getJson('/api/v1/admin/content/voor-webshops/assets')->assertUnauthorized();
        $this->asAdmin();
        $this->getJson('/api/v1/admin/content/voor-webshops/assets?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/admin/content/voor-webshops/assets')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.last_page', 1);
        $ordinary = User::factory()->create();
        Sanctum::actingAs($ordinary);
        $this->getJson('/api/v1/admin/content/voor-webshops/assets')->assertForbidden();
    }

    public function test_responsive_media_and_footer_are_validated_and_published(): void
    {
        $this->asAdmin();
        $asset = CmsAsset::create(['path' => 'cms/test.webp', 'mime' => 'image/webp', 'width' => 800, 'height' => 600, 'uploaded_by' => $this->admin->id]);
        $state = $this->getJson('/api/v1/admin/content/voor-webshops?locale=nl')->json('data');
        $draft = $state['draft'];
        $draft['media'] = ['hero' => ['desktop_asset_id' => $asset->public_id, 'mobile_asset_id' => $asset->public_id, 'alt' => 'Merchant dashboard']];
        $draft['footer']['links'] = [['label' => 'Help', 'href' => '/help']];
        $draft['platforms']['tiles'][0]['description'] = 'Connect your store';
        $this->putJson('/api/v1/admin/content/voor-webshops', ['locale' => 'nl', 'revision' => $state['draft_revision'], 'content' => $draft])->assertOk();
        $this->postJson('/api/v1/admin/content/voor-webshops/publish?locale=nl')->assertOk();
        $public = $this->getJson('/api/v1/content/voor-webshops?locale=nl')->assertOk();
        $public->assertJsonPath('data.content.footer.links.0.href', '/help')
            ->assertJsonPath('data.content.media.hero.alt', 'Merchant dashboard');
        $this->assertStringContainsString($asset->public_id, $public->json('data.content.media.hero.mobile_url'));
        $this->getJson('/api/v1/admin/content/voor-webshops/assets')->assertOk()->assertJsonPath('data.0.id', $asset->public_id);
        $revision = $this->getJson('/api/v1/admin/content/voor-webshops')->json('data.draft_revision');
        $draft['media']['hero']['desktop_asset_id'] = 'unknown';
        $this->putJson('/api/v1/admin/content/voor-webshops', ['revision' => $revision, 'content' => $draft])->assertUnprocessable();
        $draft['media']['hero']['desktop_asset_id'] = $asset->public_id;
        $draft['footer']['links'][0]['href'] = 'javascript:alert(1)';
        $this->putJson('/api/v1/admin/content/voor-webshops', ['revision' => $revision, 'content' => $draft])->assertUnprocessable();
    }

    public function test_schedule_boundaries_are_checked_even_when_publication_is_cached(): void
    {
        $this->asAdmin();
        $revision = $this->getJson('/api/v1/admin/content/voor-webshops')->json('data.draft_revision');
        $this->postJson('/api/v1/admin/content/voor-webshops/schedule', [
            'revision' => $revision, 'publish_at' => now()->addMinute()->toIso8601String(), 'unpublish_at' => now()->addMinutes(3)->toIso8601String(),
        ])->assertOk();
        $this->postJson('/api/v1/admin/content/voor-webshops/publish')->assertOk();
        $this->getJson('/api/v1/content/voor-webshops')->assertNotFound();
        $this->travel(2)->minutes();
        $this->getJson('/api/v1/content/voor-webshops')->assertOk();
        $this->travel(2)->minutes();
        $this->getJson('/api/v1/content/voor-webshops')->assertNotFound();
    }

    public function test_conflicted_draft_does_not_change_publication(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/voor-webshops')->json('data');
        $this->postJson('/api/v1/admin/content/voor-webshops/publish')->assertOk();
        $draft = $state['draft'];
        $draft['hero']['heading'] = 'Saved draft only';
        $this->putJson('/api/v1/admin/content/voor-webshops', ['revision' => $state['draft_revision'], 'content' => $draft])->assertOk();
        $draft['hero']['heading'] = 'Conflicting change';
        $this->putJson('/api/v1/admin/content/voor-webshops', ['revision' => $state['draft_revision'], 'content' => $draft])->assertConflict();
        $this->postJson('/api/v1/admin/content/voor-webshops/publish', ['revision' => $state['draft_revision']])->assertConflict();
        $this->getJson('/api/v1/content/voor-webshops')->assertOk()->assertJsonPath('data.content.hero.heading', $state['draft']['hero']['heading']);
        $this->postJson('/api/v1/admin/content/voor-webshops/publish', ['revision' => $state['draft_revision'] + 1])->assertOk();
    }

    public function test_queued_merchant_publication_keeps_current_live_and_pins_reviewed_draft(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/voor-webshops')->json('data');
        $this->postJson('/api/v1/admin/content/voor-webshops/publish')->assertOk();
        $draft = $state['draft'];
        $draft['hero']['heading'] = 'Reviewed scheduled merchant publication';
        $saved = $this->putJson('/api/v1/admin/content/voor-webshops', ['revision' => $state['draft_revision'], 'content' => $draft])->assertOk()->json('data.draft_revision');
        $url = '/api/v1/admin/cms/pages/'.$state['id'].'/schedules';
        $this->postJson($url, ['action' => 'publish', 'locale' => 'nl', 'revision' => $state['draft_revision'], 'due_at' => now()->addMinutes(2)->toIso8601String()])->assertConflict();
        $this->postJson($url, ['action' => 'publish', 'locale' => 'nl', 'revision' => $saved, 'due_at' => now()->addMinutes(2)->toIso8601String()])->assertCreated();
        $this->getJson('/api/v1/content/voor-webshops')->assertJsonPath('data.content.hero.heading', $state['draft']['hero']['heading']);
        $this->travel(3)->minutes();
        $this->assertSame(1, app(CmsScheduler::class)->runDue());
        $this->getJson('/api/v1/content/voor-webshops')->assertJsonPath('data.content.hero.heading', 'Reviewed scheduled merchant publication');
    }
}
