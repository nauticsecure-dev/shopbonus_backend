<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Jobs\RenderCmsDerivative;
use App\Models\CmsAsset;
use App\Models\CmsMediaDerivative;
use App\Models\CmsMediaRecipe;
use App\Models\User;
use App\Services\Cms\CmsMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CmsMediaTest extends TestCase
{
    use InteractsWithIdentity,RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Storage::fake('local');
        Bus::fake([RenderCmsDerivative::class]);
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function upload(): CmsAsset
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/cms/assets', ['image' => UploadedFile::fake()->image('source.png', 80, 60)])->assertCreated()->assertJsonPath('data.media.revision', 1);

        return CmsAsset::latest('id')->firstOrFail();
    }

    private function transform(): array
    {
        $out = [];
        foreach (CmsMedia::BREAKPOINTS as $bp) {
            $out[$bp] = ['x' => 0, 'y' => 0, 'width' => 40, 'height' => 40, 'rotate' => 90, 'flip_h' => true, 'flip_v' => false, 'output_width' => 32];
        }

        return $out;
    }

    private function finish(CmsMediaRecipe $r): void
    {
        foreach (CmsMediaDerivative::where('recipe_id', $r->id)->get() as $d) {
            app(CmsMedia::class)->render($d->id);
            $this->assertSame('ready', $d->fresh()->status);
        }
    }

    public function test_upload_preserves_private_bytes_and_rendered_versions_restore_exactly(): void
    {
        $this->asAdmin();
        $file = UploadedFile::fake()->image('source.png', 80, 60);
        $bytes = $file->get();
        $this->postJson('/api/v1/admin/cms/assets', ['image' => $file])->assertCreated();
        $a = CmsAsset::sole();
        $this->assertSame($bytes, Storage::disk('local')->get($a->original_path));
        $this->assertSame(hash('sha256', $bytes), $a->original_hash);
        $this->get('/api/v1/admin/cms/assets/'.$a->public_id.'/original')->assertOk()->assertContent($bytes)->assertHeader('Cache-Control', 'no-store, private');
        $old = CmsMediaRecipe::sole();
        $this->finish($old);
        $before = CmsMediaDerivative::where('recipe_id', $old->id)->pluck('content_hash', 'breakpoint')->all();
        $path = $a->path;
        $compat = Storage::disk('local')->get($path);
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/recipes', ['revision' => 1, 'breakpoints' => $this->transform()])->assertCreated()->assertJsonPath('data.revision', 2);
        $new = CmsMediaRecipe::latest('id')->firstOrFail();
        $this->finish($new);
        $this->assertSame(32, CmsMediaDerivative::where('recipe_id', $new->id)->first()->width);
        $oldUrl = route('api.v1.public.content-assets.derivative', ['id' => $a->public_id, 'recipe' => $old->public_id, 'breakpoint' => 'mobile']);
        $oldBytes = $this->get($oldUrl)->assertOk()->getContent();
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/restore', ['revision' => 2, 'recipe' => $old->public_id])->assertOk()->assertJsonPath('data.active_recipe', $old->public_id)->assertJsonPath('data.revision', 3);
        $this->assertSame($oldBytes, $this->get($oldUrl)->assertOk()->getContent());
        $this->assertSame($before, CmsMediaDerivative::where('recipe_id', $old->id)->pluck('content_hash', 'breakpoint')->all());
        $this->assertSame($compat, Storage::disk('local')->get($path));
        $this->assertDatabaseCount('cms_media_recipes', 2);
        $d = CmsMediaDerivative::where('recipe_id', $old->id)->first();
        $stored = $d->content_hash;
        app(CmsMedia::class)->render($d->id);
        $this->assertSame($stored, $d->fresh()->content_hash);
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/restore', ['revision' => 2, 'recipe' => $old->public_id])->assertConflict();
    }

    public function test_missing_original_worker_failure_and_partial_restore_preserve_compatibility(): void
    {
        $a = $this->upload();
        $originalCompat = Storage::disk('local')->get($a->path);
        Storage::disk('local')->delete($a->original_path);
        $r = CmsMediaRecipe::sole();
        $d = CmsMediaDerivative::where('recipe_id', $r->id)->first();
        app(CmsMedia::class)->render($d->id);
        $this->assertSame('failed', $d->fresh()->status);
        $this->get('/api/v1/public/content-assets/'.$a->public_id)->assertOk();
        $this->assertSame($originalCompat, Storage::disk('local')->get($a->path));
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/restore', ['revision' => 1, 'recipe' => $r->public_id])->assertConflict();
        $this->get('/api/v1/public/content-assets/'.$a->public_id.'/recipes/'.$r->public_id.'/mobile')->assertNotFound();
    }

    public function test_invalid_crop_revision_and_foreign_recipe_are_rejected(): void
    {
        $a = $this->upload();
        $b = $this->upload();
        $bad = $this->transform();
        $bad['mobile']['x'] = 79;
        $url = '/api/v1/admin/cms/assets/'.$a->public_id;
        $this->postJson($url.'/recipes', ['revision' => 1, 'breakpoints' => $bad])->assertUnprocessable();
        $this->postJson($url.'/recipes', ['revision' => 0, 'breakpoints' => $this->transform()])->assertConflict();
        $r = CmsMediaRecipe::where('asset_id', $b->id)->first();
        $this->postJson($url.'/restore', ['revision' => 1, 'recipe' => $r->public_id])->assertNotFound();
        $this->assertDatabaseCount('cms_media_recipes', 2);
    }

    public function test_revoked_authority_and_tampered_artifacts_cannot_publish_or_restore(): void
    {
        $a = $this->upload();
        $r = CmsMediaRecipe::sole();
        $this->finish($r);
        $d = CmsMediaDerivative::where('recipe_id', $r->id)->first();
        Storage::disk('local')->put($d->path, 'tampered');
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/restore', ['revision' => 1, 'recipe' => $r->public_id])->assertConflict();
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/recipes', ['revision' => 1, 'breakpoints' => $this->transform()])->assertCreated();
        $new = CmsMediaRecipe::latest('id')->first();
        $this->admin->platformRoles()->delete();
        $d = CmsMediaDerivative::where('recipe_id', $new->id)->first();
        app(CmsMedia::class)->render($d->id);
        $this->assertSame('failed', $d->fresh()->status);
        $this->getJson('/api/v1/admin/cms/assets/'.$a->public_id)->assertForbidden();
    }

    public function test_private_originals_and_mutations_require_admin_and_session_step_up(): void
    {
        $this->getJson('/api/v1/admin/cms/assets')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/cms/assets')->assertForbidden();
        $this->postJson('/api/v1/admin/cms/assets/unknown/recipes', ['revision' => 0, 'breakpoints' => $this->transform()])->assertForbidden();
    }

    public function test_legacy_asset_is_not_rewritten_or_misrepresented_as_a_pristine_original(): void
    {
        $this->asAdmin();
        $file = UploadedFile::fake()->image('legacy.png', 80, 60);
        Storage::disk('local')->put('legacy.png', $file->get());
        $a = CmsAsset::create(['path' => 'legacy.png', 'mime' => 'image/png', 'width' => 80, 'height' => 60, 'uploaded_by' => $this->admin->id]);
        $this->getJson('/api/v1/admin/cms/assets/'.$a->public_id)->assertOk()->assertJsonPath('data.original_available', false);
        $this->postJson('/api/v1/admin/cms/assets/'.$a->public_id.'/recipes', ['revision' => 0, 'breakpoints' => $this->transform()])->assertConflict();
        $this->get('/api/v1/public/content-assets/'.$a->public_id)->assertOk();
    }

    public function test_upload_storage_failure_leaves_no_asset_or_private_source(): void
    {
        $this->asAdmin();
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()->andReturnFalse();
        $mock->shouldReceive('delete')->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->postJson('/api/v1/admin/cms/assets', ['image' => UploadedFile::fake()->image('source.png', 80, 60)])->assertStatus(503)->assertJsonPath('error.code', 'storage_unavailable');
        $this->assertDatabaseCount('cms_assets', 0);
        $this->assertDatabaseCount('cms_media_recipes', 0);
        Bus::assertNotDispatched(RenderCmsDerivative::class);
    }

    public function test_original_checksum_corruption_blocks_download_and_generation(): void
    {
        $a = $this->upload();
        Bus::assertDispatchedTimes(RenderCmsDerivative::class, 3);
        Storage::disk('local')->put($a->original_path, 'corrupt');
        $this->getJson('/api/v1/admin/cms/assets/'.$a->public_id.'/original')->assertConflict();
        $d = CmsMediaDerivative::firstOrFail();
        app(CmsMedia::class)->render($d->id);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertNull($d->fresh()->path);
    }
}
