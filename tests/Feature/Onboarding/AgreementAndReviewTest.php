<?php

namespace Tests\Feature\Onboarding;

use App\Enums\AgreementTemplateStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\WebshopStatus;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\WebsiteFileFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Fakes\FakeHostResolver;
use Tests\Fakes\FakeWebsiteFileFetcher;
use Tests\TestCase;

/**
 * B03d (Trello #16 E2E 3, 4, 9 suspension half, 10 backend journey).
 */
class AgreementAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Webshop $shop;

    private FakeHostResolver $dns;

    private const PROFILE = [
        'legal_name' => 'Fietsen Utrecht B.V.', 'website_url' => 'https://fietsen-utrecht.nl', 'kvk_number' => '12345678',
        'contact_email' => 'info@fietsen-utrecht.nl', 'address_line' => 'Oudegracht 1', 'postal_code' => '3511 AA',
        'city' => 'Utrecht', 'country_code' => 'NL',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->dns = new FakeHostResolver;
        $this->app->instance(HostResolver::class, $this->dns);
        $this->app->instance(WebsiteFileFetcher::class, new FakeWebsiteFileFetcher);

        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);

        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id, 'name' => 'Fietsenwinkel Utrecht']);

        Route::middleware(['api', 'auth:sanctum', 'account.active', 'verified', 'workspace', 'workspace.commercial'])
            ->get('api/v1/workspaces/{workspace}/_test/commercial', fn () => ['ok' => true]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    private function publish(string $version = '2026.1', string $body = "# Overeenkomst\n\nTussen Shopbonus en {{legal_name}} (KvK {{kvk_number}}), {{address}}.", bool $reaccept = true): AgreementTemplate
    {
        $template = AgreementTemplate::create([
            'key' => AgreementTemplate::MERCHANT, 'version' => $version, 'locale' => 'nl', 'title' => 'Merchantovereenkomst',
            'body' => $body, 'status' => AgreementTemplateStatus::Draft, 'requires_reacceptance' => $reaccept, 'created_by' => $this->admin->id,
        ]);
        AgreementTemplate::where('key', $template->key)->where('status', 'published')->update(['status' => 'retired']);
        $template->forceFill(['status' => AgreementTemplateStatus::Published, 'published_at' => now()])->save();

        return $template;
    }

    private function onboardAndVerifyOwnership(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])->assertOk();
        $start = $this->postJson($this->url('/ownership/verifications'), ['method' => 'dns_txt'])->assertCreated();
        $this->dns->txt['_shopbonus-verification.fietsen-utrecht.nl'] = [$start->json('data.instructions.record_value')];
        $this->postJson($this->url('/ownership/verifications/'.$start->json('data.id').'/check'))->assertOk();
    }

    private function acceptCurrent(array $overrides = []): TestResponse
    {
        $agreement = $this->getJson($this->url('/agreement'))->assertOk()->json('data');

        return $this->postJson($this->url('/agreement/accept'), [
            'accept' => true, 'confirm_identity' => true, 'signatory_name' => 'Jan de Vries',
            'version' => $agreement['version'], 'document_hash' => $agreement['document_hash'], ...$overrides,
        ]);
    }

    /**
     * The admin's browser: a stateful SPA request (session) with a recent
     * password confirmation. Bearer-style requests have no session and are
     * refused by password.recent.
     */
    private function asAdminWithStepUp(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    // ---------------------------------------------------------------- E2E 4: agreement

    public function test_e2e4_rendered_agreement_uses_server_data_and_escapes_values(): void
    {
        $this->publish();
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'legal_name' => 'Fietsen <script>alert(1)</script> B.V.', 'confirm' => true])->assertOk();

        $data = $this->getJson($this->url('/agreement'))->assertOk()->json('data');

        $this->assertSame('2026.1', $data['version']);
        $this->assertStringContainsString('KvK 12345678', $data['body']);
        $this->assertStringContainsString('&lt;script&gt;', $data['body']);
        $this->assertStringNotContainsString('<script>', $data['body']);
        $this->assertSame(hash('sha256', $data['body']), $data['document_hash']);
        $this->assertSame('not_started', $data['status']);
    }

    public function test_e2e4_verified_owner_accepts_and_webshop_activates_automatically(): void
    {
        $this->publish();
        $this->onboardAndVerifyOwnership();

        $this->acceptCurrent()->assertCreated()
            ->assertJsonPath('data.version', '2026.1')
            ->assertJsonPath('data.webshop_status', 'approved');

        $acceptance = AgreementAcceptance::sole();
        $this->assertSame(hash('sha256', $acceptance->rendered_body), $acceptance->document_hash);
        $this->assertSame('Jan de Vries', $acceptance->signatory_name);
        $this->assertArrayHasKey('ip_hash', $acceptance->evidence);
        $this->assertSame(WebshopStatus::Approved, $this->shop->fresh()->status);
        $this->assertTrue(AuditEvent::where('action', 'workspace.approved')->where('metadata->automatic', true)->exists());

        $this->getJson($this->url('/_test/commercial'))->assertOk();
        $this->assertSame('complete', collect($this->getJson($this->url('/onboarding'))->json('data.steps'))->firstWhere('key', 'agreement')['status']);

        $this->expectException(LogicException::class);
        $acceptance->update(['signatory_name' => 'Iemand anders']);
    }

    public function test_e2e4_unchecked_forged_or_outdated_acceptance_cannot_activate(): void
    {
        $this->publish();
        $this->onboardAndVerifyOwnership();

        $this->acceptCurrent(['accept' => false])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['accept']]]);
        $this->acceptCurrent(['confirm_identity' => false])->assertStatus(422);
        $this->acceptCurrent(['document_hash' => str_repeat('a', 64)])->assertStatus(409)->assertJsonPath('error.code', 'agreement_outdated');
        $this->acceptCurrent(['version' => '2025.9'])->assertStatus(409)->assertJsonPath('error.details.current_version', '2026.1');

        // The page was loaded, then a new version was published before accepting.
        $stale = $this->getJson($this->url('/agreement'))->json('data');
        $this->publish('2026.2');
        $this->postJson($this->url('/agreement/accept'), ['accept' => true, 'confirm_identity' => true, 'signatory_name' => 'Jan',
            'version' => $stale['version'], 'document_hash' => $stale['document_hash']])
            ->assertStatus(409)->assertJsonPath('error.code', 'agreement_outdated');

        $this->assertSame(0, AgreementAcceptance::count());
        $this->assertNotSame(WebshopStatus::Approved, $this->shop->fresh()->status);
        $this->getJson($this->url('/_test/commercial'))->assertForbidden()->assertJsonPath('error.code', 'workspace_not_active');
    }

    public function test_agreement_requires_owner_details_and_ownership_first(): void
    {
        $this->publish();
        $manager = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $manager->id, 'role' => MembershipRole::Manager, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        Sanctum::actingAs($this->owner);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])->assertOk();
        $this->acceptCurrent()->assertStatus(409)->assertJsonPath('error.code', 'ownership_not_verified');

        Sanctum::actingAs($manager);
        $this->postJson($this->url('/agreement/accept'), ['accept' => true])->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'agreements.accept');
    }

    public function test_no_published_agreement_is_503(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/agreement'))->assertStatus(503)->assertJsonPath('error.code', 'agreement_unavailable');
    }

    public function test_new_version_requiring_reacceptance_restricts_commercial_actions(): void
    {
        $this->publish();
        $this->onboardAndVerifyOwnership();
        $this->acceptCurrent()->assertCreated();
        $this->getJson($this->url('/_test/commercial'))->assertOk();

        $this->publish('2026.2');
        $this->getJson($this->url('/_test/commercial'))->assertForbidden()->assertJsonPath('error.code', 'agreement_reacceptance_required');
        $this->assertSame('outdated', $this->getJson($this->url('/agreement'))->json('data.status'));

        $this->acceptCurrent()->assertCreated();
        $this->getJson($this->url('/_test/commercial'))->assertOk();
        $this->assertSame(2, AgreementAcceptance::count()); // history kept
    }

    public function test_new_version_without_reacceptance_keeps_access(): void
    {
        $this->publish();
        $this->onboardAndVerifyOwnership();
        $this->acceptCurrent()->assertCreated();

        $this->publish('2026.1a', 'Typo fix for {{legal_name}}.', reaccept: false);
        $this->getJson($this->url('/_test/commercial'))->assertOk();
    }

    // ---------------------------------------------------------------- E2E 3: manual review

    public function test_e2e3_unverifiable_owner_stays_pending_until_super_admin_decides(): void
    {
        $this->publish();
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])->assertOk();
        $this->post($this->url('/ownership/review'), ['notes' => 'Our registrar does not support TXT records.',
            'evidence' => [UploadedFile::fake()->createWithContent('kvk.pdf', '%PDF-1.4 extract')]], ['Accept' => 'application/json'])->assertCreated();
        $this->acceptCurrent()->assertCreated()->assertJsonPath('data.webshop_status', 'pending_review');

        // Owner cannot self-approve; nobody but a Super Admin reaches the admin API.
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve")->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');

        // Super Admin without a recent password confirmation is refused (step-up).
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve")->assertStatus(423)
            ->assertJsonPath('error.code', 'password_confirmation_required');

        $this->asAdminWithStepUp();
        $list = $this->getJson('/api/v1/admin/webshops?status=pending_review')->assertOk()->json('data');
        $this->assertSame($this->shop->public_id, $list[0]['id']);

        $detail = $this->getJson("/api/v1/admin/webshops/{$this->shop->public_id}")->assertOk()->json('data');
        $this->assertSame('Our registrar does not support TXT records.', $detail['verifications'][0]['notes']);
        $fileId = $detail['verifications'][0]['evidence_files'][0]['id'];

        $download = $this->get("/api/v1/admin/webshops/{$this->shop->public_id}/evidence/{$fileId}");
        $download->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="kvk.pdf"');
        $this->assertSame('%PDF-1.4 extract', $download->getContent());

        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve", ['reason' => 'KvK extract matches'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.ownership_method', 'manual_review');

        $this->assertSame(WebshopStatus::Approved, $this->shop->fresh()->status);
        // #29: an actual stepped-up approval reaches only its owner's central inbox.
        app(NotificationDispatcher::class)->drain();
        $notice = $this->owner->notifications()->where('event_type', 'workspace_approved')->sole();
        $this->assertSame($this->shop->public_id, $notice->data['workspace_id']);
        $this->assertSame(0, $this->admin->notifications()->where('event_type', 'workspace_approved')->count());
        $this->assertSame(1, $this->admin->notifications()->where('event_type', 'workspace_review_required')->count());
        $this->assertSame(0, $this->owner->notifications()->where('event_type', 'workspace_review_required')->count());
        foreach (['admin.webshop_viewed', 'admin.evidence_downloaded', 'workspace.approved'] as $action) {
            $this->assertTrue(AuditEvent::where('action', $action)->exists(), $action);
        }
    }

    public function test_e2e3_super_admin_rejects_with_reason(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])->assertOk();
        $this->postJson($this->url('/ownership/review'), ['notes' => 'Please review our claim.'])->assertCreated();

        $this->asAdminWithStepUp();
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/reject")->assertStatus(422);
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/reject", ['reason' => 'Ownership could not be established.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_reason', 'Ownership could not be established.');

        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');
    }

    public function test_admin_approval_requires_accepted_agreement_and_explicit_duplicate_confirmation(): void
    {
        $this->publish();
        $rivalOwner = User::factory()->create();
        $rival = Webshop::factory()->withMember($rivalOwner)->create(['created_by' => $rivalOwner->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now()]);

        Sanctum::actingAs($this->owner);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])->assertOk();
        $this->postJson($this->url('/ownership/review'), ['notes' => 'We took over the shop.'])->assertCreated();

        $this->asAdminWithStepUp();
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'agreement_not_accepted');

        Sanctum::actingAs($this->owner);
        $this->acceptCurrent()->assertCreated();

        $this->asAdminWithStepUp();
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'duplicate_claim_unconfirmed');
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/approve", ['confirm_duplicate_claim' => true, 'reason' => 'Takeover confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        // The original domain claim is never silently moved.
        $this->assertSame('fietsen-utrecht.nl', $rival->fresh()->verified_domain);
        $this->assertNull($this->shop->fresh()->verified_domain);
    }

    // ---------------------------------------------------------------- E2E 9: suspension

    public function test_e2e9_suspension_blocks_members_immediately_and_unsuspend_restores(): void
    {
        $this->publish();
        $this->onboardAndVerifyOwnership();
        $this->acceptCurrent()->assertCreated();
        $this->getJson($this->url('/_test/commercial'))->assertOk();

        $this->asAdminWithStepUp();
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/suspend", ['reason' => 'Chargeback investigation'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        Sanctum::actingAs($this->owner);
        $this->getJson($this->url())->assertForbidden()->assertJsonPath('error.code', 'workspace_suspended');
        $this->getJson($this->url('/_test/commercial'))->assertForbidden();

        $this->asAdminWithStepUp();
        $this->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/unsuspend", ['reason' => 'Resolved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/_test/commercial'))->assertOk();
    }

    // ---------------------------------------------------------------- admin access & templates

    public function test_every_admin_route_refuses_non_admins(): void
    {
        $merchant = $this->owner;
        Sanctum::actingAs($merchant);
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/admin/'));
        $this->assertGreaterThanOrEqual(11, $routes->count());

        $sent = 0;
        foreach ($routes as $route) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', (string) str()->ulid(), $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (++$sent % 50 === 0) {
                    $this->travel(61)->seconds(); // stay under the per-minute API rate limit
                }
                $this->json($method, $uri)->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');
            }
        }
    }

    public function test_admin_manages_agreement_templates_and_published_text_is_immutable(): void
    {
        $this->asAdminWithStepUp();

        $this->postJson('/api/v1/admin/agreement-templates', ['key' => 'merchant_agreement', 'version' => '2026.1', 'locale' => 'nl',
            'title' => 'Overeenkomst', 'body' => 'Hallo {{legal_name}} {{bank_account}}'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['body']]]);

        $id = $this->postJson('/api/v1/admin/agreement-templates', ['key' => 'merchant_agreement', 'version' => '2026.1', 'locale' => 'nl',
            'title' => 'Overeenkomst', 'body' => 'Hallo {{legal_name}}'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->patchJson("/api/v1/admin/agreement-templates/{$id}", ['title' => 'Merchantovereenkomst'])->assertOk();
        $this->postJson("/api/v1/admin/agreement-templates/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->patchJson("/api/v1/admin/agreement-templates/{$id}", ['body' => 'Changed'])->assertStatus(409)->assertJsonPath('error.code', 'template_not_draft');

        $this->postJson('/api/v1/admin/agreement-templates', ['key' => 'merchant_agreement', 'version' => '2026.1', 'locale' => 'nl',
            'title' => 'x', 'body' => 'y'])->assertStatus(409)->assertJsonPath('error.code', 'template_version_exists');

        $second = $this->postJson('/api/v1/admin/agreement-templates', ['key' => 'merchant_agreement', 'version' => '2026.2', 'locale' => 'nl',
            'title' => 'Overeenkomst v2', 'body' => 'Hallo {{legal_name}} (v2)'])->json('data.id');
        $this->postJson("/api/v1/admin/agreement-templates/{$second}/publish")->assertOk();
        $this->assertSame(AgreementTemplateStatus::Retired, AgreementTemplate::where('public_id', $id)->sole()->status);

        $this->expectException(LogicException::class);
        AgreementTemplate::where('public_id', $second)->sole()->update(['body' => 'tampered']);
    }

    public function test_e2e10_backend_journey_register_to_active_workspace(): void
    {
        config(['shopbonus.legal.terms_version' => 't1', 'shopbonus.legal.privacy_version' => 'p1']);
        $this->publish();

        $this->postJson('/register', ['account_type' => 'merchant', 'first_name' => 'Sanne', 'email' => 'sanne@fietsen-utrecht.nl',
            'password' => 'Sterk-wachtwoord-2026', 'accept_terms' => true, 'terms_version' => 't1', 'privacy_version' => 'p1'])->assertCreated();
        $user = User::where('email', 'sanne@fietsen-utrecht.nl')->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me')->assertJsonPath('data.destination', 'webshop_setup');

        $this->shop = Webshop::where('public_id', $this->postJson('/api/v1/workspaces', ['name' => 'Fietsenwinkel Utrecht'])->assertCreated()->json('data.id'))->sole();
        $this->owner = $user;
        $this->onboardAndVerifyOwnership();
        $this->acceptCurrent()->assertCreated()->assertJsonPath('data.webshop_status', 'approved');

        $this->getJson('/api/v1/me')->assertJsonPath('data.destination', 'workspace')->assertJsonPath('data.workspaces.0.status', 'approved');
        $this->assertSame(['complete', 'complete', 'complete', 'complete'],
            collect($this->getJson($this->url('/onboarding'))->json('data.steps'))->pluck('status')->all());
        $this->getJson($this->url('/_test/commercial'))->assertOk();
    }
}
