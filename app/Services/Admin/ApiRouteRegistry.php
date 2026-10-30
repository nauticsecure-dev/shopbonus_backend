<?php

namespace App\Services\Admin;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routes;
use Symfony\Component\Yaml\Yaml;

/** Generated at request/command time: no second mutable route catalogue. */
class ApiRouteRegistry
{
    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $entries = [];
        foreach (Routes::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $path = '/'.$route->uri();
                $operation = $spec['paths'][$path][strtolower($method)] ?? [];
                $middleware = $route->gatherMiddleware();
                $has = fn ($prefix) => collect($middleware)->contains(fn ($m) => is_string($m) && ($m === $prefix || str_starts_with($m, $prefix.':')));
                $actual = match (true) {
                    $has('auth') && $has('platform') => 'admin', $has('auth') && $has('verified') => 'verified', $has('auth') => 'authenticated',
                    $has('guest') => 'guest', $has('integration.signed') => 'integration', default => 'public',
                };
                $permission = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'workspace.can:'));
                $permission = $permission === null ? null : substr($permission, strlen('workspace.can:'));
                $entries[] = ['method' => $method, 'route' => $path, 'name' => $route->getName(), 'access' => $actual,
                    'documented_access' => $operation['x-access'] ?? null, 'permission' => $permission,
                    'contract_matches' => ($operation['x-access'] ?? null) === $actual && ($operation['x-permission'] ?? null) === $permission,
                    'scope' => $has('workspace') ? 'workspace_membership' : ($actual === 'admin' ? 'platform' : ($has('auth') ? 'authenticated_user_resource' : ($actual === 'integration' ? 'integration_scopes' : 'public'))),
                    'roles' => $actual === 'admin' ? explode(',', substr((string) collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'platform:')), strlen('platform:'))) : [],
                    'audit_category' => $operation['x-audit-category'] ?? match (true) {
                        preg_match('/wallet|checkout|funding|affiliate|financial/', $path) === 1 => 'financial',
                        preg_match('/voucher|dispute/', $path) === 1 => 'voucher',
                        preg_match('/integration|answer24|webhook/', $path) === 1 => 'integration',
                        default => $actual === 'admin' ? 'admin' : 'security_denials',
                    },
                    'monitoring_category' => $operation['tags'] ?? ['api'], 'monitoring_owner' => 'backend',
                    'error_statuses' => array_values(array_filter(array_map('strval', array_keys($operation['responses'] ?? [])), fn ($code) => (int) $code >= 400)),
                    'authentication_types' => $has('password.recent') ? ['session_cookie'] : ($has('auth') ? ['session_cookie', 'bearer_token'] : ($has('integration.signed') ? ['hmac_signature'] : ['none'])),
                    'password_recent' => $has('password.recent'), 'email_otp' => $has('step_up.otp')];
            }
        }

        return $entries;
    }
}
