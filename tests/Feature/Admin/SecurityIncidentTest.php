<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentOccurrence;
use App\Models\User;
use App\Services\Errors\ErrorRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_required_http_status_is_grouped_with_request_id_and_no_payload(): void
    {
        foreach ([401, 403, 419, 422, 429, 500] as $status) {
            Route::get('/api/v1/incident-fixture/'.$status, fn () => response()->json(['error' => ['code' => 'fixture_error']], $status));
            foreach (['first', 'second'] as $suffix) {
                $this->withHeader('X-Request-Id', 'incident-'.$status.'-'.$suffix)
                    ->getJson('/api/v1/incident-fixture/'.$status.'?password=secret-private-payload')->assertStatus($status);
            }
            $incident = SecurityIncident::where('http_status', $status)->sole();
            $this->assertSame(2, $incident->occurrences);
            $occurrences = SecurityIncidentOccurrence::where('incident_id', $incident->id)->get();
            $this->assertSame(['incident-'.$status.'-first', 'incident-'.$status.'-second'], $occurrences->pluck('request_id')->all());
            $this->assertStringNotContainsString('secret-private-payload', $occurrences->toJson());
        }
        $this->assertSame(6, SecurityIncident::count());
        $this->assertSame(12, SecurityIncidentOccurrence::count());
        $this->assertSame(2, AuditEvent::where('http_status', 403)->count());
    }

    public function test_real_auth_denials_validation_and_queue_failures_are_recorded(): void
    {
        $this->getJson('/api/v1/admin/incidents')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/incidents')->assertForbidden();
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/incidents?status=arbitrary')->assertUnprocessable();
        $this->assertSame(3, SecurityIncident::count());
        app(ErrorRecorder::class)->recordJobFailure(new \RuntimeException('private email is not copied'), 'App\\Jobs\\Fixture', 'default', 'sync');
        $this->assertDatabaseHas('security_incidents', ['service' => 'background_jobs', 'occurrences' => 1]);
        $this->assertStringNotContainsString('private email', SecurityIncidentOccurrence::all()->toJson());
    }

    public function test_incident_assignment_workflow_is_audited_validated_and_public_ids_only(): void
    {
        $this->getJson('/api/v1/admin/incidents')->assertUnauthorized();
        $incident = SecurityIncident::sole();
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000']);
        $url = '/api/v1/admin/incidents/'.$incident->public_id;
        $this->patchJson($url, ['status' => 'investigating', 'assigned_to' => $admin->public_id, 'reason' => 'Investigating repeated denials'])->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->patchJson($url, ['status' => 'investigating', 'assigned_to' => User::factory()->create()->public_id, 'reason' => 'Investigating repeated denials'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'investigating', 'assigned_to' => $admin->public_id, 'reason' => 'Investigating repeated denials'])->assertOk()->assertJsonPath('data.assigned_to', $admin->public_id);
        $this->patchJson($url, ['status' => 'resolved', 'assigned_to' => null, 'reason' => 'Investigation completed'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'history')->assertJsonPath('occurrences.0.error_code', 'unauthenticated');
        $this->getJson('/api/v1/admin/incidents?status=resolved&method=GET&request_category=read')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/incidents/missing')->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/v1/admin/incidents/missing', [])->assertForbidden();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);

        return $user;
    }
}
