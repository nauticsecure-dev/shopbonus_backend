<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Jobs\RenderCmsDerivative;
use App\Models\CmsAsset;
use App\Models\CmsMediaDerivative;
use App\Models\CmsMediaRecipe;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CmsMedia
{
    public const BREAKPOINTS = ['mobile', 'tablet', 'desktop'];

    public function __construct(private readonly CmsRaster $raster, private readonly AuditLogger $audit) {}

    public function authorize(User $actor): void
    {
        $u = User::find($actor->id);
        if (! $u?->isActive() || ! $u->isSuperAdmin()) {
            throw new ApiException('Current Super Admin access is required.', 403, 'forbidden');
        }
    }

    public function upload(UploadedFile $file, User $actor): CmsAsset
    {
        $this->authorize($actor);
        $bytes = $file->get();
        $info = $this->raster->inspect($bytes);
        $compat = $this->raster->encode($bytes, $info['mime']);
        $extension = match ($info['mime']) {
            'image/jpeg' => 'jpg','image/png' => 'png',default => 'webp'
        };
        $prefix = 'cms/'.Str::lower((string) Str::ulid());
        $original = $prefix.'/original.'.$extension;
        $path = $prefix.'/display.'.$extension;
        $committed = false;
        try {
            if (! Storage::disk('local')->put($original, $bytes) || ! Storage::disk('local')->put($path, $compat['bytes'])) {
                throw new ApiException('Private media storage is unavailable.', 503, 'storage_unavailable');
            }
            $asset = DB::transaction(function () use ($actor, $info, $original, $path, $bytes) {
                $this->authorize($actor);
                $a = CmsAsset::create(['path' => $path, 'mime' => $info['mime'], 'width' => $info['width'], 'height' => $info['height'], 'uploaded_by' => $actor->id,
                    'original_path' => $original, 'original_hash' => hash('sha256', $bytes), 'original_mime' => $info['mime'], 'media_revision' => 0]);
                $breakpoints = [];
                foreach (['mobile' => 480, 'tablet' => 960, 'desktop' => 1536] as $bp => $width) {
                    $breakpoints[$bp] = ['x' => 0, 'y' => 0, 'width' => $a->width, 'height' => $a->height, 'rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'output_width' => $width];
                }
                $r = $this->createLocked($a, $breakpoints, $actor);
                $this->audit->record('cms.asset_uploaded', category: AuditCategory::Admin, resource: $a, actorUserId: $actor->id, metadata: ['original_hash' => $a->original_hash, 'recipe' => $r->public_id]);

                return $a;
            });
            $committed = true;
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ApiException('Media could not be persisted.', 503, 'storage_unavailable');
        } finally {
            if (! $committed) {
                try {
                    Storage::disk('local')->delete([$original, $path]);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
        $this->dispatch($asset->active_recipe_id);

        return $asset->refresh();
    }

    /** @param array<string,mixed> $breakpoints */
    public function create(CmsAsset $asset, int $revision, array $breakpoints, User $actor): CmsAsset
    {
        $this->authorize($actor);
        $asset = DB::transaction(function () use ($asset, $revision, $breakpoints, $actor) {
            $a = CmsAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $this->revision($a, $revision);
            if ($a->archived_at !== null) {
                throw ApiException::conflict('Reactivate media before creating recipes.', 'media_archived');
            }
            $this->original($a);
            $recipe = $this->validated($a, $breakpoints);
            $r = $this->createLocked($a, $recipe, $actor);
            $this->audit->record('cms.media_recipe_created', category: AuditCategory::Admin, resource: $a, actorUserId: $actor->id, metadata: ['recipe' => $r->public_id, 'revision' => $a->media_revision]);

            return $a;
        });
        $this->dispatch($asset->active_recipe_id);

        return $asset->refresh();
    }

    public function restore(CmsAsset $asset, CmsMediaRecipe $recipe, int $revision, User $actor): CmsAsset
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($asset, $recipe, $revision, $actor) {
            $a = CmsAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $this->revision($a, $revision);
            $this->original($a);
            if ($recipe->asset_id !== $a->id || $recipe->original_hash !== $a->original_hash) {
                throw new ApiException('Recipe not found.', 404, 'not_found');
            }
            $rows = CmsMediaDerivative::where('recipe_id', $recipe->id)->get();
            if ($rows->count() !== 3 || $rows->contains(fn ($d) => $d->status !== 'ready')) {
                throw ApiException::conflict('Only complete existing artifacts can be restored exactly.', 'media_recipe_not_ready');
            }
            foreach ($rows as $d) {
                if ($d->path === null) {
                    throw ApiException::conflict('Artifact path is unavailable.', 'media_integrity_failed');
                }
                $bytes = Storage::disk('local')->get($d->path);
                if (! is_string($bytes) || ! is_string($d->content_hash) || ! hash_equals($d->content_hash, hash('sha256', $bytes))) {
                    throw ApiException::conflict('An immutable artifact is missing or changed.', 'media_integrity_failed');
                }
            }
            $a->forceFill(['active_recipe_id' => $recipe->id, 'media_revision' => $a->media_revision + 1])->save();
            $this->audit->record('cms.media_restored', category: AuditCategory::Admin, resource: $a, actorUserId: $actor->id, metadata: ['recipe' => $recipe->public_id, 'revision' => $a->media_revision]);

            return $a;
        });
    }

    public function original(CmsAsset $asset): string
    {
        if ($asset->original_path === null || $asset->original_hash === null) {
            throw ApiException::conflict('Legacy assets require a new original upload; historical public files are not rewritten.', 'media_original_unavailable');
        }
        $bytes = Storage::disk('local')->get($asset->original_path);
        if (! is_string($bytes) || strlen($bytes) > 4194304 || ! hash_equals($asset->original_hash, hash('sha256', $bytes))) {
            throw ApiException::conflict('The original is missing or changed.', 'media_integrity_failed');
        }

        return $bytes;
    }

    /** @return array<string,mixed> */
    public function present(CmsAsset $asset): array
    {
        $recipes = CmsMediaRecipe::where('asset_id', $asset->id)->orderByDesc('version')->limit(100)->get();

        return ['id' => $asset->public_id, 'archived' => $asset->archived_at !== null, 'width' => $asset->width, 'height' => $asset->height, 'url' => route('api.v1.public.content-assets.show', ['id' => $asset->public_id]),
            'revision' => $asset->media_revision, 'original_available' => $asset->original_path !== null, 'original_hash' => $asset->original_hash,
            'active_recipe' => $recipes->firstWhere('id', $asset->active_recipe_id)?->public_id,
            'recipes' => $recipes->map(function (CmsMediaRecipe $r) use ($asset) {
                return ['id' => $r->public_id, 'version' => $r->version, 'recipe' => $r->recipe, 'derivatives' => CmsMediaDerivative::where('recipe_id', $r->id)->get()->map(fn ($d) => ['breakpoint' => $d->breakpoint, 'status' => $d->status, 'width' => $d->width, 'height' => $d->height, 'sha256' => $d->content_hash, 'failure_code' => $d->failure_code, 'url' => $d->status === 'ready' ? route('api.v1.public.content-assets.derivative', ['id' => $asset->public_id, 'recipe' => $r->public_id, 'breakpoint' => $d->breakpoint]) : null])->all()];
            })->all()];
    }

    public function render(int $id): void
    {
        if (CmsMediaDerivative::whereKey($id)->where('status', 'queued')->update(['status' => 'processing']) !== 1) {
            return;
        }
        $d = CmsMediaDerivative::findOrFail($id);
        $path = null;
        try {
            $r = CmsMediaRecipe::findOrFail($d->recipe_id);
            $this->authorize(User::findOrFail($r->created_by));
            $a = CmsAsset::findOrFail($r->asset_id);
            if ($r->original_hash !== $a->original_hash || ($r->recipe['renderer'] ?? null) !== 'cms-webp-v1') {
                throw new \RuntimeException('Original binding changed.');
            }
            $all = $this->validated($a, $r->recipe['breakpoints']);
            $source = $this->original($a);
            $render = $this->raster->encode($source, 'image/webp', $all[$d->breakpoint]);
            $path = 'cms/derivatives/'.$r->public_id.'/'.$d->breakpoint.'-'.Str::lower((string) Str::ulid()).'.webp';
            if (! Storage::disk('local')->put($path, $render['bytes'])) {
                throw new \RuntimeException('Storage unavailable.');
            }
            DB::transaction(function () use ($d, $path, $render, $a, $r) {
                $this->authorize(User::findOrFail($r->created_by));
                $locked = CmsMediaDerivative::whereKey($d->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'processing') {
                    throw new \RuntimeException('Render no longer current.');
                }
                $locked->forceFill(['status' => 'ready', 'path' => $path, 'content_hash' => hash('sha256', $render['bytes']), 'width' => $render['width'], 'height' => $render['height'], 'renderer' => ['algorithm' => 'cms-webp-v1', 'php' => PHP_VERSION, 'gd' => GD_VERSION]])->save();
                $this->audit->record('cms.media_rendered', category: AuditCategory::Admin, resource: $a, metadata: ['recipe_id' => $d->recipe_id, 'breakpoint' => $d->breakpoint, 'content_hash' => $locked->content_hash]);
            });
        } catch (Throwable) {
            if ($path !== null) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (Throwable$e) {
                    report($e);
                }
            }
            CmsMediaDerivative::whereKey($id)->where('status', 'processing')->update(['status' => 'failed', 'failure_code' => 'media_render_failed']);
        }
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,array<string,mixed>>
     */
    private function validated(CmsAsset $a, array $value): array
    {
        $rules = ['value' => ['required', 'array:mobile,tablet,desktop']];
        foreach (self::BREAKPOINTS as $bp) {
            $p = 'value.'.$bp;
            $rules[$p] = ['required', 'array:x,y,width,height,rotate,flip_h,flip_v,output_width'];
            foreach (['x', 'y', 'width', 'height', 'output_width'] as $f) {
                $rules[$p.'.'.$f] = ['required', 'integer', 'min:'.(in_array($f, ['x', 'y'], true) ? 0 : 1), 'max:6000'];
            }$rules[$p.'.output_width'][] = 'max:2048';
            $rules[$p.'.rotate'] = ['sometimes', 'integer', 'in:0,90,180,270'];
            $rules[$p.'.flip_h'] = ['sometimes', 'boolean'];
            $rules[$p.'.flip_v'] = ['sometimes', 'boolean'];
        }
        $v = Validator::make(['value' => $value], $rules);
        if ($v->fails()) {
            throw new ApiException('Provide bounded transforms for all three breakpoints.', 422, 'media_recipe_invalid');
        }
        $out = [];
        foreach (self::BREAKPOINTS as $bp) {
            $t = $v->validated()['value'][$bp];
            if ($a->width < $t['x'] + $t['width'] || $a->height < $t['y'] + $t['height']) {
                throw new ApiException('Crop exceeds the preserved original.', 422, 'media_recipe_invalid');
            }$out[$bp] = ['x' => (int) $t['x'], 'y' => (int) $t['y'], 'width' => (int) $t['width'], 'height' => (int) $t['height'], 'rotate' => (int) ($t['rotate'] ?? 0), 'flip_h' => (bool) ($t['flip_h'] ?? false), 'flip_v' => (bool) ($t['flip_v'] ?? false), 'output_width' => (int) $t['output_width']];
        }

        return $out;
    }

    /** @param array<string,mixed> $breakpoints */
    private function createLocked(CmsAsset $a, array $breakpoints, User $actor): CmsMediaRecipe
    {
        if (CmsMediaRecipe::where('asset_id', $a->id)->count() >= 100) {
            throw ApiException::conflict('This asset reached its recipe history limit.', 'media_history_limit');
        }
        $r = CmsMediaRecipe::create(['asset_id' => $a->id, 'version' => (CmsMediaRecipe::where('asset_id', $a->id)->max('version') ?? 0) + 1, 'original_hash' => $a->original_hash, 'recipe' => ['schema' => 'cms-media-v1', 'renderer' => 'cms-webp-v1', 'orientation' => 'encoded_pixels', 'breakpoints' => $breakpoints], 'created_by' => $actor->id, 'created_at' => now()]);
        foreach (self::BREAKPOINTS as $bp) {
            CmsMediaDerivative::create(['recipe_id' => $r->id, 'breakpoint' => $bp, 'status' => 'queued']);
        }
        $a->forceFill(['active_recipe_id' => $r->id, 'media_revision' => $a->media_revision + 1])->save();

        return $r;
    }

    private function revision(CmsAsset $a, int $revision): void
    {
        if ($a->media_revision !== $revision) {
            throw ApiException::conflict('Media changed; reload its current revision.', 'media_revision_conflict');
        }
    }

    private function dispatch(?int $recipe): void
    {
        foreach (CmsMediaDerivative::where('recipe_id', $recipe)->where('status', 'queued')->get() as $d) {
            try {
                Bus::dispatch(new RenderCmsDerivative($d->id));
            } catch (Throwable) {
                CmsMediaDerivative::whereKey($d->id)->where('status', 'queued')->update(['status' => 'failed', 'failure_code' => 'media_dispatch_failed']);
            }
        }
    }
}
