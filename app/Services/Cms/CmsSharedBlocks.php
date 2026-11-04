<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsSharedBlock;
use App\Models\CmsSharedBlockVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Materialized immutable block snapshots retain the existing public rich-text contract. */
class CmsSharedBlocks
{
    public function __construct(private readonly CmsMedia $authority, private readonly RichText $richText, private readonly AuditLogger $audit) {}

    /** Object key ordering is not content; list order is. MySQL JSON normalizes object keys.
     * @param  array<string,mixed>  $node
     */
    private static function hash(array $node): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $canonical($child);
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($node), JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $node */
    public function version(?CmsSharedBlock $block, string $key, int $revision, string $locale, array $node, User $actor): CmsSharedBlockVersion
    {
        $this->authority->authorize($actor);
        Validator::make(['key' => $key, 'locale' => $locale], ['key' => ['required', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9-]*\z/D'], 'locale' => ['required', 'in:nl,en,de']])->validate();
        $doc = $this->richText->validate(['type' => 'doc', 'content' => [$node]], 'node');
        if (strlen(json_encode($doc, JSON_THROW_ON_ERROR)) > 65536 || app(CmsContentBindings::class)->references(['body' => $doc]) !== []) {
            throw ValidationException::withMessages(['node' => 'Reusable text blocks must be at most64KiB and contain no images. Use separately managed page media.']);
        }

        return DB::transaction(function () use ($block, $key, $revision, $locale, $node, $actor) {
            $this->authority->authorize($actor);
            if ($block === null) {
                $b = CmsSharedBlock::createOrFirst(['key' => $key], ['revision' => 1, 'created_by' => $actor->id]);
                if (! $b->wasRecentlyCreated) {
                    throw ApiException::conflict('A shared block with this key already exists.', 'shared_block_key_conflict');
                }
            } else {
                $b = CmsSharedBlock::whereKey($block->id)->lockForUpdate()->firstOrFail();
                if ($b->revision !== $revision) {
                    throw ApiException::conflict('Shared block changed; reload its revision.', 'shared_block_revision_conflict');
                }
                $b->forceFill(['revision' => $b->revision + 1])->save();
            }
            $v = CmsSharedBlockVersion::create(['block_id' => $b->id, 'number' => $b->revision, 'locale' => $locale, 'node' => $node, 'content_hash' => self::hash($node), 'created_by' => $actor->id]);
            $this->audit->record('cms.shared_block_version_created', category: AuditCategory::Admin, resource: $b, actorUserId: $actor->id, metadata: ['version_id' => $v->public_id, 'locale' => $locale, 'sha256' => $v->content_hash, 'propagation' => 'none']);

            return $v;
        }, 3);
    }

    /** @param array<string,mixed> $node
     * @param  array<string,string>  $overrides
     * @return array<string,mixed>
     */
    private function render(array $node, array $overrides): array
    {
        $bindings = CmsTextBindings::discover(['body' => ['type' => 'doc', 'content' => [$node]]]);
        $allowed = [];
        foreach ($bindings as $binding) {
            $allowed[substr($binding['path'], strlen('body.content.0.'))] = $binding['max_length'];
        }
        if (count($overrides) > 100) {
            throw ValidationException::withMessages(['overrides' => 'At most100 text overrides.']);
        }
        Validator::make(['values' => array_values($overrides)], ['values.*' => ['required', 'string']])->validate();
        foreach ($overrides as $path => $value) {
            if (! isset($allowed[$path]) || trim($value) === '' || mb_strlen($value) > $allowed[$path] || preg_match('/[<>]/u', $value)) {
                throw ValidationException::withMessages(['overrides' => 'Override existing text or button labels only, within their original limits.']);
            }
            Arr::set($node, $path, $value);
        }
        $this->richText->validate(['type' => 'doc', 'content' => [$node]], 'node');

        return $node;
    }

    /** @param array<string,mixed> $content
     * @return array<string,array<string,mixed>>
     */
    public function validatePins(array $content, mixed $pins, ?string $locale = null): array
    {
        if (! is_array($pins) || count($pins) > 20) {
            throw ValidationException::withMessages(['content.shared_blocks' => 'Use at most20 root rich-text block pins.']);
        }
        if ($pins !== [] && ! array_is_list($content['body']['content'] ?? [])) {
            throw ValidationException::withMessages(['content.shared_blocks' => 'Shared usages require an ordered root body list.']);
        }
        $out = [];
        foreach ($pins as $path => $pin) {
            if (! is_string($path) || ! preg_match('/\Abody\.content\.(0|[1-9][0-9]{0,3})\z/D', $path) || ! is_array($pin)) {
                throw ValidationException::withMessages(['content.shared_blocks' => 'Pin an existing root body block.']);
            }
            $d = Validator::make($pin, ['version_id' => ['required', 'string', 'ulid'], 'overrides' => ['sometimes', 'array']])->validate();
            if (array_diff(array_keys($pin), ['version_id', 'overrides']) !== []) {
                throw ValidationException::withMessages(['content.shared_blocks' => 'Unknown pin fields.']);
            }
            $version = CmsSharedBlockVersion::where('public_id', $d['version_id'])->first();
            if ($version === null || ($locale !== null && $version->locale !== $locale) || ! hash_equals($version->content_hash, self::hash($version->node))) {
                throw ValidationException::withMessages(['content.shared_blocks' => 'Choose an intact immutable version in this page locale.']);
            }
            $rendered = $this->render($version->node, $d['overrides'] ?? []);
            $actual = Arr::get($content, $path);
            if (! is_array($actual) || ! hash_equals(self::hash($rendered), self::hash($actual))) {
                throw ValidationException::withMessages(['content.shared_blocks' => 'Pinned body differs from its version/overrides. Update overrides or detach before editing.']);
            }
            $out[$path] = ['version_id' => $version->public_id, 'overrides' => $d['overrides'] ?? []];
        }
        ksort($out);

        return $out;
    }

    /** @param array<string,string> $overrides */
    public function mount(CmsPage $page, string $locale, int $revision, ?CmsSharedBlockVersion $version, ?int $index, array $overrides, User $actor): CmsPageLocale
    {
        return DB::transaction(function () use ($page, $locale, $revision, $version, $index, $overrides, $actor) {
            $this->authority->authorize($actor);
            if (($index !== null && ($index < 0 || $index > 1999)) || ($version === null && $index === null)) {
                throw ValidationException::withMessages(['index' => 'Select an existing root block index.']);
            }
            if ($page->archived_at !== null || in_array($page->type, CmsPage::LEGAL, true)) {
                throw ApiException::conflict('This page cannot mount shared text.', 'shared_block_page_unavailable');
            }
            $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->lockForUpdate()->first() ?? throw new ApiException('Translation not found.', 404, 'not_found');
            if ($l->draft_revision !== $revision) {
                throw ApiException::conflict('Draft changed; reload its revision.', 'draft_revision_conflict');
            }
            $content = $l->draft_content ?? [];
            $nodes = $content['body']['content'] ?? [];
            if (! array_is_list($nodes)) {
                throw ValidationException::withMessages(['content.body' => 'Use an ordered root body list.']);
            }
            if ($version === null) {
                $path = 'body.content.'.$index;
                if (! isset($content['shared_blocks'][$path])) {
                    throw ApiException::conflict('There is no pinned block at this index.', 'shared_block_usage_changed');
                }
                unset($content['shared_blocks'][$path]);
            } else {
                if ($version->locale !== $locale) {
                    throw ValidationException::withMessages(['version_id' => 'Choose a version in the exact page locale.']);
                }
                $target = $index ?? count($nodes);
                if ($target > count($nodes)) {
                    throw ValidationException::withMessages(['index' => 'Use an existing root block index or append.']);
                }
                $content['body']['content'][$target] = $this->render($version->node, $overrides);
                $content['shared_blocks']['body.content.'.$target] = ['version_id' => $version->public_id, 'overrides' => $overrides];
            }
            $saved = app(CmsService::class)->saveDraft($page, $locale, $content, $revision, null, $actor->id, $version === null);
            $this->audit->record($version === null ? 'cms.shared_block_detached' : 'cms.shared_block_mounted', category: AuditCategory::Admin, resource: $page, actorUserId: $actor->id, metadata: ['locale' => $locale, 'version_id' => $version?->public_id, 'index' => $index, 'draft_revision' => $saved->draft_revision, 'publication' => 'unchanged']);

            return $saved;
        }, 3);
    }

    /** @return array<string,mixed> */
    public function present(CmsSharedBlockVersion $version): array
    {
        $block = CmsSharedBlock::findOrFail($version->block_id);

        return ['id' => $version->public_id, 'block_id' => $block->public_id, 'key' => $block->key, 'block_revision' => $block->revision, 'number' => $version->number, 'locale' => $version->locale, 'node' => $version->node, 'sha256' => $version->content_hash, 'created_by' => User::find($version->created_by)?->public_id, 'created_at' => $version->created_at->toIso8601String(), 'scope' => 'platform_global', 'propagation' => 'explicit_draft_mount_only'];
    }

    /** @param array<string,mixed> $content
     * @return list<array<string,mixed>>
     */
    public function usages(array $content): array
    {
        $out = [];
        foreach ($content['shared_blocks'] ?? [] as $path => $pin) {
            $v = CmsSharedBlockVersion::where('public_id', $pin['version_id'])->first();
            $source = $v === null ? null : $this->present($v);
            if ($source !== null) {
                unset($source['block_revision']);
            }
            $out[] = ['path' => $path, 'source' => $source, 'overrides' => $pin['overrides'], 'resolved_node' => Arr::get($content, $path)];
        }

        return $out;
    }
}
