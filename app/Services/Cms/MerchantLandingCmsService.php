<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Integrations\ConnectorRegistry;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Webshop-owner landing CMS facade (key=voor-webshops, type=landing).
 */
class MerchantLandingCmsService
{
    public function __construct(
        private readonly CmsService $cms,
        private readonly MerchantLandingSchema $schema,
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function page(): CmsPage
    {
        return $this->cms->ensureMerchantLanding();
    }

    public function localeOrFail(CmsPage $page, string $locale): CmsPageLocale
    {
        if (! in_array($locale, CmsService::LOCALES, true)) {
            throw new ApiException('Unsupported locale.', 404, 'not_found');
        }

        return CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->first()
            ?? throw new ApiException('This translation does not exist.', 404, 'not_found');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function published(string $locale): ?array
    {
        if (! in_array($locale, CmsService::LOCALES, true)) {
            return null;
        }

        $published = CmsCache::remember("voor-webshops:{$locale}", function () use ($locale) {
            $page = CmsPage::where('key', 'voor-webshops')->whereNull('archived_at')->where('audience', 'public')->first();
            if ($page === null) {
                return null;
            }
            $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->whereNotNull('published_revision_id')->first();
            if ($l === null) {
                return null;
            }
            $r = CmsRevision::find($l->published_revision_id);
            if ($r === null) {
                return null;
            }
            $content = $r->content;

            return [
                'key' => $page->key,
                'type' => $page->type,
                'locale' => $locale,
                'slug' => $l->slug,
                'content' => $this->enrichPlatforms($this->resolveAssets($content)),
                'revision' => $r->number,
                'published_at' => $r->created_at->toIso8601String(),
            ];
        });

        return $published !== null && $this->scheduleAllows($published['content']['schedule'] ?? null) ? $published : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function adminState(string $locale): array
    {
        $page = $this->page();
        $l = $this->localeOrFail($page, $locale);
        $published = $l->published_revision_id === null ? null : CmsRevision::find($l->published_revision_id);

        return [
            'id' => $page->public_id,
            'key' => $page->key,
            'type' => $page->type,
            'locale' => $locale,
            'slug' => $l->slug,
            'draft_revision' => $l->draft_revision,
            'draft' => $l->draft_content === null ? null : $this->enrichPlatforms($l->draft_content),
            'published' => $published === null ? null : $this->enrichPlatforms($published->content),
            'published_revision' => $published === null ? null : $published->number,
            'published_at' => $published?->created_at?->toIso8601String(),
            'draft_updated_at' => $l->draft_updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function saveSection(CmsPage $page, string $locale, string $sectionKey, array $section, int $revision, int $actorId): CmsPageLocale
    {
        $meta = ['nav', 'footer', 'section_order', 'section_visibility', 'schedule'];
        if (! in_array($sectionKey, MerchantLandingSchema::SECTION_KEYS, true) && ! in_array($sectionKey, $meta, true)) {
            throw ValidationException::withMessages(['section' => 'Unknown merchant landing section.']);
        }
        $l = $this->localeOrFail($page, $locale);
        $draft = $l->draft_content ?? $this->schema->defaults($locale);
        $draft[$sectionKey] = $section;

        return $this->cms->saveDraft($page, $locale, $draft, $revision, null, $actorId);
    }

    /**
     * @param  array{publish_at?: string|null, unpublish_at?: string|null}  $schedule
     */
    public function schedule(CmsPage $page, string $locale, array $schedule, int $revision, int $actorId): CmsPageLocale
    {
        return $this->saveSection($page, $locale, 'schedule', $schedule, $revision, $actorId);
    }

    /**
     * Overlay live connector availability onto CMS tiles so marketing never claims a dead integration is live.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function enrichPlatforms(array $content): array
    {
        if (! isset($content['platforms']['tiles']) || ! is_array($content['platforms']['tiles'])) {
            return $content;
        }
        $catalogue = collect($this->connectors->catalogue())->keyBy('platform');
        $content['platforms']['tiles'] = array_map(function (array $tile) use ($catalogue) {
            $key = (string) ($tile['platform'] ?? '');
            $entry = $catalogue->get($key);
            if ($entry === null) {
                $tile['status'] = 'COMING_SOON';
                $tile['connectable'] = false;

                return $tile;
            }
            $tile['status'] = $this->mapStatus($entry);
            $tile['connectable'] = (bool) ($entry['available'] ?? false) && empty($entry['blocked_reason']);
            $tile['blocked_reason'] = $entry['blocked_reason'] ?? null;

            return $tile;
        }, $content['platforms']['tiles']);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function mapStatus(array $entry): string
    {
        if (! ($entry['available'] ?? false) || ! empty($entry['blocked_reason'])) {
            return 'COMING_SOON';
        }
        $method = (string) ($entry['method'] ?? '');
        if ($method === 'private_module') {
            return 'MANUAL_SETUP';
        }
        if (($entry['priority'] ?? '') === 'production') {
            return 'AVAILABLE';
        }

        return 'BETA';
    }

    /** @param  array<string, mixed>|null  $schedule */
    private function scheduleAllows(?array $schedule): bool
    {
        if ($schedule === null) {
            return true;
        }
        $now = CarbonImmutable::now();
        if (! empty($schedule['publish_at']) && $now->lt(CarbonImmutable::parse($schedule['publish_at']))) {
            return false;
        }
        if (! empty($schedule['unpublish_at']) && $now->gte(CarbonImmutable::parse($schedule['unpublish_at']))) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function resolveAssets(array $content): array
    {
        $url = fn (?string $id) => $id === null || $id === '' ? null : route('api.v1.public.content-assets.show', ['id' => $id]);
        foreach ($content['media'] ?? [] as $slot => $media) {
            $content['media'][$slot]['desktop_url'] = $url($media['desktop_asset_id'] ?? null);
            $content['media'][$slot]['mobile_url'] = $url($media['mobile_asset_id'] ?? null);
        }
        if (isset($content['seo_image_asset_id'])) {
            $content['seo_image_url'] = $url($content['seo_image_asset_id']);
        }
        if (isset($content['hero']['background_asset_id'])) {
            $content['hero']['background_url'] = $url($content['hero']['background_asset_id']);
        }
        if (isset($content['hero']['illustration_asset_id'])) {
            $content['hero']['illustration_url'] = $url($content['hero']['illustration_asset_id']);
        }
        if (isset($content['benefits']['image_asset_id'])) {
            $content['benefits']['image_url'] = $url($content['benefits']['image_asset_id']);
        }
        if (isset($content['offer_preview']['preview_asset_id'])) {
            $content['offer_preview']['preview_url'] = $url($content['offer_preview']['preview_asset_id']);
        }

        return $content;
    }
}
