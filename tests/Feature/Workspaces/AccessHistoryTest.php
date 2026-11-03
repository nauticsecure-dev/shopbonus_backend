<?php

namespace Tests\Feature\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_scoped_paginated_and_excludes_sensitive_audit_fields(): void
    {
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create();
        $other = Webshop::factory()->create();
        $logger = app(AuditLogger::class);
        for ($i = 0; $i < 26; $i++) {
            $logger->record('team.member_updated', category: AuditCategory::Workspace, webshopId: $shop->id, metadata: ['secret' => 'never expose']);
        }
        $logger->record('ownership.transfer_started', category: AuditCategory::Workspace, webshopId: $shop->id);
        $logger->record('team.invitation_created', category: AuditCategory::Workspace, webshopId: $other->id);
        $logger->record('payment.succeeded', category: AuditCategory::Workspace, webshopId: $shop->id);
        Sanctum::actingAs($owner);
        $url = "/api/v1/workspaces/{$shop->public_id}/access-history";
        $response = $this->getJson($url)->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.action', 'ownership.transfer_started');
        $this->assertSame(['action', 'occurred_at', 'outcome'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('never expose', $response->getContent());
        $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'?page=0')->assertUnprocessable();
        $this->getJson("/api/v1/workspaces/{$other->public_id}/access-history")->assertNotFound();
    }

    public function test_history_requires_effective_team_management_and_active_membership(): void
    {
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create();
        $employee = User::factory()->create();
        $membership = $shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee,
            'status' => MembershipStatus::Active, 'permissions' => ['team.view']]);
        $url = "/api/v1/workspaces/{$shop->public_id}/access-history";
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($employee);
        $this->getJson($url)->assertForbidden();
        $membership->update(['permissions' => ['team.view', 'team.manage']]);
        $this->getJson($url)->assertOk();
        $membership->update(['status' => MembershipStatus::Removed]);
        $this->getJson($url)->assertNotFound();
    }
}
