<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Consumer homepage CMS facade over CmsService (key=homepage, type=landing).
 */
class HomepageCmsService
{
    public function __construct(
        private readonly CmsService $cms,
        private readonly HomepageSchema $schema,
    ) {}

    public function page(): CmsPage
    {
        return $this->cms->ensureHomepage();
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
     * Published homepage for a locale, or null when unpublished / outside schedule.
     *
     * @return array<string, mixed>|null
     */
    public function published(string $locale): ?array
    {
        if (! in_array($locale, CmsService::LOCALES, true)) {
            return null;
        }

        return CmsCache::remember("homepage:{$locale}", function () use ($locale) {
            $page = CmsPage::where('key', 'homepage')->whereNull('archived_at')->where('audience', 'public')->first();
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
            if (! $this->scheduleAllows($content['schedule'] ?? null)) {
                return null;
            }

            return [
                'key' => $page->key,
                'type' => $page->type,
                'locale' => $locale,
                'slug' => $l->slug,
                'content' => $this->resolveAssets($content),
                'revision' => $r->number,
                'published_at' => $r->created_at->toIso8601String(),
            ];
        });
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
            'draft' => $l->draft_content,
            'published' => $published?->content,
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
        if (! in_array($sectionKey, HomepageSchema::SECTION_KEYS, true) && ! in_array($sectionKey, $meta, true)) {
            throw ValidationException::withMessages(['section' => 'Unknown homepage section.']);
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
     * AI copy suggestions only — never applied. Disabled by default.
     *
     * @return list<array{field: string, suggestion: string}>
     */
    public function aiSuggest(string $locale, string $sectionKey, ?string $focus = null): array
    {
        if (! config('shopbonus.cms.ai_suggest_enabled') || empty(config('shopbonus.cms.ai_api_key'))) {
            throw new ApiException('Homepage AI suggestions are not configured.', 503, 'homepage_ai_not_configured');
        }
        if (! in_array($sectionKey, HomepageSchema::SECTION_KEYS, true)) {
            throw ValidationException::withMessages(['section' => 'Unknown homepage section.']);
        }
        $response = Http::withToken((string) config('shopbonus.cms.ai_api_key'))
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout(20)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('shopbonus.cms.ai_model'),
                'temperature' => 0.4,
                'max_tokens' => 400,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Suggest short ShopBonus homepage copy in '.$locale.'. Plain text only, no HTML. Never invent cashback percentages, wallet balances, payouts, withdrawal claims, or financial guarantees. Suggestions only — do not say they are live.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'section' => $sectionKey,
                            'focus' => $focus,
                            'format' => 'JSON array of {field, suggestion}',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);
        if (! $response->successful()) {
            throw new ApiException('Homepage AI suggestion failed.', 503, 'homepage_ai_failed');
        }
        $text = (string) data_get($response->json(), 'choices.0.message.content', '');
        $json = json_decode(trim($text), true);
        if (! is_array($json)) {
            throw new ApiException('Homepage AI suggestion failed.', 503, 'homepage_ai_failed');
        }
        $out = [];
        foreach ($json as $row) {
            if (! is_array($row) || ! is_string($row['field'] ?? null) || ! is_string($row['suggestion'] ?? null)) {
                continue;
            }
            if (preg_match('/[<>]/', $row['suggestion']) === 1 || preg_match('/\b(uitbetalen|opnemen|withdraw)\b/iu', $row['suggestion']) === 1) {
                continue;
            }
            $out[] = ['field' => mb_substr($row['field'], 0, 60), 'suggestion' => mb_substr($row['suggestion'], 0, 300)];
        }

        return array_slice($out, 0, 8);
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
        if (isset($content['seo_image_asset_id'])) {
            $content['seo_image_url'] = $url($content['seo_image_asset_id']);
        }
        if (isset($content['hero']['background_asset_id'])) {
            $content['hero']['background_url'] = $url($content['hero']['background_asset_id']);
        }
        if (isset($content['body']) && is_array($content['body'])) {
            $walk = function (array $node) use (&$walk): array {
                if (($node['type'] ?? null) === 'image' && isset($node['attrs']['asset_id'])) {
                    $node['attrs']['src'] = route('api.v1.public.content-assets.show', ['id' => $node['attrs']['asset_id']]);
                }
                if (isset($node['content']) && is_array($node['content'])) {
                    $node['content'] = array_map(fn ($c) => is_array($c) ? $walk($c) : $c, $node['content']);
                }

                return $node;
            };
            $content['body'] = $walk($content['body']);
        }

        return $content;
    }
}
