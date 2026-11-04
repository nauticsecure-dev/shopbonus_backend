<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CmsAsset;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CmsAssetManagement
{
    public function __construct(private readonly CmsMedia $media, private readonly CmsService $cms, private readonly CmsContentBindings $bindings, private readonly AuditLogger $audit) {}

    public function state(CmsAsset $asset, int $revision, bool $archived, string $reason, User $actor): CmsAsset
    {
        return DB::transaction(function () use ($asset, $revision, $archived, $reason, $actor) {
            $a = CmsAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $this->media->authorize($actor);
            if ($a->media_revision !== $revision) {
                throw ApiException::conflict('Media changed; reload its revision.', 'media_revision_conflict');
            }
            if (($a->archived_at !== null) === $archived) {
                return $a;
            }
            $a->forceFill(['archived_at' => $archived ? now() : null, 'archived_by' => $archived ? $actor->id : null, 'media_revision' => $a->media_revision + 1])->save();
            $this->audit->record($archived ? 'cms.media_archived' : 'cms.media_reactivated', category: AuditCategory::Admin, resource: $a, actorUserId: $actor->id, metadata: ['reason' => $reason, 'revision' => $a->media_revision, 'preserves' => 'Existing originals/artifacts/published/history remain unchanged.']);

            return $a;
        }, 3);
    }

    /** @param list<array{page_id:string,locale:string,revision:int,path:string}> $usages
     * @return list<array<string,mixed>>
     */
    public function replace(CmsAsset $from, CmsAsset $to, ?string $recipe, array $usages, User $actor): array
    {
        return DB::transaction(function () use ($from, $to, $recipe, $usages, $actor) {
            $this->media->authorize($actor);
            $groups = [];
            foreach ($usages as $usage) {
                $key = $usage['page_id'].':'.$usage['locale'];
                $groups[$key][] = $usage;
            }
            ksort($groups);
            $locales = [];
            // Lock every selected locale before locking assets, consistent with ordinary draft saves.
            foreach ($groups as $key => $items) {
                $first = $items[0];
                $p = CmsPage::where('public_id', $first['page_id'])->first() ?? throw new ApiException('Page not found.', 404, 'not_found');
                if ($p->archived_at !== null) {
                    throw ApiException::conflict('An archived page is not a replacement target.', 'page_archived');
                }
                $l = CmsPageLocale::where('page_id', $p->id)->where('locale', $first['locale'])->lockForUpdate()->first() ?? throw new ApiException('Translation not found.', 404, 'not_found');
                foreach ($items as $item) {
                    if ($item['revision'] !== $l->draft_revision) {
                        throw ApiException::conflict('A selected draft changed.', 'draft_revision_conflict');
                    }
                }
                $locales[$key] = [$p, $l];
            }
            $targets = CmsAsset::whereIn('id', [$from->id, $to->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (! isset($targets[$to->id]) || $targets[$to->id]->archived_at !== null) {
                throw ApiException::conflict('Choose an active replacement.', 'media_archived');
            }
            $out = [];
            foreach ($groups as $key => $items) {
                [$p,$l] = $locales[$key];
                $content = $l->draft_content ?? [];
                $refs = $this->bindings->references($content);
                $seen = [];
                foreach ($items as $item) {
                    $path = $item['path'];
                    if (isset($seen[$path]) || ($refs[$path] ?? null) !== $from->public_id) {
                        throw ApiException::conflict('A selected usage no longer references this asset.', 'media_usage_changed');
                    }
                    $seen[$path] = true;
                    Arr::set($content, $path, $to->public_id);
                    if ($recipe !== null) {
                        $content['media_recipes'][$path] = $recipe;
                    } else {
                        unset($content['media_recipes'][$path]);
                    }
                }
                $this->media->authorize($actor);
                $saved = $this->cms->saveDraft($p, $l->locale, $content, $l->draft_revision, null, $actor->id);
                $out[] = ['page_id' => $p->public_id, 'locale' => $l->locale, 'draft_revision' => $saved->draft_revision, 'paths' => array_keys($seen)];
            }
            $this->audit->record('cms.media_usages_replaced', category: AuditCategory::Admin, resource: $from, actorUserId: $actor->id, metadata: ['replacement' => $to->public_id, 'recipe' => $recipe, 'selected' => $out, 'scope' => 'Explicit selected page drafts only; no publication or global completeness claim.']);

            return $out;
        }, 3);
    }
}
