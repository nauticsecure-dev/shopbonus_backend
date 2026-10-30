<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoucherCode;
use App\Services\Cms\CmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocalIntegrationSetupTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->scratch = sys_get_temp_dir().'/shopbonus-fixture-test-'.bin2hex(random_bytes(8));
        mkdir($this->scratch, 0700);
        $this->app->useStoragePath($this->scratch);
        config(['mail.default' => 'array']);
        Http::preventStrayRequests();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    public function test_explicit_fixture_setup_is_private_rerunnable_and_supports_discovery_and_claims(): void
    {
        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('shopbonus:setup-local-integration');
        $this->assertSame(0, $exit, Artisan::output());
        $file = $this->scratch.'/app/private/local-integration/credentials.json';
        $manifest = json_decode(file_get_contents($file), true);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($file) & 0777);
        }
        $this->assertSame(4, User::count());
        $this->assertSame(10, VoucherCode::count());
        $this->assertTrue(User::where('email', $manifest['accounts']['admin']['email'])->sole()->isSuperAdmin());
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.registration_available', true)->assertJsonPath('data.documents_available', true);
        $this->getJson('/api/v1/public/webshops')->assertOk()->assertJsonFragment(['slug' => 'local-sample-shop']);
        $this->getJson('/api/v1/public/faq')->assertOk()->assertJsonFragment(['key' => 'local-integration']);
        $consumer = User::where('email', $manifest['accounts']['consumer']['email'])->sole();
        $this->assertTrue(Hash::check($manifest['accounts']['consumer']['password'], $consumer->getAuthPassword()));
        Sanctum::actingAs($consumer);
        $this->postJson('/api/v1/consumer/offers/'.$manifest['offer_slug'].'/claim')->assertCreated();
        $this->assertSame(0, Artisan::call('shopbonus:setup-local-integration'), Artisan::output());
        $this->assertSame($manifest, json_decode(file_get_contents($file), true));
        $this->assertSame(4, User::count());
        $this->assertSame(10, VoucherCode::count());
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_existing_legal_drafts_are_preserved_and_registration_is_not_unblocked_by_samples(): void
    {
        $actor = User::factory()->create();
        $page = app(CmsService::class)->create('real-terms-draft', 'terms', 'public', [], $actor->id);
        config(['shopbonus.legal.terms_version' => null]);
        $this->artisan('shopbonus:setup-local-integration')->assertSuccessful();
        $this->assertDatabaseMissing('cms_pages', ['key' => 'local-integration-terms']);
        $this->assertDatabaseHas('cms_pages', ['id' => $page->id, 'key' => 'real-terms-draft']);
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.registration_available', false);
    }

    public function test_production_or_outbound_mail_is_rejected_before_creating_accounts(): void
    {
        config(['mail.default' => 'smtp']);
        $this->artisan('shopbonus:setup-local-integration')->assertFailed();
        config(['mail.default' => 'array']);
        $this->app->instance('env', 'production');
        $this->artisan('shopbonus:setup-local-integration')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_existing_identity_is_never_reset_or_granted_a_role(): void
    {
        $user = User::factory()->create(['email' => 'local-admin@shopbonus.example.test']);
        $password = $user->getAuthPassword();
        $this->artisan('shopbonus:setup-local-integration')->assertFailed();
        $this->assertSame($password, $user->fresh()->getAuthPassword());
        $this->assertFalse($user->fresh()->isSuperAdmin());
        $this->assertDatabaseCount('users', 1);
    }
}
