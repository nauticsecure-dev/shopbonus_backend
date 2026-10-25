<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Admin\ApiRouteRegistry;
use App\Services\Admin\StepUpCodes;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditExportAndRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_admin_password_otp_and_bounded_dates_and_omits_private_fields(): void
    {
        $url = '/api/v1/admin/audit/export';
        $payload = ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'per_page' => 1];
        $this->postJson($url, $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($url, $payload)->assertForbidden();
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson($url, $payload)->assertStatus(423);
        $this->withSession([StepUpCodes::SESSION_KEY => time()]);
        app(AuditLogger::class)->record('private.fixture', metadata: ['email' => 'private@example.test']);
        $r = $this->postJson($url, $payload)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{26}$/', $r->json('data.0.id'));
        $this->assertArrayNotHasKey('resource_id', $r->json('data.0'));
        $this->assertArrayNotHasKey('metadata', $r->json('data.0'));
        $this->assertStringNotContainsString('private@example.test', $r->getContent());
        $this->postJson($url, ['from' => '2020-01-01', 'to' => '2026-10-25'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.audit_exported']);
    }

    public function test_registry_matches_runtime_routes_and_access_is_admin_only(): void
    {
        $this->getJson('/api/v1/admin/api-routes')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/api-routes')->assertForbidden();
        $entries = app(ApiRouteRegistry::class)->entries();
        $wallet = collect($entries)->firstWhere('route', '/api/v1/admin/wallet-adjustments/{approval}/review');
        $this->assertSame('admin', $wallet['access']);
        $this->assertSame('admin', $wallet['documented_access']);
        $this->assertSame(['super_admin'], $wallet['roles']);
        $this->assertTrue($wallet['password_recent']);
        $this->assertTrue($wallet['email_otp']);
        $this->assertTrue($wallet['contract_matches']);
        $this->assertTrue(collect($entries)->every(fn ($r) => $r['contract_matches']));
    }
}
