<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use App\Services\Admin\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * About Us landing CMS facade (key=over-ons, type=landing).
 */
class AboutUsCmsService
{
    public function __construct(
        private readonly CmsService $cms,
        private readonly AboutUsSchema $schema,
        private readonly PlatformSettings $platformSettings,
    ) {}

    public function page(): CmsPage
    {
        return $this->cms->ensureAboutUs();
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

        return CmsCache::remember("over-ons:{$locale}", function () use ($locale) {
            $page = CmsPage::where('key', 'over-ons')->where('type', 'landing')->whereNull('archived_at')->where('audience', 'public')->first();
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
                'content' => $this->resolveAssets($this->enrichContact($this->enrichCommissionSplit($content))),
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
            'draft' => $l->draft_content === null ? null : $this->enrichContact($this->enrichCommissionSplit($l->draft_content)),
            'published' => $published === null ? null : $this->enrichContact($this->enrichCommissionSplit($published->content)),
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
        if (! in_array($sectionKey, AboutUsSchema::SECTION_KEYS, true) && ! in_array($sectionKey, $meta, true)) {
            throw ValidationException::withMessages(['section' => 'Unknown About Us section.']);
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
     * @return list<array{field: string, suggestion: string}>
     */
    public function aiSuggest(string $locale, string $sectionKey, ?string $focus = null): array
    {
        if (! config('shopbonus.cms.ai_suggest_enabled') || empty(config('shopbonus.cms.ai_api_key'))) {
            throw new ApiException('About Us AI suggestions are not configured.', 503, 'about_us_ai_not_configured');
        }
        if (! in_array($sectionKey, AboutUsSchema::SECTION_KEYS, true)) {
            throw ValidationException::withMessages(['section' => 'Unknown About Us section.']);
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
                        'content' => 'Suggest short ShopBonus About Us copy in '.$locale.'. Plain text only, no HTML. Never invent founding dates, founders, team members, KvK/registration numbers, addresses, statistics, awards, partnerships, or financial performance. Never claim cashback as a percentage of purchase amount. Suggestions only — do not say they are live or published.',
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
            throw new ApiException('About Us AI suggestion failed.', 503, 'about_us_ai_failed');
        }
        $text = (string) data_get($response->json(), 'choices.0.message.content', '');
        $json = json_decode(trim($text), true);
        if (! is_array($json)) {
            throw new ApiException('About Us AI suggestion failed.', 503, 'about_us_ai_failed');
        }
        $out = [];
        foreach ($json as $row) {
            if (! is_array($row) || ! is_string($row['field'] ?? null) || ! is_string($row['suggestion'] ?? null)) {
                continue;
            }
            if (preg_match('/[<>]/', $row['suggestion']) === 1 || preg_match('/\b(uitbetalen|opnemen|withdraw|guaranteed)\b/iu', $row['suggestion']) === 1) {
                continue;
            }
            if (preg_match('/\b\d+\s*%\s*(cashback\s+)?(op|van)\s+(de\s+)?(aankoop|purchase|order)\b/iu', $row['suggestion']) === 1) {
                continue;
            }
            $out[] = ['field' => mb_substr($row['field'], 0, 60), 'suggestion' => mb_substr($row['suggestion'], 0, 300)];
        }

        return array_slice($out, 0, 8);
    }

    /**
     * Inject commission split percentages from config (never treat CMS-stored percents as authoritative).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function enrichCommissionSplit(array $content): array
    {
        $bps = (int) config('shopbonus.affiliate.default_consumer_share_bps', 7000);
        $consumer = (int) round($bps / 100);
        $consumer = max(0, min(100, $consumer));
        $platform = 100 - $consumer;
        if (! isset($content['revenue_model']) || ! is_array($content['revenue_model'])) {
            $content['revenue_model'] = [];
        }
        $content['revenue_model']['consumer_percent'] = $consumer;
        $content['revenue_model']['platform_percent'] = $platform;

        return $content;
    }

    /**
     * Fill empty contact email from published PlatformSettings public.support_email.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function enrichContact(array $content): array
    {
        if (! isset($content['contact']) || ! is_array($content['contact'])) {
            return $content;
        }
        $email = $content['contact']['email'] ?? '';
        if (is_string($email) && trim($email) !== '') {
            return $content;
        }
        $published = $this->platformSettings->published(true);
        $support = $published['values']['public.support_email'] ?? null;
        if (is_string($support) && $support !== '') {
            $content['contact']['email'] = $support;
        }

        return $content;
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
        if (isset($content['hero']['image_asset_id'])) {
            $content['hero']['image_url'] = $url($content['hero']['image_asset_id']);
        }
        if (isset($content['story']['image_asset_id'])) {
            $content['story']['image_url'] = $url($content['story']['image_asset_id']);
        }
        if (isset($content['team']['image_asset_id'])) {
            $content['team']['image_url'] = $url($content['team']['image_asset_id']);
        }
        if (isset($content['team']['profiles']) && is_array($content['team']['profiles'])) {
            $content['team']['profiles'] = array_map(function (array $profile) use ($url) {
                if (array_key_exists('photo_asset_id', $profile)) {
                    $profile['photo_url'] = $url($profile['photo_asset_id'] ?? null);
                }

                return $profile;
            }, $content['team']['profiles']);
        }

        return $content;
    }
}
