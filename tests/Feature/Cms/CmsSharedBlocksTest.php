<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsSharedBlockVersion;
use App\Models\User;
use App\Services\Cms\CmsService;
use App\Services\Cms\CmsTextBindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CmsSharedBlocksTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    private CmsPage $page;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time()]);
        $this->page = app(CmsService::class)->create('shared-page', 'info', 'public', ['nl' => ['slug' => 'shared-page', 'content' => ['title' => 'Synthetic', 'body' => ['type' => 'doc', 'content' => []]]]], $this->admin->id);
        $this->base = '/api/v1/admin/cms/pages/'.$this->page->public_id.'/locales/nl';
    }

    private function node(string $text = 'Original'): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function block(): array
    {
        return $this->postJson('/api/v1/admin/cms/shared-blocks', ['key' => 'synthetic-block', 'locale' => 'nl', 'node' => $this->node()])->assertCreated()->json('data');
    }

    public function test_versions_are_immutable_and_explicit_pins_keep_published_content_stable(): void
    {
        $v = $this->block();
        $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id'], 'overrides' => ['content.0.text' => 'Local override']])->assertOk()->assertJsonPath('data.draft_revision', 2)->assertJsonPath('data.content.body.content.0.content.0.text', 'Local override');
        $published = app(CmsService::class)->publish($this->page, 'nl', $this->admin->id);
        $blockPath = '/api/v1/admin/cms/shared-blocks/'.$v['block_id'];
        $this->getJson($blockPath.'/usages?source=draft')->assertOk()->assertJsonPath('data.0.usages.0.source.id', $v['id']);
        $this->getJson($blockPath.'/usages?source=revision')->assertOk()->assertJsonPath('data.0.source.version.id', $published->public_id);
        $this->getJson('/api/v1/public/pages/nl/shared-page')->assertOk()->assertJsonPath('data.content.body.content.0.type', 'paragraph')->assertJsonPath('data.content.body.content.0.content.0.text', 'Local override');
        $v2 = $this->postJson($blockPath.'/versions', ['revision' => 1, 'locale' => 'nl', 'node' => $this->node('New version')])->assertCreated()->json('data');
        $this->postJson($blockPath.'/versions', ['revision' => 1, 'locale' => 'nl', 'node' => $this->node('Stale')])->assertConflict();
        $this->getJson('/api/v1/admin/cms/shared-blocks?limit=1')->assertOk()->assertJsonPath('data.0.revision', 2);
        $this->getJson($blockPath.'?locale=nl&after=1')->assertOk()->assertJsonCount(1, 'data.versions')->assertJsonPath('data.versions.0.id', $v2['id']);
        $preview = $this->getJson($this->base.'/media-preview?version=published')->assertOk()->assertJsonPath('data.shared_usages.0.source.id', $v['id'])->assertJsonPath('data.shared_usages.0.resolved_node.content.0.text', 'Local override');
        $this->assertSame($v['sha256'], $preview->json('data.shared_usages.0.source.sha256'));
        $this->postJson($this->base.'/shared-blocks', ['revision' => 2, 'version_id' => $v2['id'], 'index' => 0])->assertOk();
        $this->assertSame('Local override', $published->fresh()->content['body']['content'][0]['content'][0]['text']);
        $this->assertSame($v['id'], $published->content['shared_blocks']['body.content.0']['version_id']);
        $this->expectException(LogicException::class);
        CmsSharedBlockVersion::where('public_id', $v['id'])->sole()->update(['node' => $this->node('Illegal mutation')]);
    }

    public function test_pin_tampering_unsafe_overrides_and_silent_detach_are_rejected(): void
    {
        $v = $this->block();
        foreach ([['attrs.href' => 'javascript:alert(1)'], ['content.0.text' => '<script>'], ['content.0.text' => str_repeat('a', 5001)], ['type' => 'heading']] as $overrides) {
            $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id'], 'overrides' => $overrides])->assertUnprocessable();
        }
        $mounted = $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id']])->assertOk()->json('data.content');
        $changed = $mounted;
        $changed['body']['content'][0]['content'][0]['text'] = 'Untracked change';
        $this->putJson($this->base, ['revision' => 2, 'content' => $changed])->assertUnprocessable();
        $changed = $mounted;
        unset($changed['shared_blocks']);
        $this->putJson($this->base, ['revision' => 2, 'content' => $changed])->assertConflict();
        $this->postJson($this->base.'/shared-blocks/detach', ['revision' => 1, 'index' => 0])->assertConflict();
        $this->postJson($this->base.'/shared-blocks/detach', ['revision' => 2, 'index' => 0])->assertOk()->assertJsonPath('data.draft_revision', 3)->assertJsonPath('data.content.body.content.0.content.0.text', 'Original');
        unset($changed['shared_blocks']);
        $changed['body']['content'][0]['content'][0]['text'] = 'Owned text';
        $this->putJson($this->base, ['revision' => 3, 'content' => $changed])->assertOk();
        $this->postJson($this->base.'/shared-blocks/detach', ['revision' => 4, 'index' => 0])->assertConflict();
    }

    public function test_exact_locale_and_library_schema_are_enforced_before_ai_execution(): void
    {
        $v = $this->block();
        $path = '/api/v1/admin/cms/shared-blocks/'.$v['block_id'];
        $de = $this->postJson($path.'/versions', ['revision' => 1, 'locale' => 'de', 'node' => $this->node('Deutsch')])->assertCreated()->json('data');
        $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $de['id']])->assertUnprocessable();
        $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id'], 'index' => 2])->assertUnprocessable();
        $this->postJson('/api/v1/admin/cms/shared-blocks', ['key' => 'unsafe', 'locale' => 'nl', 'node' => ['type' => 'button', 'attrs' => ['label' => 'Unsafe', 'href' => 'javascript:alert(1)']]])->assertUnprocessable();
        $content = $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id']])->assertOk()->json('data.content');
        $this->assertSame(['title'], array_column(CmsTextBindings::discover($content), 'path'));
        $bindings = $this->getJson($this->base.'/bindings')->assertOk()->json('data');
        $text = collect($bindings)->firstWhere('path', 'body.content.0.content.0.text');
        $this->assertFalse($text['translatable']);
        $this->assertSame($v['id'], $text['shared_source']['version_id']);
        $this->putJson('/api/v1/admin/cms/pages/'.$this->page->public_id.'/locales/de', ['revision' => 1, 'slug' => 'shared-page-de', 'content' => $content])->assertUnprocessable();
        config(['cms.ai_enabled' => true, 'cms.api_key' => 'synthetic', 'cms.model' => 'gpt-test']);
        Http::preventStrayRequests();
        $this->postJson($this->base.'/suggestions', ['kind' => 'translate', 'target_locale' => 'de', 'idempotency_key' => 'shared-translate'])->assertUnprocessable()->assertJsonPath('error.code', 'cms_shared_block_translation_requires_locale_version');
        Http::assertNothingSent();
        $this->assertDatabaseCount('cms_ai_suggestions', 0);
    }

    public function test_usage_limit_library_pagination_and_archived_page_guard(): void
    {
        $v = $this->block();
        $this->postJson('/api/v1/admin/cms/shared-blocks', ['key' => 'second-block', 'locale' => 'en', 'node' => $this->node('Second')])->assertCreated();
        $first = $this->getJson('/api/v1/admin/cms/shared-blocks?limit=1')->assertOk()->json('next');
        $this->assertNotNull($first);
        $this->getJson('/api/v1/admin/cms/shared-blocks?limit=1&after='.$first)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next', null);
        $content = ['title' => 'Synthetic', 'body' => ['type' => 'doc', 'content' => array_fill(0, 20, $this->node())], 'shared_blocks' => []];
        for ($i = 0; $i < 20; $i++) {
            $content['shared_blocks']['body.content.'.$i] = ['version_id' => $v['id'], 'overrides' => []];
        }
        $this->putJson($this->base, ['revision' => 1, 'content' => $content])->assertOk();
        $this->postJson($this->base.'/shared-blocks', ['revision' => 2, 'version_id' => $v['id']])->assertUnprocessable();
        $this->assertSame(2, CmsPageLocale::where('page_id', $this->page->id)->sole()->draft_revision);
        $this->page->forceFill(['archived_at' => now()])->save();
        $this->postJson($this->base.'/shared-blocks', ['revision' => 2, 'index' => 0, 'version_id' => $v['id']])->assertConflict();
    }

    public function test_json_object_key_order_does_not_change_content_identity(): void
    {
        $v = $this->block();
        $mounted = $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id']])->assertOk()->json('data.content');
        $mounted['body']['content'][0] = ['content' => [['text' => 'Original', 'type' => 'text']], 'type' => 'paragraph'];
        $this->putJson($this->base, ['revision' => 2, 'content' => $mounted])->assertOk();
        $this->getJson($this->base.'/media-preview')->assertOk()->assertJsonPath('data.shared_usages.0.source.sha256', $v['sha256']);
    }

    public function test_library_write_failure_rolls_back_and_returns_a_sanitized_error(): void
    {
        config(['app.debug' => false]);
        $event = 'eloquent.creating: '.CmsSharedBlockVersion::class;
        Event::listen($event, fn () => throw new \RuntimeException('Private synthetic failure detail'));
        try {
            $r = $this->postJson('/api/v1/admin/cms/shared-blocks', ['key' => 'rolled-back', 'locale' => 'nl', 'node' => $this->node()])->assertStatus(500)->assertJsonPath('error.code', 'server_error')->assertJsonStructure(['error' => ['request_id']]);
            $this->assertStringNotContainsString('Private synthetic failure detail', $r->getContent());
            $this->assertDatabaseCount('cms_shared_blocks', 0);
            $this->assertDatabaseCount('cms_shared_block_versions', 0);
        } finally {
            Event::forget($event);
        }
    }

    public function test_authority_missing_records_and_required_revisions_are_enforced(): void
    {
        $v = $this->block();
        $this->postJson($this->base.'/shared-blocks', ['version_id' => $v['id']])->assertUnprocessable();
        $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])->assertNotFound();
        $this->getJson('/api/v1/admin/cms/shared-blocks/01AAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/cms/shared-blocks')->assertForbidden();
        $this->postJson('/api/v1/admin/cms/shared-blocks', ['key' => 'unauthorized', 'locale' => 'nl', 'node' => $this->node()])->assertForbidden();
        $this->postJson($this->base.'/shared-blocks', ['revision' => 1, 'version_id' => $v['id']])->assertForbidden();
        $this->assertSame(1, CmsPageLocale::where('page_id', $this->page->id)->sole()->draft_revision);
    }
}
