<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Enforces the documentation rule in docs/api/README.md and the access
 * registry required by Trello #4:
 *
 *  - every /api/v1 route and every session-auth route is in openapi.yaml,
 *    with an example request and an example for each response;
 *  - every operation declares `x-access` (public | guest | authenticated |
 *    verified | admin | integration), and that declaration matches the route's real middleware,
 *    so a private route cannot silently ship without auth (default deny).
 */
class OpenApiCoverageTest extends TestCase
{
    /** Session-auth routes served by Fortify/Sanctum outside /api/v1. */
    private const AUTH_ROUTE_NAMES = [
        'sanctum.csrf-cookie', 'register.store', 'login.store', 'logout',
        'password.email', 'password.update', 'user-password.update',
        'password.confirm.store', 'password.confirmation',
    ];

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    }

    /** @return list<Route> */
    private function documentedRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/')
                || in_array($route->getName(), self::AUTH_ROUTE_NAMES, true))
            ->values()
            ->all();
    }

    private function actualAccess(Route $route): string
    {
        $middleware = $route->gatherMiddleware();
        $has = fn (string $prefix) => collect($middleware)->contains(
            fn ($m) => is_string($m) && ($m === $prefix || str_starts_with($m, $prefix.':')),
        );

        return match (true) {
            $has('auth') && $has('platform') => 'admin',
            $has('auth') && $has('verified') => 'verified',
            $has('auth') => 'authenticated',
            $has('guest') => 'guest',
            $has('integration.signed') => 'integration',
            default => 'public',
        };
    }

    public function test_spec_is_valid_openapi_31(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
    }

    public function test_every_route_is_documented_with_examples_and_correct_access(): void
    {
        $spec = $this->spec();
        $components = $spec['components']['responses'] ?? [];
        $routes = $this->documentedRoutes();

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $path = '/'.$route->uri();

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $label = "{$method} {$path}";
                $operation = $spec['paths'][$path][strtolower($method)] ?? null;

                $this->assertNotNull($operation, "{$label} is missing from docs/api/openapi.yaml");
                $this->assertNotEmpty($operation['x-codeSamples'] ?? [], "{$label} has no example request (x-codeSamples)");
                $this->assertNotEmpty($operation['responses'] ?? [], "{$label} documents no responses");
                $this->assertSame(
                    $this->actualAccess($route),
                    $operation['x-access'] ?? null,
                    "{$label}: x-access does not match the route's middleware",
                );

                $permission = collect($route->gatherMiddleware())
                    ->first(fn ($m) => is_string($m) && str_starts_with($m, 'workspace.can:'));
                $this->assertSame(
                    $permission === null ? null : substr($permission, strlen('workspace.can:')),
                    $operation['x-permission'] ?? null,
                    "{$label}: x-permission does not match the route's workspace.can middleware",
                );

                foreach ($operation['responses'] as $status => $response) {
                    if (isset($response['$ref'])) {
                        $response = $components[basename($response['$ref'])] ?? [];
                    }

                    if ((int) $status === 204) {
                        continue;
                    }

                    // File downloads document a binary schema instead of a JSON example.
                    $binary = collect($response['content'] ?? [])->contains(fn ($media, $type) => $type !== 'application/json'
                        && ($media['schema']['format'] ?? null) === 'binary');
                    if ($binary) {
                        continue;
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

    public function test_every_authenticated_route_requires_an_active_account(): void
    {
        foreach ($this->documentedRoutes() as $route) {
            if (! in_array($this->actualAccess($route), ['authenticated', 'verified', 'admin'], true)) {
                continue;
            }

            $this->assertContains(
                'account.active',
                $route->gatherMiddleware(),
                "{$route->uri()} is authenticated but does not re-check account status",
            );
        }
    }
}
