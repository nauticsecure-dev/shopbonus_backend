<?php

namespace App\Services\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Internal snapshot. Never hydrate this object from client/model JSON. */
final readonly class ToolEvidence
{
    public string $id;

    /**
     * @param  array<string, string>  $arguments
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public int $actorId,
        public string $tool,
        public array $arguments,
        public ?string $workspace,
        public string $locale,
        public string $sourceId,
        public array $facts,
        public CarbonImmutable $retrievedAt,
    ) {
        $this->id = strtolower((string) Str::ulid());
    }

    /** Only this minimal projection may be passed to an answer selector.
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return ['evidence_id' => $this->id, 'tool' => $this->tool, 'checked_at' => $this->retrievedAt->toIso8601String(), 'facts' => $this->facts];
    }

    public function matches(self $other): bool
    {
        return $this->actorId === $other->actorId && $this->tool === $other->tool
            && $this->workspace === $other->workspace && $this->locale === $other->locale
            && $this->arguments === $other->arguments && $this->sourceId === $other->sourceId
            && $this->facts === $other->facts;
    }
}
