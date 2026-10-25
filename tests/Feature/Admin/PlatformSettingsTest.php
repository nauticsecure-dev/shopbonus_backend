<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSettingVersion;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Admin\StepUpCodes;
use App\Services\Audit\AuditIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);

        return $user;
    }

    private function asAdmin(User $user, bool $otp = true): void
    {
        Sanctum::actingAs($user);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => $otp ? time() : 0]);
    }

    public function test_draft_review_independent_publish_and_rollback_preserve_history(): void
    {
        $author = $this->admin();
        $reviewer = $this->admin();
        $this->asAdmin($author);
        $id = $this->postJson('/api/v1/admin/platform-settings', ['key' => 'public.brand_name', 'value' => 'Shopbonus NL', 'reason' => 'Reviewed branding change'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->assertSame('Shopbonus', $this->getJson('/api/v1/public/settings')->json('data.values')['public.brand_name']);
        $url = '/api/v1/admin/platform-settings/'.$id;
        $this->asAdmin($reviewer);
        $this->postJson($url.'/publish', ['note' => 'Independent review completed'])->assertConflict();
        $this->asAdmin($author);
        $this->postJson($url.'/review')->assertOk()->assertJsonPath('data.status', 'review');
        $this->postJson($url.'/review')->assertConflict();
        $this->postJson($url.'/publish', ['note' => 'Independent review completed'])->assertForbidden();
        $this->asAdmin($reviewer, otp: false);
        $this->postJson($url.'/publish', ['note' => 'Independent review completed'])->assertStatus(423);
        $this->asAdmin($reviewer);
        $this->postJson($url.'/publish', ['note' => 'Independent review completed'])->assertOk()->assertJsonPath('data.is_current', true);
        $this->assertSame('Shopbonus NL', $this->getJson('/api/v1/public/settings')->json('data.values')['public.brand_name']);
        $this->postJson($url.'/publish', ['note' => 'Repeat publication'])->assertConflict();
        $rollback = $this->postJson($url.'/rollback', ['reason' => 'Restore published historical values'])->assertCreated()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.rollback_of', $id)->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->assertSame($id, $this->getJson('/api/v1/public/settings')->json('data.versions')['public.brand_name']);
        $this->postJson('/api/v1/admin/platform-settings/'.$rollback.'/review')->assertOk();
        $this->asAdmin($author);
        $this->postJson('/api/v1/admin/platform-settings/'.$rollback.'/publish', ['note' => 'Rollback independently verified'])->assertOk();
        $this->assertSame($rollback, $this->getJson('/api/v1/public/settings')->json('data.versions')['public.brand_name']);
        $this->assertSame('published', PlatformSettingVersion::where('public_id', $id)->sole()->status);
        $this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
    }

    public function test_settings_allowlist_and_value_validation_block_sensitive_policy_or_secret_changes(): void
    {
        $this->asAdmin($this->admin());
        foreach (['mollie.api_key', 'affiliate.credit_timing', 'answer24.dispatch_enabled', 'public.unknown'] as $key) {
            $this->postJson('/api/v1/admin/platform-settings', ['key' => $key, 'value' => 'secret', 'reason' => 'Test sensitive key'])->assertUnprocessable();
        }
        foreach ([['public.brand_name', '<script>alert(1)</script>'], ['public.languages', ['en', 'en']], ['public.languages', ['fr']], ['public.support_email', 'not-an-email']] as [$key, $value]) {
            $this->postJson('/api/v1/admin/platform-settings', ['key' => $key, 'value' => $value, 'reason' => 'Test invalid value'])->assertUnprocessable();
        }
        $this->assertSame(0, PlatformSettingVersion::count());
    }

    public function test_stale_publication_and_changed_snapshot_checksum_are_refused(): void
    {
        $author = $this->admin();
        $reviewer = $this->admin();
        $service = app(PlatformSettings::class);
        $older = $service->submit($service->draft($author, 'public.brand_name', 'Old Branding', 'Older proposed branding'), $author);
        $newer = $service->submit($service->draft($author, 'public.brand_name', 'New Branding', 'Newer proposed branding'), $author);
        $service->publish($newer, $reviewer, 'Independent review completed');
        $this->asAdmin($reviewer);
        $this->postJson('/api/v1/admin/platform-settings/'.$older->public_id.'/publish', ['note' => 'Attempt stale publication'])->assertConflict()->assertJsonPath('error.code', 'setting_stale');
        $draft = $service->submit($service->draft($author, 'public.brand_name', 'Verified Branding', 'Proposed verified branding'), $author);
        DB::table('platform_setting_versions')->where('id', $draft->id)->update(['value' => json_encode('Changed Outside Workflow')]);
        $this->postJson('/api/v1/admin/platform-settings/'.$draft->public_id.'/publish', ['note' => 'Attempt changed snapshot'])->assertConflict()->assertJsonPath('error.code', 'setting_integrity_failed');
        $this->assertSame($newer->public_id, $service->published()['versions']['public.brand_name']);
    }

    public function test_settings_are_protected_before_lookup_and_drafts_cannot_rollback(): void
    {
        $this->getJson('/api/v1/admin/platform-settings')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/admin/platform-settings/missing/publish', [])->assertForbidden();
        $this->asAdmin($this->admin());
        $id = $this->postJson('/api/v1/admin/platform-settings', ['key' => 'public.languages', 'value' => ['en'], 'reason' => 'Display English option'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/platform-settings/'.$id.'/rollback', ['reason' => 'Rollback draft should fail'])->assertConflict();
        $this->withSession(['auth.password_confirmed_at' => 0]);
        $this->postJson('/api/v1/admin/platform-settings', [])->assertStatus(423);
    }
}
