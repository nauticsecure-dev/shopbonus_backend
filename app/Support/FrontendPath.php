<?php

namespace App\Support;

/**
 * Builds SPA paths from `shopbonus.identity.frontend_paths`, so emails, the
 * inbox and support actions follow the frontend router without code changes.
 */
final class FrontendPath
{
    /** @param array<string, string|null> $params */
    public static function to(string $name, array $params = []): string
    {
        $template = (string) config('shopbonus.identity.frontend_paths.'.$name);
        if ($template === '') {
            throw new \LogicException("Unknown frontend path {$name}.");
        }

        return strtr($template, collect($params)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => rawurlencode((string) $v)])->all());
    }

    /** A same-origin relative path: no scheme, host, backslash or line break. */
    public static function isSafe(mixed $path): bool
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && preg_match('/[\\\\\r\n]/', $path) !== 1;
    }
}
