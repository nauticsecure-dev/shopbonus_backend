<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\CookieConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B15b: cookie consent lifecycle (Trello #5 E2E 9) and NL/EN API messages (E2E 1, backend part).
 */
class CookieConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function policy(string $version, bool $reconsent = false): array
    {
        $tracker = fn (string $key, string $name) => ['key' => $key, 'name' => $name, 'provider' => 'Plausible', 'purpose_nl' => 'Bezoekstatistieken', 'purpose_en' => 'Visit statistics', 'retention' => '12 maanden'];
        $text = fn (string $l) => ['title' => $l === 'nl' ? 'Cookies' : 'Cookies', 'body' => $l === 'nl' ? 'Wij gebruiken cookies.' : 'We use cookies.', 'accept_all' => $l === 'nl' ? 'Alles accepteren' : 'Accept all',
            'reject_optional' => $l === 'nl' ? 'Optionele weigeren' : 'Reject optional', 'customize' => $l === 'nl' ? 'Aanpassen' : 'Customize'];

        return ['version' => $version, 'requires_reconsent' => $reconsent, 'texts' => ['nl' => $text('nl'), 'en' => $text('en')], 'categories' => [
            ['key' => 'necessary', 'trackers' => [['key' => 'session', 'name' => 'Sessie', 'provider' => 'ShopBonus', 'purpose_nl' => 'Inloggen', 'purpose_en' => 'Sign-in', 'retention' => 'Sessie']]],
            ['key' => 'preferences', 'trackers' => []],
            ['key' => 'analytics', 'trackers' => [$tracker('plausible', 'Plausible Analytics')]],
            ['key' => 'marketing', 'trackers' => [$tracker('meta_pixel', 'Meta Pixel')]],
        ]];
    }

    private function publish(string $version, bool $reconsent = false): void
    {
        $this->asAdmin();
        $id = $this->postJson('/api/v1/admin/cms/cookie-policies', $this->policy($version, $reconsent))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/cms/cookie-policies/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->app['auth']->forgetGuards();
    }

    public function test_card5_e2e9_cookie_lifecycle_and_reconsent(): void
    {
        $this->getJson('/api/v1/public/cookie-policy')->assertOk()->assertJsonPath('data.version', null); // nothing published: necessary only
        $this->publish('2026.1');

        $policy = $this->getJson('/api/v1/public/cookie-policy?locale=en')->assertOk()->assertJsonPath('data.texts.accept_all', 'Accept all')->json('data');
        $this->assertSame([true, false, false, false], array_column($policy['categories'], 'required'));

        // Reject optional: only necessary trackers may load.
        $first = $this->postJson('/api/v1/public/cookie-consents', ['policy_version' => '2026.1', 'action' => 'reject_optional'])->assertCreated();
        $id = $first->json('data.consent_id');
        $this->assertSame(['session'], $first->json('data.allowed_trackers'));
        $this->assertSame(['necessary' => true, 'preferences' => false, 'analytics' => false, 'marketing' => false], $first->json('data.choices'));

        // Customize: analytics only.
        $this->postJson('/api/v1/public/cookie-consents', ['consent_id' => $id, 'policy_version' => '2026.1', 'action' => 'customize', 'choices' => ['analytics' => true]])
            ->assertCreated()->assertJsonPath('data.allowed_trackers', ['session', 'plausible']);
        // Accept all, then withdraw.
        $this->postJson('/api/v1/public/cookie-consents', ['consent_id' => $id, 'policy_version' => '2026.1', 'action' => 'accept_all'])->assertJsonPath('data.allowed_trackers', ['session', 'plausible', 'meta_pixel']);
        $this->postJson('/api/v1/public/cookie-consents', ['consent_id' => $id, 'policy_version' => '2026.1', 'action' => 'withdraw'])->assertJsonPath('data.allowed_trackers', ['session']);
        $this->getJson("/api/v1/public/cookie-consents/{$id}")->assertOk()->assertJsonPath('data.action', 'withdraw')->assertJsonPath('data.needs_renewal', false);
        $this->assertSame(4, CookieConsent::where('consent_id', $id)->count()); // full history

        // A new policy that requires renewed consent: old choices no longer count.
        $this->postJson('/api/v1/public/cookie-consents', ['consent_id' => $id, 'policy_version' => '2026.1', 'action' => 'accept_all'])->assertCreated();
        $this->publish('2026.2', reconsent: true);
        $this->getJson("/api/v1/public/cookie-consents/{$id}")->assertJsonPath('data.needs_renewal', true)->assertJsonPath('data.allowed_trackers', []);
        $this->postJson('/api/v1/public/cookie-consents', ['consent_id' => $id, 'policy_version' => '2026.1', 'action' => 'accept_all'])->assertStatus(409)->assertJsonPath('error.code', 'policy_outdated');
        $this->postJson('/api/v1/public/cookie-consents', ['consent_id' => $id, 'policy_version' => '2026.2', 'action' => 'customize', 'choices' => ['marketing' => true]])->assertCreated();
        $this->getJson("/api/v1/public/cookie-consents/{$id}")->assertJsonPath('data.needs_renewal', false)->assertJsonPath('data.allowed_trackers', ['session', 'meta_pixel']);

        $this->postJson('/api/v1/public/cookie-consents', ['policy_version' => '2026.2', 'action' => 'withdraw'])->assertNotFound();
        $this->getJson('/api/v1/public/cookie-consents/not-a-uuid')->assertNotFound();
    }

    public function test_policies_are_validated_immutable_once_published_and_admin_only(): void
    {
        $this->asAdmin();
        $bad = $this->policy('x');
        $bad['categories'][2]['trackers'][0]['name'] = '<script>';
        $this->postJson('/api/v1/admin/cms/cookie-policies', $bad)->assertUnprocessable();
        $missing = $this->policy('y');
        array_pop($missing['categories']);
        $this->postJson('/api/v1/admin/cms/cookie-policies', $missing)->assertUnprocessable();

        $id = $this->postJson('/api/v1/admin/cms/cookie-policies', $this->policy('2026.1'))->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/cms/cookie-policies/{$id}", $this->policy('ignored', true))->assertOk()->assertJsonPath('data.requires_reconsent', true);
        $this->postJson("/api/v1/admin/cms/cookie-policies/{$id}/publish")->assertOk();
        $this->putJson("/api/v1/admin/cms/cookie-policies/{$id}", $this->policy('2026.1'))->assertStatus(409);
        $this->getJson('/api/v1/admin/cms/cookie-consents/stats')->assertOk()->assertJsonPath('data.version', '2026.1');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/admin/cms/cookie-policies', $this->policy('z'))->assertForbidden();
    }

    public function test_card5_e2e1_validation_messages_follow_accept_language(): void
    {
        config(['app.locale' => 'nl', 'app.fallback_locale' => 'nl']);
        app('translator')->setFallback('nl');
        $nl = $this->postJson('/api/v1/public/cookie-consents', [], ['Accept-Language' => 'nl-NL,nl;q=0.9'])->assertUnprocessable()->assertHeader('Content-Language', 'nl');
        $this->assertSame('Beleidsversie is verplicht.', $nl->json('error.fields.policy_version.0'));

        $en = $this->postJson('/api/v1/public/cookie-consents', [], ['Accept-Language' => 'en'])->assertUnprocessable()->assertHeader('Content-Language', 'en');
        $this->assertSame('The policy version field is required.', $en->json('error.fields.policy_version.0'));

        // No (supported) preference: the default, and nothing leaks from the previous request.
        $this->postJson('/api/v1/public/cookie-consents', [], ['Accept-Language' => 'fr-FR'])->assertHeader('Content-Language', 'nl');
        // Symfony's test request supplies English by default; blank means no preference.
        $this->postJson('/api/v1/public/cookie-consents', [], ['Accept-Language' => ''])->assertHeader('Content-Language', 'nl');
        config(['app.locale' => 'en']);
        $this->postJson('/api/v1/public/cookie-consents', [], ['Accept-Language' => 'fr-FR'])->assertHeader('Content-Language', 'en');
        $this->postJson('/api/v1/public/cookie-consents', [], ['Accept-Language' => ''])->assertHeader('Content-Language', 'en');
    }
}
