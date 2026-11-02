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
        $visible = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($visible)) ?? '');
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

        // Only follow a small set of relevant, same-origin links. Every fetch
        // retains SafeUrlFetcher's public-DNS pinning and redirect refusal.
        // E1-E3 are stable homepage references, even when title or meta is absent.
        $nextEvidence = 4;
        $pages = [$sourceUrl => $html];
        $document = $this->document($html);
        foreach ($document->getElementsByTagName('a') as $link) {
            $href = $this->sameOriginUrl($sourceUrl, $link->getAttribute('href'));
            if ($href === null || isset($pages[$href]) || count($pages) >= 4
                || ! preg_match('/about|over-ons|overons|contact|categor|assortiment|collection/i', $href.' '.$link->textContent)) {
                continue;
            }
            $body = $this->fetcher->fetch($href, min(262144, $maxBytes));
            $pages[$href] = $body ?? '';
            if ($body === null || trim($body) === '') {
                continue;
            }
            $body = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', '', $body) ?? '';
            $value = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? ''), 0, 3000);
            if ($value !== '') {
                $evidence['E'.$nextEvidence++] = ['fact' => 'page_text', 'value' => $value, 'source_url' => $href];
            }
        }
        foreach ($pages as $url => $body) {
            $doc = $this->document($body);
            $images = 0;
            foreach ($doc->getElementsByTagName('img') as $img) {
                $imageUrl = $this->sameOriginUrl($url, $img->getAttribute('src'));
                if ($imageUrl === null || ++$images > 8) {
                    continue;
                }
                $hint = mb_substr($img->getAttribute('alt'), 0, 150);
                $evidence['E'.$nextEvidence++] = [
                    'fact' => preg_match('/logo/i', $imageUrl.' '.$hint) ? 'logo_candidate' : 'image_candidate',
                    'value' => $imageUrl, 'source_url' => $url, 'alt' => $hint,
                ];
            }
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

    /** @param array<string, mixed> $research */
    public function hash(array $research): string
    {
        return hash('sha256', json_encode($research, JSON_THROW_ON_ERROR));
    }

    private function document(string $html): \DOMDocument
    {
        $document = new \DOMDocument;
        if ($html !== '') {
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        }

        return $document;
    }

    private function sameOriginUrl(string $base, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || preg_match('/[\x00-\x20\\\\]/', $href)) {
            return null;
        }
        $origin = 'https://'.parse_url($base, PHP_URL_HOST);
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
            $href = $origin.(str_starts_with($href, '/') ? $href : rtrim(dirname(parse_url($base, PHP_URL_PATH) ?: '/'), '/').'/'.$href);
        }
        $parts = parse_url($href);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== parse_url($base, PHP_URL_HOST)
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }

        return $origin.($parts['path'] ?? '/');
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
