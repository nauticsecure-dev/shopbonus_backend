<?php

namespace App\Services\Onboarding\Network;

interface WebsiteFileFetcher
{
    /**
     * Fetches https://{domain}{path} and returns at most $maxBytes of the
     * body, or null if the file is not available or the target is unsafe.
     */
    public function fetch(string $domain, string $path, int $maxBytes = 1024): ?string;
}
