<?php

namespace Tests\Feature\Api;

use App\Services\Admin\ApiRouteRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReleaseInventoryTest extends TestCase
{
    public function test_checked_in_inventory_matches_current_runtime_counts_roles_and_access(): void
    {
        $entries = app(ApiRouteRegistry::class)->entries();
        $runtime = array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'api/v1/'));
        $document = file_get_contents(base_path('docs/release/API_INVENTORY.md'));
        $this->assertStringContainsString('Runtime route entries: **'.count($runtime).'**', $document);
        $this->assertStringContainsString('HEAD aliases excluded): **'.count($entries).'**', $document);
        $this->assertStringContainsString('Unique paths: **'.count(array_unique(array_column($entries, 'route'))).'**', $document);
        $actual = [];
        foreach (explode("\n", $document) as $line) {
            if (! preg_match('/^\| (GET|POST|PUT|PATCH|DELETE|OPTIONS) \| `([^`]+)` \|/', $line, $m)) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $key = $m[1].' '.$m[2];
            $this->assertArrayNotHasKey($key, $actual, 'Duplicate inventory operation.');
            $actual[$key] = [$cells[2], $cells[3], $cells[4], $cells[5], $cells[6], $cells[7], $cells[9]];
        }
        $expected = [];
        foreach ($entries as $e) {
            $step = array_filter([$e['password_recent'] ? 'recent password' : null, $e['email_otp'] ? 'email code' : null]);
            $expected[$e['method'].' '.$e['route']] = [$e['access'], implode(', ', $e['roles']) ?: '-',
                implode(', ', $e['authentication_types']), $e['permission'] ?? '-', $e['scope'], implode(', ', $step) ?: '-', $e['audit_category']];
        }
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(0, Artisan::call('shopbonus:api-routes', ['--markdown' => true]));
        $this->assertStringStartsWith("# API route inventory (Trello #22)\n\nGenerated", Artisan::output());
        $this->assertSame(0, Artisan::call('shopbonus:api-routes'));
        $this->assertSame(['data' => $entries], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_undocumented_multi_method_route_blocks_markdown_but_json_still_reports_drift(): void
    {
        Route::match(['GET', 'POST'], 'api/v1/release-inventory-fixture', fn () => response()->noContent());
        $this->assertSame(1, Artisan::call('shopbonus:api-routes', ['--markdown' => true]));
        $this->assertStringContainsString('drift', Artisan::output());
        $this->assertStringNotContainsString('| Method |', Artisan::output());
        $this->assertSame(1, Artisan::call('shopbonus:api-routes'));
        $entries = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $bad = array_values(array_filter($entries, fn ($e) => ! $e['contract_matches']));
        $this->assertCount(2, $bad);
        $this->assertSame(['GET', 'POST'], array_column($bad, 'method'));
    }
}
