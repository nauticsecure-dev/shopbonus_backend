<?php

namespace App\Services\Support;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\SupportKnowledgeVersion;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\CmsCache;
use Illuminate\Support\Facades\DB;

final class SupportKnowledge
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(FaqCategory $category, array $data, User $actor): FaqItem
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $item = FaqItem::create(['category_id' => $category->id, 'audience' => $data['audience'] ?? 'public', 'published' => (bool) ($data['published'] ?? false),
                'translations' => $data['translations'], 'position' => (int) FaqItem::where('category_id', $category->id)->max('position') + 1]);
            $this->capture($item, $actor);
            $this->audit->record('cms.faq_created', category: AuditCategory::Admin, resource: $item, actorUserId: $actor->id);
            CmsCache::flush();

            return $item;
        });
    }

    /** @param array<string, mixed> $changes */
    public function update(FaqItem $item, array $changes, int $revision, User $actor, ?SupportKnowledgeVersion $rollback = null, ?string $reason = null): FaqItem
    {
        return DB::transaction(function () use ($item, $changes, $revision, $actor, $rollback, $reason) {
            $item = FaqItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->revision !== $revision) {
                throw ApiException::conflict('Someone else changed this item. Reload to continue.', 'draft_revision_conflict');
            }
            $this->capture($item, null, 'backfill');
            foreach (['translations', 'audience', 'published'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $item->setAttribute($field, $changes[$field]);
                }
            }
            $item->revision++;
            $item->save();
            $this->capture($item, $actor, $rollback === null ? null : 'rollback');
            $this->audit->record($rollback === null ? 'cms.faq_updated' : 'support.knowledge_rolled_back', category: AuditCategory::Admin, resource: $item, actorUserId: $actor->id,
                metadata: ['revision' => $item->revision, 'rollback_of' => $rollback?->public_id, 'reason' => $reason]);
            CmsCache::flush();

            return $item;
        }, 3);
    }

    public function rollback(FaqItem $item, SupportKnowledgeVersion $version, int $revision, User $actor, string $reason): FaqItem
    {
        if ($version->faq_id !== $item->id || ! ($version->snapshot['published'] ?? false)) {
            throw ApiException::conflict('Choose a published version of this knowledge item.', 'support_knowledge_not_published');
        }
        if (! hash_equals($version->checksum, PlatformSettings::checksum($version->snapshot))) {
            throw ApiException::conflict('Knowledge integrity verification failed.', 'support_knowledge_integrity_failed');
        }

        return $this->update($item, $version->snapshot, $revision, $actor, $version, $reason);
    }

    private function capture(FaqItem $item, ?User $actor, ?string $origin = null): void
    {
        $snapshot = ['audience' => $item->audience, 'published' => $item->published, 'translations' => $item->translations];
        SupportKnowledgeVersion::firstOrCreate(['faq_id' => $item->id, 'revision' => $item->revision], ['snapshot' => $snapshot, 'checksum' => PlatformSettings::checksum($snapshot),
            'recorded_by' => $actor?->id, 'origin' => $origin ?? ($item->published ? 'published' : 'draft'), 'created_at' => now()]);
    }
}
