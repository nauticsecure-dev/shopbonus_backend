<?php

namespace Tests\Feature\Cms;

use App\Enums\MembershipRole;
use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\CmsPage;
use App\Models\User;
use App\Services\Identity\LegalConsents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * B15a: CMS pages, legal versions and FAQ (Trello #5 E2E 2–8).
 */
class CmsTest extends TestCase
{
    use CreatesActiveWebshops, InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function doc(string $text, array $extra = []): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Kop']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text], ['type' => 'text', 'text' => ' meer', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/hulp']]]]]], ...$extra]];
    }

    private function content(string $title, string $text = 'Welkom bij ShopBonus.'): array
    {
        return ['title' => $title, 'seo_title' => $title, 'seo_description' => 'Omschrijving', 'body' => $this->doc($text)];
    }

    private function page(string $key = 'over-ons', string $type = 'info'): string
    {
        $this->asAdmin();

        return $this->postJson('/api/v1/admin/cms/pages', ['key' => $key, 'type' => $type, 'locales' => [
            'nl' => ['slug' => $key, 'content' => $this->content('Over ons')], 'en' => ['slug' => $key.'-en', 'content' => $this->content('About us', 'Welcome to ShopBonus.')],
        ]])->assertCreated()->json('data.id');
    }

    public function test_card5_e2e2_and_e2e4_drafts_are_isolated_and_publishing_updates_only_that_locale(): void
    {
        $id = $this->page();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertNotFound(); // nothing published yet

        $this->asAdmin();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertOk()->assertJsonPath('data.number', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertOk()->assertJsonPath('data.content.title', 'Over ons')->assertJsonPath('data.alternates', ['nl' => 'over-ons']);

        $this->asAdmin();
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Nieuwe kop (concept)')])->assertOk()->assertJsonPath('data.draft_revision', 2);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertJsonPath('data.content.title', 'Over ons'); // draft invisible

        $this->asAdmin();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertOk()->assertJsonPath('data.number', 2);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertJsonPath('data.content.title', 'Nieuwe kop (concept)'); // cache invalidated
        $this->getJson('/api/v1/public/pages/en/over-ons-en')->assertNotFound(); // EN untouched
        $this->assertSame(2, AuditEvent::where('action', 'cms.published')->count());
    }

    public function test_card5_e2e3_preview_is_admin_only_and_never_cached(): void
    {
        $id = $this->page();
        $this->getJson("/api/v1/admin/cms/pages/{$id}/locales/en/preview")->assertOk()->assertJsonPath('data.content.title', 'About us')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $owner = User::factory()->create();
        $this->activeWebshop($owner);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/admin/cms/pages/{$id}/locales/en/preview")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/admin/cms/pages/{$id}/locales/en/preview")->assertUnauthorized();
    }

    public function test_card5_e2e5_concurrent_edits_conflict_and_restore_makes_a_new_revision(): void
    {
        $id = $this->page();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertOk();
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Versie twee')])->assertOk(); // admin A
        $this->putJson("/api/v1/admin/cms/pages/{$id}/locales/nl", ['revision' => 1, 'content' => $this->content('Versie van B')])->assertStatus(409) // admin B, stale
            ->assertJsonPath('error.code', 'draft_revision_conflict')->assertJsonPath('error.details.current_revision', 2);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertOk();

        $first = collect($this->getJson("/api/v1/admin/cms/pages/{$id}/revisions")->json('data'))->firstWhere('number', 1)['id'];
        $this->postJson("/api/v1/admin/cms/pages/{$id}/revisions/{$first}/restore")->assertOk()->assertJsonPath('data.number', 3)->assertJsonPath('data.restored_from', 1);
        $this->assertSame([3, 2, 1], array_column(array_filter($this->getJson("/api/v1/admin/cms/pages/{$id}/revisions")->json('data'), fn ($r) => $r['locale'] === 'nl'), 'number'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/over-ons')->assertJsonPath('data.content.title', 'Over ons');
    }

    public function test_legal_pages_need_version_date_and_approval_and_drive_registration(): void
    {
        $id = $this->page('voorwaarden', 'terms');
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertUnprocessable()->assertJsonPath('error.code', 'legal_approval_required');
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish", ['legal_version' => '2026.2', 'effective_date' => '2026-11-01'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish", ['legal_version' => '2026.2', 'effective_date' => '2026-11-01', 'approve' => true])->assertOk()
            ->assertJsonPath('data.legal_version', '2026.2')->assertJsonPath('data.approved_by', $this->admin->first_name);
        $this->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish", ['legal_version' => '2026.2', 'effective_date' => '2026-11-01', 'approve' => true])->assertStatus(409);
        $first = $this->getJson("/api/v1/admin/cms/pages/{$id}/revisions")->json('data.0.id');
        $this->postJson("/api/v1/admin/cms/pages/{$id}/revisions/{$first}/restore")->assertStatus(409)->assertJsonPath('error.code', 'legal_restore_not_allowed');
        $this->postJson("/api/v1/admin/cms/pages/{$id}/archive")->assertStatus(409);

        // Registration now requires the CMS version instead of the env one.
        $this->assertSame('2026.2', app(LegalConsents::class)->requiredVersions()['terms']);
        $this->assertSame('privacy-2026-10', app(LegalConsents::class)->requiredVersions()['privacy']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/pages/nl/voorwaarden')->assertJsonPath('data.legal', ['version' => '2026.2', 'effective_date' => '2026-11-01']);
    }

    public function test_card5_e2e6_faq_order_search_and_audience_are_enforced_by_the_api(): void
    {
        $this->asAdmin();
        $cat = $this->postJson('/api/v1/admin/cms/faq/categories', ['key' => 'vouchers', 'names' => ['nl' => 'Vouchers', 'en' => 'Vouchers']])->assertCreated()->json('data.id');
        $mk = fn (string $q, string $aud, string $answer) => $this->postJson('/api/v1/admin/cms/faq/items', ['category_id' => $cat, 'audience' => $aud, 'published' => true,
            'translations' => ['nl' => ['question' => $q, 'answer' => $this->doc($answer)], 'en' => ['question' => $q.' (EN)', 'answer' => $this->doc($answer)]]])->assertCreated()->json('data.id');
        $a = $mk('Hoe claim ik een voucher?', 'public', 'Via de link.');
        $b = $mk('Waar staat mijn code?', 'consumers', 'In Mijn vouchers.');
        $c = $mk('Hoe importeer ik codes?', 'merchants', 'Via Offers & Codes.');
        $this->postJson('/api/v1/admin/cms/faq/reorder', ['category_id' => $cat, 'items' => [$b, $a, $c]])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertSame(['Hoe claim ik een voucher?'], array_column($this->getJson('/api/v1/public/faq')->json('data.0.items'), 'question'));
        $this->assertSame(['Hoe claim ik een voucher? (EN)'], array_column($this->getJson('/api/v1/public/faq?locale=en')->json('data.0.items'), 'question'));

        Sanctum::actingAs(User::factory()->create());
        $this->assertSame([$b, $a], array_column($this->getJson('/api/v1/public/faq')->json('data.0.items'), 'id'));
        $owner = User::factory()->create();
        $this->activeWebshop($owner);
        Sanctum::actingAs($owner);
        $this->assertSame([$b, $a, $c], array_column($this->getJson('/api/v1/public/faq')->json('data.0.items'), 'id'));
        $this->assertSame([$c], array_column($this->getJson('/api/v1/public/faq?search=importeer')->json('data.0.items'), 'id'));
        $this->assertSame([$a], array_column($this->getJson('/api/v1/public/faq?search=link')->json('data.0.items'), 'id')); // searches answers too
    }

    public function test_card5_e2e7_only_super_admins_can_change_content(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $shop->memberships()->create(['user_id' => $staff = User::factory()->create()->id, 'role' => MembershipRole::Manager, 'status' => 'active', 'joined_at' => now()]);
        foreach ([$owner, User::find($staff), User::factory()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/admin/cms/pages', ['key' => 'x', 'type' => 'info', 'locales' => ['nl' => ['slug' => 'x', 'content' => $this->content('X')]]])
                ->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/cms/pages', [])->assertUnauthorized();
        $this->assertSame(0, CmsPage::count());
        $this->assertGreaterThanOrEqual(3, AuditEvent::where('http_status', 403)->where('route', 'like', '%admin/cms%')->count());

        // Admin without a recent password confirmation (step-up) cannot publish.
        $id = $this->page();
        Sanctum::actingAs($this->admin);
        $this->flushSession();
        $this->withSession([])->postJson("/api/v1/admin/cms/pages/{$id}/locales/nl/publish")->assertStatus(423);
    }

    public function test_card5_e2e8_unsafe_content_and_fake_images_are_refused(): void
    {
        $this->asAdmin();
        $bad = [
            ['type' => 'doc', 'content' => [['type' => 'html', 'content' => '<script>alert(1)</script>']]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(1)'], 'content' => [['type' => 'text', 'text' => 'x']]]]],
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '//evil.example']]]]]]]],
            ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['asset_id' => '01jxxxxxxxxxxxxxxxxxxxxxxx', 'alt' => 'x']]]],
        ];
        foreach ($bad as $i => $doc) {
            $this->postJson('/api/v1/admin/cms/pages', ['key' => "bad-{$i}", 'type' => 'info', 'locales' => ['nl' => ['slug' => "bad-{$i}", 'content' => ['title' => 'X', 'body' => $doc]]]])->assertUnprocessable();
        }
        $this->postJson('/api/v1/admin/cms/pages', ['key' => 'bad-title', 'type' => 'info', 'locales' => ['nl' => ['slug' => 'bad-title', 'content' => ['title' => '<img src=x>', 'body' => $this->doc('x')]]]])->assertUnprocessable();
        $this->assertSame(0, CmsPage::count());

        $this->postJson('/api/v1/admin/cms/assets', ['image' => UploadedFile::fake()->create('x.png', 10, 'image/png')])->assertUnprocessable();
        $this->postJson('/api/v1/admin/cms/assets', ['image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')])->assertUnprocessable();
        $asset = $this->postJson('/api/v1/admin/cms/assets', ['image' => UploadedFile::fake()->image('hero.jpg', 1200, 600)])->assertCreated()->json('data.id');

        // A page with a real image renders its URL.
        $this->postJson('/api/v1/admin/cms/pages', ['key' => 'beeld', 'type' => 'landing', 'locales' => ['nl' => ['slug' => 'beeld', 'content' => ['title' => 'Beeld',
            'body' => $this->doc('x', [['type' => 'image', 'attrs' => ['asset_id' => $asset, 'alt' => 'Hero']]])]]]])->assertCreated();
        $page = CmsPage::where('key', 'beeld')->sole();
        $this->postJson("/api/v1/admin/cms/pages/{$page->public_id}/locales/nl/publish")->assertOk();
        $this->app['auth']->forgetGuards();
        $img = collect($this->getJson('/api/v1/public/pages/nl/beeld')->json('data.content.body.content'))->firstWhere('type', 'image');
        $this->assertStringContainsString('/api/v1/public/content-assets/'.$asset, $img['attrs']['src']);
    }
}
