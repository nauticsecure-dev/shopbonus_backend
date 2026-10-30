<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\User;
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
            ->assertHeader('Cache-Control', 'max-age=60, public')
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
}
