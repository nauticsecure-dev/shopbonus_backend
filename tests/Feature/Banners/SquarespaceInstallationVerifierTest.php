<?php

namespace Tests\Feature\Banners;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Banners\BannerService;
use App\Services\Banners\SquarespaceInstallationVerifier;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/** Squarespace verified-manual publication: snippet, served-HTML presence and absence. Storefront faked. */
class SquarespaceInstallationVerifierTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private const SITE = '5863f6faf7e0abc3cf95ce3a';

    private User $owner;

    private Webshop $shop;

    private WebshopConnection $connection;

    private string $html = '<html><body>Store</body></html>';

    private int $status = 200;

    /** @var list<string> */
    private array $addresses = ['93.184.216.34'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.example.com']);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'shop.example.com']);
        [$this->connection] = app(ConnectionManager::class)->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), EcommercePlatform::Squarespace,
            ['website_id' => self::SITE, 'api_key' => 'SYNTHETIC_squarespace_key']);
        $test = $this;
        $this->app->bind(HostResolver::class, fn () => new class($test) implements HostResolver
        {
            public function __construct(private SquarespaceInstallationVerifierTest $test) {}

            public function addresses(string $host): array
            {
                return $this->test->resolved();
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            if (str_starts_with($r->url(), 'https://api.squarespace.com/')) {
                return Http::response(str_contains($r->url(), '/authorization/website') ? ['id' => self::SITE, 'url' => 'https://shop.example.com'] : ['result' => [], 'pagination' => ['hasNextPage' => false]]);
            }
            $this->assertSame('https://shop.example.com/', $r->url());

            return Http::response($this->html, $this->status);
        });
    }

    /** @return list<string> */
    public function resolved(): array
    {
        return $this->addresses;
    }

    private function verifier(): SquarespaceInstallationVerifier
    {
        return app(SquarespaceInstallationVerifier::class);
    }

    public function test_install_returns_exact_snippet_and_reconcile_verifies_served_html(): void
    {
        $key = app(BannerService::class)->installation($this->shop)->public_key;
        $result = $this->verifier()->install($this->connection, $this->owner);
        $this->assertSame('manual_installation_required', $result['state']);
        $this->assertSame('<script defer src="https://api.example.com/embed/v1.js" data-shopbonus-key="'.$key.'"></script>', $result['snippet']);
        $this->assertSame('remote_missing', $this->verifier()->reconcile($this->connection, $this->owner)['state']);
        $this->html = '<html><body>Store'.$result['snippet'].'</body></html>';
        $this->assertSame('native_configured', $this->verifier()->reconcile($this->connection, $this->owner)['state']);
        $this->assertSame(1, AuditEvent::where('action', 'integration.banner_sq_verified')->count());
    }

    public function test_remove_requires_observed_absence_including_altered_snippets(): void
    {
        $key = app(BannerService::class)->installation($this->shop)->public_key;
        $this->html = '<script async src="https://api.example.com/embed/v1.js" data-shopbonus-key="'.$key.'"></script>';
        $this->assertSame('removal_pending', $this->verifier()->remove($this->connection, $this->owner)['state']);
        $this->html = '<html></html>';
        $this->assertSame('removed', $this->verifier()->remove($this->connection, $this->owner)['state']);
    }

    public function test_redirects_errors_private_addresses_and_unauthorized_actors_fail_closed(): void
    {
        $this->status = 301;
        $this->assertCode('native_banner_unconfirmed', fn () => $this->verifier()->reconcile($this->connection, $this->owner));
        $this->status = 200;
        $this->addresses = ['10.0.0.5'];
        $this->assertCode('provider_address_refused', fn () => $this->verifier()->reconcile($this->connection, $this->owner));
        $this->addresses = ['93.184.216.34'];
        $this->assertCode('native_banner_forbidden', fn () => $this->verifier()->reconcile($this->connection, User::factory()->create()));
    }

    private function assertCode(string $code, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Expected '.$code);
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
