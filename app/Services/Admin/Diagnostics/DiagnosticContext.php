<?php

namespace App\Services\Admin\Diagnostics;

use App\Exceptions\ApiException;
use App\Models\SecurityIncident;
use App\Services\Admin\ApiRouteRegistry;

class DiagnosticContext
{
    /** @return array<string, mixed> */
    public function build(SecurityIncident $incident): array
    {
        if (! ($incident->http_status === 500 || ($incident->http_status === null && $incident->service === 'background_jobs'))
            || in_array($incident->status, ['resolved', 'ignored'], true)) {
            throw ApiException::conflict('Only active technical failure incidents can be analyzed.', 'incident_not_diagnosable');
        }
        $registered = collect(app(ApiRouteRegistry::class)->entries())->first(fn ($r) => $r['route'] === '/'.$incident->route && $r['method'] === $incident->http_method);
        $facts = [
            'http_status' => $incident->http_status,
            'service' => in_array($incident->service, ['api', 'integrations', 'background_jobs'], true) ? $incident->service : 'unknown',
            'registered_route' => $registered['route'] ?? null,
            'method' => $registered['method'] ?? null,
            'occurrence_count' => max(0, $incident->occurrences),
        ];
        $evidence = [];
        foreach ($facts as $name => $value) {
            $evidence['E'.(count($evidence) + 1)] = ['fact' => $name, 'value' => $value];
        }

        return ['schema_version' => 'incident-context-v1', 'evidence' => $evidence];
    }

    /** @param array<string, mixed> $context */
    public function hash(array $context): string
    {
        return hash('sha256', json_encode($this->canonical($context), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
