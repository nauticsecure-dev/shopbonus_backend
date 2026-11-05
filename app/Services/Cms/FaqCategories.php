<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\FaqCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Two-level global FAQ hierarchy. Item audience/publication guards remain independent. */
class FaqCategories
{
    public function __construct(private readonly CmsMedia $authority, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function save(?FaqCategory $category, array $data, User $actor): FaqCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $this->authority->authorize($actor);
            $parentId = $data['parent_id'] ?? null;
            $parent = $parentId === null ? null : FaqCategory::where('public_id', $parentId)->first();
            if ($parentId !== null && $parent === null) {
                throw new ApiException('Category not found.', 404, 'not_found');
            }
            $ids = array_filter([$category?->id, $parent?->id]);
            sort($ids);
            $locked = FaqCategory::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $category === null ? null : ($locked[$category->id] ?? throw new ApiException('Category not found.', 404, 'not_found'));
            if ($current !== null && $current->revision !== $data['revision']) {
                throw ApiException::conflict('FAQ category changed.', 'faq_category_changed');
            }
            if ($current !== null && ! array_key_exists('parent_id', $data)) {
                $parent = $current->parent_id === null ? null : FaqCategory::find($current->parent_id);
            } elseif ($parent !== null) {
                $parent = $locked[$parent->id];
            }
            if ($parent !== null && ($parent->parent_id !== null || $parent->id === $current?->id)) {
                throw new ApiException('Use a root parent; FAQ nesting is limited to two levels.', 422, 'faq_parent_invalid');
            }
            if ($parent !== null && $current !== null && FaqCategory::where('parent_id', $current->id)->exists()) {
                throw ApiException::conflict('A parent with children cannot become a subcategory.', 'faq_category_has_children');
            }
            $before = $current === null ? null : $this->present($current);
            $fields = array_intersect_key($data, array_flip(['key', 'names', 'position']));
            $fields['parent_id'] = $parent?->id;
            if ($current === null) {
                $current = FaqCategory::create($fields + ['position' => (int) FaqCategory::max('position') + 1]);
            } else {
                $current->forceFill($fields + ['revision' => $current->revision + 1])->save();
            }
            $this->audit->record($category === null ? 'cms.faq_category_created' : 'cms.faq_category_updated', category: AuditCategory::Admin, resource: $current, actorUserId: $actor->id, metadata: ['revision' => $current->revision, 'parent' => $parent?->public_id, 'before' => $before, 'after' => $this->present($current)]);
            CmsCache::flush();

            return $current;
        }, 3);
    }

    /** @return array<string,mixed> */
    public function present(FaqCategory $c): array
    {
        return ['id' => $c->public_id, 'key' => $c->key, 'names' => $c->names, 'position' => $c->position, 'revision' => $c->revision, 'parent_id' => $c->parent_id === null ? null : FaqCategory::find($c->parent_id)?->public_id];
    }
}
