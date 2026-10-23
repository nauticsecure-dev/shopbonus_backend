<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Enforces the documentation rule in docs/api/README.md: every /api/v1 route
 * is in openapi.yaml with an example request and an example for each response.
 */
class OpenApiCoverageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    }

    public function test_spec_is_valid_openapi_31(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
    }

    public function test_every_api_route_is_documented_with_examples(): void
    {
        $spec = $this->spec();
        $components = $spec['components']['responses'] ?? [];
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $path = '/'.$route->uri();

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $label = "{$method} {$path}";
                $operation = $spec['paths'][$path][strtolower($method)] ?? null;

                $this->assertNotNull($operation, "{$label} is missing from docs/api/openapi.yaml");
                $this->assertNotEmpty($operation['x-codeSamples'] ?? [], "{$label} has no example request (x-codeSamples)");
                $this->assertNotEmpty($operation['responses'] ?? [], "{$label} documents no responses");

                foreach ($operation['responses'] as $status => $response) {
                    if (isset($response['$ref'])) {
                        $response = $components[basename($response['$ref'])] ?? [];
                    }

                    $content = $response['content']['application/json'] ?? [];
                    $this->assertTrue(
                        isset($content['example']) || ! empty($content['examples']),
                        "{$label} response {$status} has no example",
                    );
                }
            }
        }
    }
}
