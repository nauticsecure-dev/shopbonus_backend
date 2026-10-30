<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\User;
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
            ->assertHeader('Cache-Control', 'max-age=60, public')
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
        $this->postJson('/api/v1/admin/content/voor-webshops/restore', [
            'revision_id' => $versions[0]['id'],
        ])->assertOk();
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
}
