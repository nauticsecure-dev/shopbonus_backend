<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Jobs\RenderCmsDerivative;
use App\Models\CmsAsset;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Cms\CmsMedia;
use App\Services\Cms\CmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CmsAssetManagementTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    private CmsAsset $from;

    private CmsAsset $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Storage::fake('local');
        Bus::fake([RenderCmsDerivative::class]);
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->from = app(CmsMedia::class)->upload(UploadedFile::fake()->image('from.png', 80, 60), $this->admin);
        $this->to = app(CmsMedia::class)->upload(UploadedFile::fake()->image('to.png', 80, 60), $this->admin);
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function content(): array
    {
        return ['title' => 'Synthetic', 'body' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['asset_id' => $this->from->public_id, 'alt' => 'Synthetic']]]]];
    }

    private function page(string $key): CmsPage
    {
        return app(CmsService::class)->create($key, 'info', 'public', ['nl' => ['slug' => $key, 'content' => $this->content()]], $this->admin->id);
    }

    private function usage(CmsPage $page, int $revision = 1): array
    {
        return ['page_id' => $page->public_id, 'locale' => 'nl', 'revision' => $revision, 'path' => 'body.content.0.attrs.asset_id'];
    }

    public function test_archive_keeps_existing_drafts_and_history_but_blocks_new_references(): void
    {
        $page = $this->page('existing');
        $revision = app(CmsService::class)->publish($page, 'nl', $this->admin->id);
        $path = '/api/v1/admin/cms/assets/'.$this->from->public_id;
        $this->patchJson($path, ['revision' => 1, 'archived' => true, 'reason' => 'Retire outdated photo'])->assertOk()->assertJsonPath('data.archived', true)->assertJsonPath('data.revision', 2);
        $this->getJson('/api/v1/admin/cms/assets')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/cms/assets?include_archived=1')->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson($path, ['revision' => 1, 'archived' => false, 'reason' => 'Restore'])->assertConflict();
        $this->putJson('/api/v1/admin/cms/pages/'.$page->public_id.'/locales/nl', ['revision' => 1, 'content' => $this->content()])->assertOk();
        $this->postJson('/api/v1/admin/cms/pages', ['key' => 'new-reference', 'type' => 'info', 'locales' => ['nl' => ['slug' => 'new-reference', 'content' => $this->content()]]])->assertUnprocessable();
        $this->postJson($path.'/recipes', ['revision' => 2, 'breakpoints' => ['mobile' => ['x' => 0]]])->assertConflict();
        $this->getJson($path.'/original')->assertOk();
        $this->assertSame($this->from->public_id, $revision->fresh()->content['body']['content'][0]['attrs']['asset_id']);
        $this->patchJson($path, ['revision' => 2, 'archived' => false, 'reason' => 'Approved for reuse'])->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_selected_replacement_is_atomic_version_checked_and_never_publishes(): void
    {
        $a = $this->page('first');
        $b = $this->page('second');
        $published = app(CmsService::class)->publish($a, 'nl', $this->admin->id);
        $path = '/api/v1/admin/cms/assets/'.$this->from->public_id.'/replace';
        $body = ['replacement_asset_id' => $this->to->public_id, 'usages' => [$this->usage($a), $this->usage($b, 2)]];
        $this->postJson($path, $body)->assertConflict();
        $this->assertSame([1, 1], CmsPageLocale::orderBy('id')->pluck('draft_revision')->all());
        $body['usages'][1]['revision'] = 1;
        $this->postJson($path, $body)->assertOk()->assertJsonPath('scope', 'selected_drafts_only')->assertJsonCount(2, 'data');
        foreach (CmsPageLocale::all() as $locale) {
            $this->assertSame(2, $locale->draft_revision);
            $this->assertSame($this->to->public_id, $locale->draft_content['body']['content'][0]['attrs']['asset_id']);
        }
        $this->assertSame($this->from->public_id, $published->fresh()->content['body']['content'][0]['attrs']['asset_id']);
        $this->postJson($path, $body)->assertConflict();
        $this->postJson($path, ['replacement_asset_id' => $this->to->public_id, 'usages' => [$this->usage($a, 2)]])->assertConflict();
    }

    public function test_invalid_recipe_archived_target_and_non_admin_fail_without_changes(): void
    {
        $page = $this->page('protected');
        $path = '/api/v1/admin/cms/assets/'.$this->from->public_id.'/replace';
        $body = ['replacement_asset_id' => $this->to->public_id, 'usages' => [$this->usage($page)], 'recipe_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'];
        $this->postJson($path, $body)->assertUnprocessable();
        unset($body['recipe_id']);
        $this->patchJson('/api/v1/admin/cms/assets/'.$this->to->public_id, ['revision' => 1, 'archived' => true, 'reason' => 'Retired target'])->assertOk();
        $this->postJson($path, $body)->assertConflict();
        $this->patchJson('/api/v1/admin/cms/assets/'.$this->from->public_id, ['archived' => true, 'reason' => 'Missing revision'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($path, $body)->assertForbidden();
        $this->patchJson('/api/v1/admin/cms/assets/'.$this->from->public_id, ['revision' => 1, 'archived' => true, 'reason' => 'Unauthorized'])->assertForbidden();
        $this->assertSame(1, CmsPageLocale::where('page_id', $page->id)->sole()->draft_revision);
    }
}
