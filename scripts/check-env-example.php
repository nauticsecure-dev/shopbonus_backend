<?php

// Keep every application config env key discoverable without activating optional services.
$root = dirname(__DIR__);
$example = $root.'/.env.example';
$contents = file_get_contents($example);
preg_match_all('/^\s*#?\s*([A-Z][A-Z0-9_]+)=/m', $contents, $documented);
$references = [];
foreach (glob($root.'/config/*.php') as $file) {
    preg_match_all('/\benv\(\s*[\'"]([A-Z][A-Z0-9_]+)[\'"]/', file_get_contents($file), $matches);
    foreach ($matches[1] as $key) {
        $references[$key][] = basename($file);
    }
}
ksort($references);
$missing = array_diff(array_keys($references), $documented[1]);
if ($missing && in_array('--write', $argv, true)) {
    $contents .= "\n# Optional configuration catalogue. These are commented: omitting them preserves\n"
        ."# each config file's default. Set only for the selected driver/integration; do not\n"
        ."# uncomment the whole catalogue (empty values can override working defaults).\n"
        ."# Provider credentials must come from the deployment secret store.\n";
    foreach ($missing as $key) {
        $contents .= '# '.$key.'= # config/'.implode(', config/', array_unique($references[$key]))."\n";
    }
    file_put_contents($example, $contents);
    $missing = [];
}
preg_match_all('/^([A-Z][A-Z0-9_]+)=/m', $contents, $active);
$duplicates = array_keys(array_filter(array_count_values($active[1]), fn ($count) => $count > 1));
if ($missing || $duplicates) {
    fwrite(STDERR, 'Missing: '.implode(', ', $missing)."\nDuplicate active keys: ".implode(', ', $duplicates)."\n");
    exit(1);
}
echo count($references)." config env keys documented; no duplicate active keys.\n";
