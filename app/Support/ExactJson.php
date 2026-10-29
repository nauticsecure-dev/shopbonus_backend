<?php

namespace App\Support;

use JsonException;

final class ExactJson
{
    public static function decode(string $body): mixed
    {
        $rewritten = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s', static function (array $m): string {
            return isset($m[1]) && strpbrk($m[1], '.eE') !== false ? json_encode($m[1], JSON_THROW_ON_ERROR) : $m[0];
        }, $body);
        if ($rewritten === null) {
            throw new JsonException('Invalid JSON encoding.');
        }

        return json_decode($rewritten, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
