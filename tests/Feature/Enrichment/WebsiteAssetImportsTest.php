<?php

namespace Tests\Feature\Enrichment;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\BannerAsset;
use App\Models\MerchantAssetImport;
use App\Models\MerchantEnrichmentRun;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Enrichment\WebsiteAssetImports;
use App\Services\Enrichment\WebsiteResearcher;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class WebsiteAssetImportsTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private Webshop $shop;

    private MerchantEnrichmentRun $run;

    private string $binary;

    private string $body;

    private string $mime = 'image/png';

    private bool $privateDns = false;

    private int $responseStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->shop = $this->activeWebshop($this->admin, ['verified_domain' => 'shop.example.com']);
        $research = ['source_url' => 'https://shop.example.com/', 'evidence' => ['E4' => ['fact' => 'image_candidate', 'value' => 'https://shop.example.com/photo.png', 'source_url' => 'https://shop.example.com/']]];
        $this->run = MerchantEnrichmentRun::create(['webshop_id' => $this->shop->id, 'status' => 'awaiting_review', 'trigger' => 'admin', 'source_url' => 'https://shop.example.com/', 'research' => $research, 'research_hash' => app(WebsiteResearcher::class)->hash($research), 'text_model' => 'synthetic', 'review_required' => true]);
        $im = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($im);
        $this->binary = ob_get_clean();
        imagedestroy($im);
        $this->body = $this->binary;
        $test = $this;
        $this->app->bind(HostResolver::class, fn () => new class($test) implements HostResolver
        {
            public function __construct(private WebsiteAssetImportsTest $test) {}

            public function addresses(string $host): array
            {
                return $this->test->addresses();
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
        Http::fake(fn () => Http::response($this->body, $this->responseStatus, ['Content-Type' => $this->mime]));
    }

    public function addresses(): array
    {
        return [$this->privateDns ? '127.0.0.1' : '93.184.216.34'];
    }

    private function service(): WebsiteAssetImports
    {
        return app(WebsiteAssetImports::class);
    }

    private function error(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected '.$code);
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function import(): MerchantAssetImport
    {
        return $this->service()->import($this->run, 'E4', $this->admin);
    }

    public function test_import_is_private_until_explicit_rights_review_and_approval_is_idempotent(): void
    {
        $this->body .= 'SYNTHETIC_METADATA_SCRIPT';
        $r = $this->import();
        $this->assertSame('pending', $r->status);
        $this->assertDatabaseCount('banner_assets', 0);
        $this->assertStringNotContainsString('SYNTHETIC_METADATA_SCRIPT', $this->service()->preview($r, $this->admin));
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($this->service()->preview($r, $this->admin))[2]);
        $this->assertSame($r->id, $this->import()->id);
        Http::assertSentCount(1);
        $this->error('asset_review_invalid', fn () => $this->service()->review($r, $this->admin, true, 'Looks appropriate', null));
        $approved = $this->service()->review($r, $this->admin, true, 'Approved image content', 'Merchant licence reference SYNTHETIC-1');
        $this->service()->review($approved, $this->admin, true, 'Replay', 'Same permission');
        $this->assertDatabaseCount('banner_assets', 1);
        $asset = BannerAsset::sole();
        $this->assertSame($this->shop->id, $asset->webshop_id);
        $this->get('/api/v1/banners/assets/'.$asset->public_id)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->error('asset_review_completed', fn () => $this->service()->review($r, $this->admin, false, 'Cannot reverse', null));
    }

    public function test_private_dns_redirect_and_oversized_download_are_refused(): void
    {
        $this->privateDns = true;
        $this->error('asset_download_refused', fn () => $this->import());
        Http::assertNothingSent();
        $this->privateDns = false;
        $this->responseStatus = 302;
        $this->error('asset_download_refused', fn () => $this->import());
        Http::assertSentCount(1);
        $this->responseStatus = 200;
        $this->body = str_repeat('x', 2097153);
        $this->error('asset_download_refused', fn () => $this->import());
        $this->assertDatabaseCount('merchant_asset_imports', 0);
    }

    public function test_svg_fake_raster_and_wrong_content_type_are_not_imported(): void
    {
        $this->body = '<svg onload="alert(1)"></svg>';
        $this->mime = 'image/svg+xml';
        $this->error('asset_download_refused', fn () => $this->import());
        $this->mime = 'image/png';
        $this->error('asset_image_invalid', fn () => $this->import());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_changed_domain_revoked_admin_and_tampered_file_block_approval(): void
    {
        $r = $this->import();
        $this->admin->platformRoles()->delete();
        $this->error('forbidden', fn () => $this->service()->review($r, $this->admin, true, 'Review', 'Licence'));
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Storage::disk('local')->put($r->path, 'tampered');
        $this->error('asset_integrity_failed', fn () => $this->service()->review($r, $this->admin, true, 'Review', 'Licence'));
        $this->shop->forceFill(['verified_domain' => 'changed.example.com'])->save();
        $this->error('asset_source_changed', fn () => $this->service()->review($r, $this->admin, true, 'Review', 'Licence'));
        $this->assertDatabaseCount('banner_assets', 0);
        $this->assertSame('rejected', $this->service()->review($r, $this->admin, false, 'Not suitable', null)->status);
    }

    public function test_unknown_candidates_and_stale_research_never_download(): void
    {
        $this->error('asset_candidate_invalid', fn () => $this->service()->import($this->run, 'UNKNOWN', $this->admin));
        $this->run->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->error('asset_source_changed', fn () => $this->import());
        Http::assertNothingSent();
    }

    public function test_operator_can_export_private_preview_and_reject_without_publishing(): void
    {
        $this->artisan('shopbonus:website-asset', ['action' => 'import', 'id' => $this->run->public_id, '--actor' => $this->admin->public_id, '--evidence' => 'E4'])->assertSuccessful();
        $r = MerchantAssetImport::sole();
        $path = sys_get_temp_dir().'/'.uniqid('b75-preview-', true).'.webp';
        try {
            $this->artisan('shopbonus:website-asset', ['action' => 'show', 'id' => $r->public_id, '--actor' => $this->admin->public_id, '--preview-file' => $path])->assertSuccessful();
            $this->assertFileExists($path);
            $this->artisan('shopbonus:website-asset', ['action' => 'show', 'id' => $r->public_id, '--actor' => $this->admin->public_id, '--preview-file' => $path])->assertFailed();
            $this->artisan('shopbonus:website-asset', ['action' => 'reject', 'id' => $r->public_id, '--actor' => $this->admin->public_id, '--note' => 'No reusable rights'])->assertSuccessful();
            $this->assertDatabaseCount('banner_assets', 0);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_storage_failure_and_inflight_revocation_leave_no_import_or_reusable_asset(): void
    {
        Http::fake(function () {
            $this->admin->platformRoles()->delete();

            return Http::response($this->binary, 200, ['Content-Type' => 'image/png']);
        });
        $this->error('forbidden', fn () => $this->import());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('merchant_asset_imports', 0);
        $this->assertDatabaseCount('banner_assets', 0);
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Http::fake(fn () => Http::response($this->binary, 200, ['Content-Type' => 'image/png']));
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->error('asset_storage_unavailable', fn () => $this->import());
        $this->assertDatabaseCount('merchant_asset_imports', 0);
    }
}
