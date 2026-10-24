<?php

namespace App\Services\Cms;

use App\Models\CmsAsset;
use Illuminate\Validation\ValidationException;

/**
 * The only rich-text format the CMS stores (Trello #5 "allowed content
 * schema"): a ProseMirror/Tiptap-style JSON document restricted to an
 * allowlist of nodes, marks and attributes. There is no HTML anywhere, so
 * script tags or event handlers cannot exist; links must be https or
 * site-relative; images must be uploaded CMS assets.
 */
class RichText
{
    private const MAX_NODES = 2000;

    private const BLOCKS = ['paragraph', 'heading', 'bulletList', 'orderedList', 'image', 'button'];

    private int $count = 0;

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $doc, string $field): array
    {
        $this->count = 0;
        if (($doc['type'] ?? null) !== 'doc' || ! is_array($doc['content'] ?? null) || array_diff(array_keys($doc), ['type', 'content']) !== []) {
            $this->fail($field, 'The content must be a document of allowed blocks.');
        }
        foreach ($doc['content'] as $i => $node) {
            $this->node($node, self::BLOCKS, "{$field}.content.{$i}");
        }

        return $doc;
    }

    /**
     * Plain text of a document, for search and previews.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function plainText(array $doc): string
    {
        $out = '';
        $walk = function (array $n) use (&$walk, &$out) {
            if (($n['type'] ?? null) === 'text') {
                $out .= $n['text'].' ';
            }
            foreach ($n['content'] ?? [] as $child) {
                is_array($child) && $walk($child);
            }
        };
        $walk($doc);

        return trim(preg_replace('/\s+/', ' ', $out) ?? '');
    }

    /**
     * @param  list<string>  $allowed
     */
    private function node(mixed $node, array $allowed, string $path): void
    {
        if (++$this->count > self::MAX_NODES) {
            $this->fail($path, 'The content is too long.');
        }
        if (! is_array($node) || ! in_array($node['type'] ?? null, $allowed, true)) {
            $this->fail($path, 'This block type is not allowed.');
        }
        $type = $node['type'];
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            $this->fail($path, 'Invalid attributes.');
        }

        $allowedKeys = match ($type) {
            'text' => ['type', 'text', 'marks'],
            'image', 'button' => ['type', 'attrs'],
            default => ['type', 'content', 'attrs'],
        };
        if (array_diff(array_keys($node), $allowedKeys) !== []) {
            $this->fail($path, 'Unknown properties.');
        }

        match ($type) {
            'text' => $this->text($node, $path),
            'heading' => in_array($attrs['level'] ?? null, [2, 3, 4], true) && array_keys($attrs) === ['level'] ? $this->children($node, ['text'], $path) : $this->fail($path, 'Headings are level 2, 3 or 4.'),
            'paragraph' => $attrs === [] ? $this->children($node, ['text'], $path, allowEmpty: true) : $this->fail($path, 'Paragraphs have no attributes.'),
            'bulletList', 'orderedList' => $attrs === [] ? $this->children($node, ['listItem'], $path) : $this->fail($path, 'Lists have no attributes.'),
            'listItem' => $attrs === [] ? $this->children($node, ['paragraph', 'bulletList', 'orderedList'], $path) : $this->fail($path, 'List items have no attributes.'),
            'image' => $this->image($attrs, $path),
            'button' => $this->button($attrs, $path),
            default => $this->fail($path, 'This block type is not allowed.'),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $allowed
     */
    private function children(array $node, array $allowed, string $path, bool $allowEmpty = false): void
    {
        $content = $node['content'] ?? [];
        if (! is_array($content) || (! $allowEmpty && $content === [])) {
            $this->fail($path, 'This block needs content.');
        }
        foreach (array_values($content) as $i => $child) {
            $this->node($child, $allowed, "{$path}.content.{$i}");
        }
    }

    /** @param array<string, mixed> $node */
    private function text(array $node, string $path): void
    {
        if (! is_string($node['text'] ?? null) || $node['text'] === '' || mb_strlen($node['text']) > 5000) {
            $this->fail($path, 'Text must be 1–5000 characters.');
        }
        foreach ($node['marks'] ?? [] as $j => $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            $ok = match ($type) {
                'bold', 'italic' => array_keys($mark) === ['type'],
                'link' => array_keys($mark) === ['type', 'attrs'] && is_array($mark['attrs']) && array_keys($mark['attrs']) === ['href'] && self::safeHref($mark['attrs']['href']),
                default => false,
            };
            if (! $ok) {
                $this->fail("{$path}.marks.{$j}", 'Only bold, italic and https or relative links are allowed.');
            }
        }
    }

    /** @param array<string, mixed> $attrs */
    private function image(array $attrs, string $path): void
    {
        if (array_diff(array_keys($attrs), ['asset_id', 'alt']) !== [] || ! is_string($attrs['asset_id'] ?? null) || ! is_string($attrs['alt'] ?? null)
            || mb_strlen($attrs['alt']) > 200 || ! CmsAsset::where('public_id', $attrs['asset_id'])->exists()) {
            $this->fail($path, 'Images need an uploaded CMS image and alt text.');
        }
    }

    /** @param array<string, mixed> $attrs */
    private function button(array $attrs, string $path): void
    {
        if (array_diff(array_keys($attrs), ['label', 'href']) !== [] || ! is_string($attrs['label'] ?? null) || mb_strlen($attrs['label']) > 40
            || preg_match('/[<>]/', $attrs['label']) === 1 || ! self::safeHref($attrs['href'] ?? null)) {
            $this->fail($path, 'Buttons need a short label and an https or relative link.');
        }
    }

    public static function safeHref(mixed $href): bool
    {
        if (! is_string($href) || strlen($href) > 500 || preg_match('/[\s<>"\']/', $href) === 1) {
            return false;
        }

        return (str_starts_with($href, '/') && ! str_starts_with($href, '//')) || (str_starts_with($href, 'https://') && filter_var($href, FILTER_VALIDATE_URL) !== false);
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
