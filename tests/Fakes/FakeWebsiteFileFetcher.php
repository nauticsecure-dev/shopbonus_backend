<?php

namespace Tests\Fakes;

use App\Services\Onboarding\Network\WebsiteFileFetcher;

class FakeWebsiteFileFetcher implements WebsiteFileFetcher
{
    /** @var array<string, string> "domain/path" => body */
    public array $files = [];

    public function fetch(string $domain, string $path, int $maxBytes = 1024): ?string
    {
        $body = $this->files[$domain.$path] ?? null;

        return $body === null ? null : substr($body, 0, $maxBytes);
    }
}
