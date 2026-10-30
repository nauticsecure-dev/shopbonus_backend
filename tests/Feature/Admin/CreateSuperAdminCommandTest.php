<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_verified_super_admin_from_prompts(): void
    {
        $this->artisan('shopbonus:create-admin')
            ->expectsQuestion('Name', 'Martijn')
            ->expectsQuestion('Email', ' Admin@Example.COM ')
            ->expectsQuestion('Password (min 10 characters, letters and numbers)', 'Sterk-wachtwoord-2026')
            ->expectsQuestion('Confirm password', 'Sterk-wachtwoord-2026')
            ->expectsOutputToContain('Super Admin admin@example.com created.')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('Martijn', $user->first_name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('Sterk-wachtwoord-2026', $user->password));
        $this->assertSame(1, AuditEvent::where('action', 'admin.super_admin_granted')->count());
    }

    public function test_accepts_options_and_signs_in(): void
    {
        $this->artisan('shopbonus:create-admin', ['--name' => 'Frank', '--email' => 'frank@example.com', '--password' => 'Another-pass-2026'])->assertSuccessful();
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->postJson('/login', ['email' => 'frank@example.com', 'password' => 'Another-pass-2026'])->assertOk();
    }

    public function test_rejects_weak_password_mismatch_and_existing_email(): void
    {
        $this->artisan('shopbonus:create-admin', ['--name' => 'A', '--email' => 'a@example.com', '--password' => 'short'])->assertFailed();
        $this->artisan('shopbonus:create-admin', ['--name' => 'A', '--email' => 'not-an-email', '--password' => 'Long-enough-2026'])->assertFailed();
        $this->artisan('shopbonus:create-admin', ['--name' => 'A', '--email' => 'a@example.com'])
            ->expectsQuestion('Password (min 10 characters, letters and numbers)', 'Long-enough-2026')
            ->expectsQuestion('Confirm password', 'Different-2026')
            ->expectsOutputToContain('do not match')->assertFailed();

        User::factory()->create(['email' => 'taken@example.com']);
        $this->artisan('shopbonus:create-admin', ['--name' => 'A', '--email' => 'taken@example.com', '--password' => 'Long-enough-2026'])
            ->expectsOutputToContain('shopbonus:grant-super-admin')->assertFailed();
        $this->assertSame(1, User::count());
        $this->assertSame(0, AuditEvent::where('action', 'admin.super_admin_granted')->count());
    }
}
