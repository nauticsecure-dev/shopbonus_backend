<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Jobs\RenderCmsDerivative;
use App\Models\CmsAsset;
use App\Models\CmsMediaDerivative;
use App\Models\CmsMediaRecipe;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Cms\AboutUsCmsService;
use App\Services\Cms\CmsContentBindings;
use App\Services\Cms\CmsMedia;
use App\Services\Cms\CmsService;
use App\Services\Cms\HomepageCmsService;
use App\Services\Cms\MerchantLandingCmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CmsBindingsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    private CmsAsset $asset;

    private CmsMediaRecipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Storage::fake('local');
        Bus::fake([RenderCmsDerivative::class]);
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->asset = app(CmsMedia::class)->upload(UploadedFile::fake()->image('source.png', 80, 60), $this->admin);
        $this->recipe = CmsMediaRecipe::sole();
        foreach (CmsMediaDerivative::all() as $d) {
            app(CmsMedia::class)->render($d->id);
        }
    }

    private function content(): array
    {
        return ['title' => 'Synthetic page', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Actual component copy']]], ['type' => 'image', 'attrs' => ['asset_id' => $this->asset->public_id, 'alt' => 'Synthetic image']]]], 'media_recipes' => ['body.content.1.attrs.asset_id' => $this->recipe->public_id]];
    }

    private function page(): CmsPage
    {
        return app(CmsService::class)->create('binding-test', 'info', 'public', ['nl' => ['slug' => 'binding-test', 'content' => $this->content()]], $this->admin->id);
    }

    public function test_landing_renderers_preserve_exact_recipe_pins_instead_of_active_recipe(): void
    {
        $bindings = app(CmsContentBindings::class);
        $asset = $this->asset->public_id;
        $pin = $this->recipe->public_id;
        $content = ['hero' => ['background_asset_id' => $asset, 'background_mobile_asset_id' => $asset],
            'media' => ['hero' => ['desktop_asset_id' => $asset, 'mobile_asset_id' => $asset, 'alt' => 'Photo']],
            'media_recipes' => ['hero.background_asset_id' => $pin, 'hero.background_mobile_asset_id' => $pin,
                'media.hero.desktop_asset_id' => $pin, 'media.hero.mobile_asset_id' => $pin]];
        foreach ([HomepageCmsService::class, MerchantLandingCmsService::class, AboutUsCmsService::class] as $renderer) {
            $result = app($renderer)->resolveAssets($content);
            $this->assertStringEndsWith('/recipes/'.$pin.'/desktop', $result['hero']['background_url'] ?? $result['media']['hero']['desktop_url']);
            $this->assertStringEndsWith('/recipes/'.$pin.'/mobile', $result['hero']['background_mobile_url'] ?? $result['media']['hero']['mobile_url']);
        }
        $original = $bindings->resolveMedia($content);
        $this->asset->forceFill(['active_recipe_id' => null])->save();
        $this->assertSame($original, $bindings->resolveMedia($content));
        $foreign = app(CmsMedia::class)->upload(UploadedFile::fake()->image('foreign.png', 80, 60), $this->admin);
        $content['hero']['background_asset_id'] = $foreign->public_id;
        $result = $bindings->resolveMedia($content);
        $this->assertArrayNotHasKey('background_url', $result['hero']);
        $rich = $bindings->resolveMedia($this->content());
        $this->assertStringEndsWith('/recipes/'.$pin.'/desktop', $rich['body']['content'][1]['attrs']['src']);
    }

    public function test_snapshot_bindings_pin_exact_source_recipe_and_survive_other_recipe_changes(): void
    {
        $page = $this->page();
        $cms = app(CmsService::class);
        $published = $cms->publish($page, 'nl', $this->admin->id);
        Sanctum::actingAs($this->admin);
        $base = '/api/v1/admin/cms/pages/'.$page->public_id.'/locales/nl';
        $r = $this->getJson($base.'/bindings?limit=2')->assertOk()->assertJsonPath('source.scope', 'platform_global')->assertJsonPath('source.webshop_id', null)->assertJsonPath('coverage.frontend', 'unknown');
        $hash = $r->json('source.content_sha256');
        $this->assertSame(2, count($r->json('data')));
        $this->getJson($base.'/bindings?after=2&hash='.$hash)->assertOk();
        $preview = $this->getJson($base.'/media-preview?version=published')->assertOk()->assertJsonPath('data.content.body.content.0.content.0.text', 'Actual component copy')->assertJsonPath('data.media_usages.0.recipe_id', $this->recipe->public_id)->assertJsonPath('data.media_usages.0.derivatives.mobile.status', 'ready');
        $url = $preview->json('data.media_usages.0.derivatives.mobile.url');
        app(CmsMedia::class)->restore($this->asset->fresh(), $this->recipe, 1, $this->admin);
        $this->getJson($base.'/media-preview?version='.$published->public_id)->assertOk()->assertJsonPath('data.media_usages.0.derivatives.mobile.url', $url);
        $changed = $this->content();
        $changed['title'] = 'New draft';
        $cms->saveDraft($page, 'nl', $changed, 1, null, $this->admin->id);
        $this->getJson($base.'/bindings?after=2&hash='.$hash)->assertConflict();
        $this->getJson($base.'/media-preview?version=published')->assertOk()->assertJsonPath('data.content.title', 'Synthetic page');
        $this->getJson('/api/v1/admin/cms/assets/'.$this->asset->public_id.'/usages?source=draft')->assertOk()->assertJsonPath('data.0.paths.0', 'body.content.1.attrs.asset_id');
        $this->getJson('/api/v1/admin/cms/assets/'.$this->asset->public_id.'/usages?source=revision')->assertOk()->assertJsonPath('data.0.source.version.id', $published->public_id);
    }

    public function test_recipe_cannot_be_pinned_to_a_foreign_missing_or_incomplete_usage(): void
    {
        $other = app(CmsMedia::class)->upload(UploadedFile::fake()->image('other.png', 80, 60), $this->admin);
        foreach (['foreign', 'missing', 'incomplete'] as $case) {
            $content = $this->content();
            if ($case === 'foreign') {
                $content['body']['content'][1]['attrs']['asset_id'] = $other->public_id;
            }
            if ($case === 'missing') {
                $content['media_recipes'] = ['hero.nonexistent' => $this->recipe->public_id];
            }
            if ($case === 'incomplete') {
                $content['body']['content'][1]['attrs']['asset_id'] = $other->public_id;
                $content['media_recipes']['body.content.1.attrs.asset_id'] = CmsMediaRecipe::where('asset_id', $other->id)->sole()->public_id;
            }
            try {
                app(CmsService::class)->validateContent($content, 'info', 'binding-test');
                $this->fail('Unbound recipe accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_legacy_unpinned_content_and_landing_schemas_remain_compatible(): void
    {
        $content = $this->content();
        unset($content['media_recipes']);
        $page = app(CmsService::class)->create('legacy-binding', 'info', 'public', ['nl' => ['slug' => 'legacy-binding', 'content' => $content]], $this->admin->id);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/cms/pages/'.$page->public_id.'/locales/nl/media-preview')->assertOk()->assertJsonPath('data.media_usages.0.pin_state', 'recipe_not_pinned');
        foreach (['homepage', 'over-ons', 'voor-webshops'] as $key) {
            $method = match ($key) {
                'homepage' => 'ensureHomepage','over-ons' => 'ensureAboutUs',default => 'ensureMerchantLanding'
            };
            $p = app(CmsService::class)->$method($this->admin->id);
            $l = CmsPageLocale::where('page_id', $p->id)->where('locale', 'nl')->sole();
            $c = $l->draft_content;
            $c['media_recipes'] = [];
            $valid = app(CmsService::class)->validateContent($c, 'landing', $key);
            $this->assertSame([], $valid['media_recipes']);
        }
    }

    public function test_admin_only_read_boundaries_and_cross_page_revision_lookup(): void
    {
        $page = $this->page();
        $base = '/api/v1/admin/cms/pages/'.$page->public_id.'/locales/nl';
        $this->getJson($base.'/bindings')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($base.'/media-preview')->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->getJson($base.'/bindings?version=published')->assertNotFound();
        $this->getJson($base.'/bindings?hash=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/admin/cms/assets/'.$this->asset->public_id.'/usages')->assertUnprocessable();
        $foreign = app(CmsService::class)->create('foreign-binding', 'info', 'public', ['nl' => ['slug' => 'foreign-binding', 'content' => $this->content()]], $this->admin->id);
        $r = app(CmsService::class)->publish($foreign, 'nl', $this->admin->id);
        $this->getJson($base.'/media-preview?version='.$r->public_id)->assertNotFound();
    }

    public function test_usage_scan_requires_following_empty_pages_before_claiming_no_references(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $p = CmsPage::create(['key' => 'scan-'.$i, 'type' => 'info', 'created_by' => $this->admin->id]);
            CmsPageLocale::create(['page_id' => $p->id, 'locale' => 'nl', 'slug' => 'scan-'.$i, 'draft_content' => ['title' => 'No image', 'body' => ['type' => 'doc', 'content' => []]]]);
        }
        $page = $this->page();
        Sanctum::actingAs($this->admin);
        $url = '/api/v1/admin/cms/assets/'.$this->asset->public_id.'/usages?source=draft';
        $first = $this->getJson($url)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('scan.scanned', 100);
        $this->assertNotNull($first->json('scan.next'));
        $this->getJson($url.'&after='.$first->json('scan.next'))->assertOk()->assertJsonPath('scan.next', null)->assertJsonPath('data.0.source.page_id', $page->public_id);
    }
}
