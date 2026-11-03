<?php

namespace App\Services\Cms;

use RuntimeException;

/** Deterministic rejection checks; passing these is never semantic approval. */
class TranslationGuard
{
    public static function assertSafe(string $source, string $target, string $from, string $to): void
    {
        if (trim($target) === '' || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/u', $target)) {
            throw new RuntimeException('Unsafe translated text.');
        }
        // Per-segment multisets prevent deletion, duplication, changes or movement of facts/tokens.
        foreach (['/https?:\/\/\S+|www\.\S+|[\w.+-]+@[\w-]+\.[\w.]+|\d+(?:[.,]\d+)*|[€$£%]/u',
            '/\{[A-Za-z_][A-Za-z0-9_.]*\}|:[A-Za-z_][A-Za-z0-9_]*|%\d*\$?[sdf]/u'] as $pattern) {
            if (self::matches($pattern, $source) !== self::matches($pattern, $target)) {
                throw new RuntimeException('Protected facts or tokens changed.');
            }
        }
        foreach ((array) config('cms.glossary', []) as $term) {
            if (is_string($term) && $term !== '' && substr_count($source, $term) !== substr_count($target, $term)) {
                throw new RuntimeException('Brand glossary changed.');
            }
        }
        $negation = [
            'nl' => '/\b(geen|niet|nooit|zonder)\b/iu',
            'en' => '/\b(no|not|never|without|cannot)\b|n[’\x27]t\b/iu',
            'de' => '/\b(kein\w*|nicht|nie|niemals|ohne)\b/iu',
        ];
        if ((preg_match($negation[$from], $source) === 1) !== (preg_match($negation[$to], $target) === 1)) {
            throw new RuntimeException('Negation changed; manual translation required.');
        }
        $cash = '/\b(cash[ -]?out|cash payout|withdraw\w*|uitbetal\w*|opnemen|auszahl\w*|bargeld\w*)\b/iu';
        if (preg_match($cash, $target) && ! preg_match($cash, $source)) {
            throw new RuntimeException('New payout claim; manual translation required.');
        }
        if ($from !== $to && mb_strlen($source) > 40 && $source === $target) {
            throw new RuntimeException('Untranslated segment.');
        }
    }

    /** @return list<string> */
    private static function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches);
        sort($matches[0], SORT_STRING);

        return $matches[0];
    }
}
