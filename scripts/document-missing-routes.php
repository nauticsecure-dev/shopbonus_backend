<?php

/**
 * Adds missing OpenAPI operations for runtime /api/v1 routes.
 * - New paths are inserted before the top-level `components:` key.
 * - Missing methods on an existing path are merged under that path key.
 */

declare(strict_types=1);

use App\Services\Admin\ApiRouteRegistry;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$specPath = base_path('docs/api/openapi.yaml');
$existing = Yaml::parseFile($specPath);
$entries = app(ApiRouteRegistry::class)->entries();

$newPaths = [];
$mergeMethods = [];
$fixedAccess = 0;

foreach ($entries as $entry) {
    if ($entry['contract_matches']) {
        continue;
    }

    $path = $entry['route'];
    $method = strtolower($entry['method']);
    $access = $entry['access'];
    $permission = $entry['permission'];

    if (isset($existing['paths'][$path][$method])) {
        // Documented but access/permission mismatch — leave body, caller should fix x-access manually.
        $fixedAccess++;
        continue;
    }

    $slug = preg_replace('/[^a-zA-Z0-9]+/', ' ', $method.' '.$path) ?? 'op';
    $opId = lcfirst(str_replace(' ', '', ucwords(trim($slug))));

    $error = static fn (int $status, string $code, string $message): array => [
        'description' => $message,
        'content' => [
            'application/json' => [
                'example' => [
                    'error' => [
                        'status' => $status,
                        'code' => $code,
                        'message' => $message,
                        'request_id' => '01a16918-650f-721a-b787-30280f4825eb',
                    ],
                ],
            ],
        ],
    ];

    $isBinary = str_ends_with($path, '/cover') || str_ends_with($path, '/logo');
    $responses = [];
    if ($isBinary) {
        $responses['200'] = [
            'description' => 'Binary media response.',
            'content' => [
                'image/webp' => [
                    'schema' => ['type' => 'string', 'format' => 'binary'],
                ],
            ],
        ];
        $responses['404'] = $error(404, 'not_found', 'Not found.');
    } else {
        $responses['200'] = [
            'description' => 'Successful response (placeholder; capture a live example when available).',
            'content' => [
                'application/json' => [
                    'example' => ['data' => ['ok' => true]],
                ],
            ],
        ];
        if ($access !== 'public' && $access !== 'guest') {
            $responses['401'] = $error(401, 'unauthenticated', 'Authentication is required.');
            $responses['403'] = $error(403, 'forbidden', 'This action is unauthorized.');
        }
        if (str_contains($path, '{')) {
            $responses['404'] = $error(404, 'not_found', 'Not found.');
        }
    }

    $tag = match (true) {
        str_starts_with($path, '/api/v1/admin/') => 'Admin',
        str_starts_with($path, '/api/v1/public/') => 'Discovery',
        str_starts_with($path, '/api/v1/content/') => 'CMS',
        str_starts_with($path, '/api/v1/consumer/') => 'Consumer',
        str_starts_with($path, '/api/v1/workspaces/') => 'Workspaces',
        str_starts_with($path, '/api/v1/me') => 'Auth',
        default => 'API',
    };

    $operation = [
        'tags' => [$tag],
        'operationId' => $opId,
        'summary' => (string) ($entry['name'] ?? $opId),
        'description' => 'Documented for access-registry coverage. Replace placeholder examples with captured responses.',
        'x-access' => $access,
        'x-codeSamples' => [[
            'lang' => 'curl',
            'source' => "curl -i -X ".strtoupper($method)." 'http://localhost:8000{$path}' -H 'Accept: application/json'",
        ]],
        'responses' => $responses,
    ];
    if ($permission !== null) {
        $operation['x-permission'] = $permission;
    }
    if ($access === 'public' || $access === 'guest') {
        $operation['security'] = [];
    }

    if (isset($existing['paths'][$path])) {
        $mergeMethods[$path][$method] = $operation;
    } else {
        $newPaths[$path][$method] = $operation;
    }
}

$raw = file_get_contents($specPath);
if ($raw === false) {
    fwrite(STDERR, "Unable to read openapi.yaml\n");
    exit(1);
}

$flags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP;

// Merge missing methods into existing path blocks by inserting after the path key line.
foreach ($mergeMethods as $path => $methods) {
    $methodYaml = Yaml::dump($methods, 12, 2, $flags);
    // Indent every line four spaces so nested dump content stays under the path key.
    $indented = preg_replace('/^/m', '    ', rtrim($methodYaml))."\n";
    $pathLine = '  '.$path.":\n";
    $pos = strpos($raw, $pathLine);
    if ($pos === false) {
        // Fall back: treat as new path.
        $newPaths[$path] = ($newPaths[$path] ?? []) + $methods;
        continue;
    }
    $insertAt = $pos + strlen($pathLine);
    $raw = substr($raw, 0, $insertAt).$indented.substr($raw, $insertAt);
}

if ($newPaths !== []) {
    $yamlFragment = Yaml::dump(['paths' => $newPaths], 12, 2, $flags);
    $yamlFragment = preg_replace('/^paths:\n/', '', $yamlFragment) ?? $yamlFragment;
    $needle = "\ncomponents:\n";
    $pos = strrpos($raw, $needle);
    if ($pos === false) {
        fwrite(STDERR, "Could not locate top-level components: section\n");
        exit(1);
    }
    $raw = substr($raw, 0, $pos)."\n".$yamlFragment.substr($raw, $pos);
}

file_put_contents($specPath, $raw);

$newCount = 0;
foreach ($newPaths as $m) {
    $newCount += count($m);
}
$mergeCount = 0;
foreach ($mergeMethods as $m) {
    $mergeCount += count($m);
}
echo "Added {$newCount} ops on new paths, merged {$mergeCount} ops into existing paths, skipped {$fixedAccess} access mismatches.\n";
