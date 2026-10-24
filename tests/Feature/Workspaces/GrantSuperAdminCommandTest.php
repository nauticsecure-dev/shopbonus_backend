<?php

namespace Tests\Feature\Workspaces;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GrantSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_grants_and_revokes_super_admin_with_audit(): void
    {
        $user = User::factory()->create(['email' => 'admin@shopbonus.nl']);

        $this->artisan('shopbonus:grant-super-admin', ['email' => 'ADMIN@shopbonus.nl'])->assertSuccessful();
        $this->assertTrue($user->isSuperAdmin());

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.roles', ['super_admin'])
            ->assertJsonPath('data.destination', 'admin');

        // The last active Super Admin cannot be revoked (B16b); with a second one it can.
        $this->artisan('shopbonus:grant-super-admin', ['email' => 'admin@shopbonus.nl', '--revoke' => true])->assertFailed();
        $this->assertTrue($user->fresh()->isSuperAdmin());
        User::factory()->create(['email' => 'tweede@shopbonus.nl']);
        $this->artisan('shopbonus:grant-super-admin', ['email' => 'tweede@shopbonus.nl'])->assertSuccessful();

        $this->artisan('shopbonus:grant-super-admin', ['email' => 'admin@shopbonus.nl', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isSuperAdmin());

        $this->assertSame(
            ['admin.super_admin_granted', 'admin.super_admin_granted', 'admin.super_admin_revoked'],
            AuditEvent::where('category', 'admin')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_refuses_unverified_or_inactive_users(): void
    {
        User::factory()->unverified()->create(['email' => 'new@shopbonus.nl']);
        User::factory()->suspended()->create(['email' => 'old@shopbonus.nl']);

        $this->artisan('shopbonus:grant-super-admin', ['email' => 'new@shopbonus.nl'])->assertFailed();
        $this->artisan('shopbonus:grant-super-admin', ['email' => 'old@shopbonus.nl'])->assertFailed();
        $this->artisan('shopbonus:grant-super-admin', ['email' => 'nobody@shopbonus.nl'])->assertFailed();
    }

    public function test_there_is_no_way_to_become_super_admin_through_the_api(): void
    {
        $this->assertEmpty(collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => (str_contains($r->uri(), 'super-admin') || str_contains($r->uri(), 'platform-role'))
                && ! str_ends_with($r->uri(), '/revoke-super-admin'))); // revoking (B16b) is allowed; granting is console-only
    }
}
