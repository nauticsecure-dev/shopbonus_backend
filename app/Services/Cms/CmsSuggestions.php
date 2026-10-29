<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * "Improve with AI" for CMS pages (Trello #33–#35). The model only rewrites
 * or translates the existing text segments (title, SEO fields, text nodes,
 * button labels, alt texts); structure, links and images are kept as they
 * are. Output may not add numbers, amounts, percentages, links or e-mail
 * addresses that the source did not contain, so it cannot invent offers,
 * statistics or company facts. A suggestion is a private proposal: applying
 * it saves a draft through the normal optimistic-revision path and nothing
 * is ever published automatically.
 */
class CmsSuggestions
{
    private const LIMITS = ['title' => 120, 'seo_title' => 70, 'seo_description' => 160, 'text' => 5000, 'label' => 40, 'alt' => 200];

    public function __construct(private readonly CmsService $cms, private readonly AuditLogger $audit) {}

    public function request(CmsPage $page, string $kind, string $sourceLocale, string $targetLocale, string $key, User $actor): CmsAiSuggestion
    {
        if (! config('cms.ai_enabled') || (string) config('cms.api_key') === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', (string) config('cms.model'))) {
            throw new ApiException('AI suggestions are not configured.', 503, 'cms_ai_not_configured');
        }
        if ($page->archived_at !== null) {
            throw ApiException::conflict('Archived pages cannot be edited.', 'page_archived');
        }
        if (($kind === 'improve') !== ($sourceLocale === $targetLocale)) {
            throw new ApiException('Improve keeps the language; translate needs a different target language.', 422, 'validation_failed');
        }
        $source = CmsPageLocale::where('page_id', $page->id)->where('locale', $sourceLocale)->first();
        if ($source === null || $source->draft_content === null) {
            throw ApiException::conflict('There is no draft in this language to work from.', 'nothing_to_suggest');
        }
        $target = CmsPageLocale::where('page_id', $page->id)->where('locale', $targetLocale)->first();
        $hash = hash('sha256', json_encode([$page->id, $kind, $sourceLocale, $targetLocale, $source->draft_revision], JSON_THROW_ON_ERROR));

        $suggestion = DB::transaction(function () use ($page, $kind, $sourceLocale, $targetLocale, $key, $actor, $hash, $source, $target) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = CmsAiSuggestion::where('created_by', $actor->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw ApiException::conflict('This idempotency key belongs to another request.', 'cms_suggestion_conflict');
                }

                return $existing;
            }
            $day = now()->utc()->toDateString();
            DB::table('cms_ai_quotas')->upsert([['day' => $day, 'requests' => 0]], ['day'], ['day']);
            $quota = DB::table('cms_ai_quotas')->where('day', $day)->lockForUpdate()->first();
            if ($quota->requests >= max(0, min(200, (int) config('cms.daily_limit')))) {
                $retry = max(1, (int) ceil(now()->utc()->diffInSeconds(now()->utc()->addDay()->startOfDay())));
                throw new ApiException('The daily AI suggestion limit is reached.', 429, 'cms_ai_daily_limit', ['retry_after_seconds' => $retry], ['Retry-After' => (string) $retry]);
            }
            DB::table('cms_ai_quotas')->where('day', $day)->increment('requests');

            return CmsAiSuggestion::create(['page_id' => $page->id, 'kind' => $kind, 'source_locale' => $sourceLocale, 'target_locale' => $targetLocale,
                'source_revision' => $source->draft_revision, 'target_revision' => $target?->draft_revision, 'request_key' => $key, 'request_hash' => $hash,
                'status' => 'processing', 'created_by' => $actor->id]);
        });
        if (! $suggestion->wasRecentlyCreated) {
            return $suggestion;
        }

        try {
            $proposed = $this->generate($kind, $sourceLocale, $targetLocale, $source->draft_content);
            $suggestion->forceFill(['status' => 'proposed', 'proposed' => $proposed])->save();
            $this->audit->record('cms.suggestion_proposed', category: AuditCategory::Admin, resource: $page, actorUserId: $actor->id,
                metadata: ['suggestion' => $suggestion->public_id, 'kind' => $kind, 'source' => $sourceLocale, 'target' => $targetLocale]);
        } catch (Throwable) {
            $suggestion->forceFill(['status' => 'failed', 'error_code' => 'generation_failed'])->save();
            throw new ApiException('The suggestion could not be generated safely. Nothing was changed.', 503, 'cms_suggestion_failed');
        }

        return $suggestion;
    }

    /** Saves the suggestion as the target draft. Needs the draft unchanged since the suggestion was made. */
    public function apply(CmsAiSuggestion $suggestion, User $actor, ?string $slug): CmsPageLocale
    {
        return DB::transaction(function () use ($suggestion, $actor, $slug) {
            $s = CmsAiSuggestion::whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'proposed') {
                throw ApiException::conflict('Only open suggestions can be applied.', 'suggestion_not_open', ['status' => $s->status]);
            }
            $page = CmsPage::findOrFail($s->page_id);
            $target = CmsPageLocale::where('page_id', $page->id)->where('locale', $s->target_locale)->first();
            if ($target?->draft_revision !== $s->target_revision) {
                throw ApiException::conflict('The draft changed after this suggestion was made. Ask for a new suggestion.', 'draft_revision_conflict');
            }
            $l = $this->cms->saveDraft($page, $s->target_locale, (array) $s->proposed, (int) ($s->target_revision ?? 1), $slug, $actor->id);
            $s->forceFill(['status' => 'applied', 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            $this->audit->record('cms.suggestion_applied', category: AuditCategory::Admin, resource: $page, actorUserId: $actor->id, metadata: ['suggestion' => $s->public_id, 'locale' => $s->target_locale]);

            return $l;
        });
    }

    public function reject(CmsAiSuggestion $suggestion, User $actor): CmsAiSuggestion
    {
        return DB::transaction(function () use ($suggestion, $actor) {
            $s = CmsAiSuggestion::whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'proposed') {
                throw ApiException::conflict('Only open suggestions can be rejected.', 'suggestion_not_open', ['status' => $s->status]);
            }
            $s->forceFill(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            $this->audit->record('cms.suggestion_rejected', category: AuditCategory::Admin, resource: CmsPage::find($s->page_id), actorUserId: $actor->id, metadata: ['suggestion' => $s->public_id]);

            return $s;
        });
    }

    /**
     * Text segments of a content document, in a stable order: [kind, text].
     *
     * @param  array<string, mixed>  $content
     * @return list<array{0: string, 1: string}>
     */
    public static function segments(array $content): array
    {
        $out = [];
        foreach (['title', 'seo_title', 'seo_description'] as $f) {
            if (is_string($content[$f] ?? null) && $content[$f] !== '') {
                $out[] = [$f, $content[$f]];
            }
        }
        $walk = function (array $n) use (&$walk, &$out) {
            match ($n['type'] ?? null) {
                'text' => $out[] = ['text', (string) $n['text']],
                'button' => $out[] = ['label', (string) ($n['attrs']['label'] ?? '')],
                'image' => $out[] = ['alt', (string) ($n['attrs']['alt'] ?? '')],
                default => null,
            };
            foreach ($n['content'] ?? [] as $child) {
                is_array($child) && $walk($child);
            }
        };
        $walk((array) ($content['body'] ?? []));

        return $out;
    }

    /**
     * Puts rewritten segments back into the original structure.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    public static function replace(array $content, array $texts): array
    {
        $i = 0;
        foreach (['title', 'seo_title', 'seo_description'] as $f) {
            if (is_string($content[$f] ?? null) && $content[$f] !== '') {
                $content[$f] = $texts[$i++];
            }
        }
        $walk = function (array $n) use (&$walk, &$i, $texts): array {
            match ($n['type'] ?? null) {
                'text' => $n['text'] = $texts[$i++],
                'button' => $n['attrs']['label'] = $texts[$i++],
                'image' => $n['attrs']['alt'] = $texts[$i++],
                default => null,
            };
            if (isset($n['content']) && is_array($n['content'])) {
                $n['content'] = array_map(fn ($c) => is_array($c) ? $walk($c) : $c, $n['content']);
            }

            return $n;
        };
        $content['body'] = $walk((array) ($content['body'] ?? []));

        return $content;
    }

    /**
     * Facts the model may not introduce: numbers, amounts, percentages, links and e-mail addresses.
     *
     * @return list<string>
     */
    public static function facts(string $text): array
    {
        preg_match_all('/https?:\/\/\S+|www\.\S+|[\w.+-]+@[\w-]+\.[\w.]+|\d+(?:[.,]\d+)*|[€$£%]/u', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function generate(string $kind, string $from, string $to, array $content): array
    {
        $segments = self::segments($content);
        if ($segments === [] || count($segments) > 200) {
            throw new RuntimeException('Unsupported content size.');
        }
        $names = ['nl' => 'Dutch', 'en' => 'English'];
        $instructions = ($kind === 'translate'
            ? "Translate every ShopBonus website text segment from {$names[$from]} to {$names[$to]}."
            : "Improve every ShopBonus website text segment in {$names[$to]}: clearer, shorter, friendly and professional, better for search engines.")
            .' Return exactly one output per input segment, in the same order. Plain text only, no HTML or markdown.'
            .' Never add facts: no new numbers, prices, percentages, dates, names, statistics, awards, partners, links or e-mail addresses.'
            .' Respect the maximum length given per segment. These are private drafts for human review.';
        $input = array_map(fn ($s) => ['kind' => $s[0], 'max_length' => self::LIMITS[$s[0]], 'text' => $s[1]], $segments);
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['segments'],
            'properties' => ['segments' => ['type' => 'array', 'items' => ['type' => 'string']]]];

        $response = Http::withToken((string) config('cms.api_key'))->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(30)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('cms.model'), 'store' => false, 'max_output_tokens' => 4000, 'instructions' => $instructions,
                'input' => json_encode(['segments' => $input], JSON_THROW_ON_ERROR),
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'cms_segments', 'strict' => true, 'schema' => $schema]],
            ]);
        if (! $response->successful() || strlen($response->body()) > 262144 || $response->json('status') !== 'completed') {
            throw new RuntimeException('Provider failed.');
        }
        $texts = [];
        foreach ($response->json('output', []) as $output) {
            if (($output['type'] ?? '') === 'reasoning') {
                continue;
            }
            if (($output['type'] ?? '') !== 'message' || ($output['role'] ?? '') !== 'assistant') {
                throw new RuntimeException('Invalid provider output.');
            }
            foreach ($output['content'] ?? [] as $item) {
                if (($item['type'] ?? '') !== 'output_text') {
                    throw new RuntimeException('Invalid provider content.');
                }
                $texts[] = $item['text'];
            }
        }
        $value = count($texts) === 1 ? json_decode($texts[0], true, flags: JSON_THROW_ON_ERROR) : null;
        $out = is_array($value) ? ($value['segments'] ?? null) : null;
        if (! is_array($out) || count($out) !== count($segments) || ! array_is_list($out)) {
            throw new RuntimeException('Segment count mismatch.');
        }
        $allowed = self::facts(implode(' ', array_column($segments, 1)));
        foreach ($out as $i => $text) {
            $kindOf = $segments[$i][0];
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > self::LIMITS[$kindOf] || preg_match('/[<>]/', $text) === 1
                || array_diff(self::facts($text), $allowed) !== [] || str_contains($text, (string) config('cms.api_key'))) {
                throw new RuntimeException('Unsafe segment.');
            }
        }

        return $this->cms->validateContent(self::replace($content, $out));
    }
}
