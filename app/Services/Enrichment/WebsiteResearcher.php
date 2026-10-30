<?php

namespace App\Services\Enrichment;

use App\Logging\SensitiveDataProcessor;

class WebsiteResearcher
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * @return array{schema_version: string, source_url: string, title: string|null, meta_description: string|null, excerpt: string, evidence: array<string, array{fact: string, value: string}>}
     */
    public function research(string $sourceUrl, int $maxBytes): array
    {
        $html = $this->fetcher->fetch($sourceUrl, $maxBytes);
        if ($html === null || trim($html) === '') {
            throw new EnrichmentFailure('fetch_failed');
        }

        $title = $this->match($html, '/<title[^>]*>(.*?)<\/title>/is');
        $meta = $this->match($html, '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is')
            ?? $this->match($html, '/<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']/is');
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        $excerpt = mb_substr($text, 0, 4000);

        $evidence = [];
        if ($title !== null && $title !== '') {
            $evidence['E1'] = ['fact' => 'page_title', 'value' => $title];
        }
        if ($meta !== null && $meta !== '') {
            $evidence['E2'] = ['fact' => 'meta_description', 'value' => $meta];
        }
        if ($excerpt !== '') {
            $evidence['E3'] = ['fact' => 'visible_text_excerpt', 'value' => $excerpt];
        }
        if ($evidence === []) {
            throw new EnrichmentFailure('empty_research');
        }

        $payload = app(SensitiveDataProcessor::class)->redactArray([
            'schema_version' => 'merchant-enrichment-research-v1',
            'source_url' => $sourceUrl,
            'title' => $title,
            'meta_description' => $meta,
            'excerpt' => $excerpt,
            'evidence' => $evidence,
        ]);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function hash(array $research): string
    {
        return hash('sha256', json_encode($research, JSON_THROW_ON_ERROR));
    }

    private function match(string $html, string $pattern): ?string
    {
        if (preg_match($pattern, $html, $m) !== 1) {
            return null;
        }
        $value = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
