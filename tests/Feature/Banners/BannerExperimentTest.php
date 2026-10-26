<?php

namespace Tests\Feature\Banners;

use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerMeasurement;
use App\Services\Banners\BannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

class BannerExperimentTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private BannerCampaign $campaign;

    private BannerInstallation $installation;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'example.nl']);
        $banners = app(BannerService::class);
        $this->campaign = $banners->create($this->shop, 'Test', null, BannerFixture::config(), $this->owner->id);
        $banners->publish($this->campaign, $this->owner->id);
        $config = $this->campaign->draft_config;
        $config['placement']['anchor'] = 'bottom';
        $banners->saveDraft($this->campaign, $config, 1, $this->owner->id);
        $banners->publish($this->campaign, $this->owner->id);
        $this->installation = $banners->installation($this->shop);
        $this->url = "/api/v1/workspaces/{$this->shop->public_id}/banners/{$this->campaign->public_id}/experiment";
        Sanctum::actingAs($this->owner);
    }

    private function start(bool $holdout = false): void
    {
        $this->postJson($this->url, ['control_version' => $holdout ? null : 1, 'variant_version' => 2, 'variant_bps' => 5000])->assertCreated();
    }

    private function assign(string $visitor): array
    {
        return $this->postJson('/api/v1/banners/assignments', ['key' => $this->installation->public_key, 'visitor_key' => $visitor, 'consent' => true], ['Origin' => 'https://example.nl'])
            ->assertOk()->json('data.0');
    }

    private function event(array $assignment, string $type = 'viewable_impression'): array
    {
        return ['id' => (string) Str::uuid(), 'campaign' => $this->campaign->public_id, 'version' => $assignment['version'], 'type' => $type,
            'occurred_at' => now()->toIso8601String(), 'device' => 'desktop', 'consent' => true, 'assignment_token' => $assignment['assignment_token']];
    }

    public function test_explicit_launch_pinned_variants_stable_assignment_and_stop(): void
    {
        $this->start();
        $visitor = (string) Str::uuid();
        $a = $this->assign($visitor);
        $this->assertSame($a, $this->assign($visitor));
        $this->assertDatabaseCount('banner_assignments', 1);
        $this->assertStringNotContainsString($visitor, json_encode(DB::table('banner_assignments')->first()));
        $this->assertSame([], app(BannerMeasurement::class)->publishedConfig($this->installation));
        $rows = app(BannerMeasurement::class)->publishedConfig($this->installation, [$this->campaign->public_id => $a['assignment_token']]);
        $this->assertSame($a['version'], $rows[0]['version']);
        $this->postJson(str_replace('/experiment', '/publish', $this->url))->assertStatus(409)->assertJsonPath('error.code', 'banner_experiment_active');
        $this->postJson($this->url.'/stop')->assertOk()->assertJsonPath('data.active', false);
        $this->assertCount(1, app(BannerMeasurement::class)->publishedConfig($this->installation));
        $this->assertSame([], $this->postJson('/api/v1/banners/assignments', ['key' => $this->installation->public_key, 'visitor_key' => $visitor, 'consent' => true], ['Origin' => 'https://example.nl'])->json('data'));
    }

    public function test_holdout_never_renders_or_counts_impressions_and_events_require_bound_token(): void
    {
        $this->start(true);
        $control = null;
        foreach (range(1, 50) as $_) {
            $a = $this->assign((string) Str::uuid());
            if ($a['holdout']) {
                $control = $a;
                break;
            }
        }
        $this->assertNotNull($control);
        $this->assertSame([], app(BannerMeasurement::class)->publishedConfig($this->installation, [$this->campaign->public_id => $control['assignment_token']]));
        $events = [$this->event($control), $this->event($control, 'eligible')];
        $r = app(BannerMeasurement::class)->ingest($this->installation, $events);
        $this->assertSame([1, 1], [$r['accepted'], $r['rejected']]);
        $r = app(BannerMeasurement::class)->ingest($this->installation, [$events[1]]);
        $this->assertSame(1, $r['duplicates']);
        $invalid = $this->event($control, 'eligible');
        $invalid['assignment_token'] = 'bx_'.str_repeat('0', 64);
        $this->assertSame(1, app(BannerMeasurement::class)->ingest($this->installation, [$invalid])['rejected']);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.arms.control.viewable_impressions', 0)->assertJsonPath('data.causal_uplift', null);
    }

    public function test_pairing_and_editor_tokens_recheck_current_membership(): void
    {
        $staff = User::factory()->create();
        $membership = $this->shop->memberships()->create(['user_id' => $staff->id, 'role' => 'manager', 'status' => 'active']);
        Sanctum::actingAs($staff);
        $path = "/api/v1/workspaces/{$this->shop->public_id}/banners/pairing";
        $first = $this->postJson($path, ['origin' => 'https://example.nl'])->assertCreated()->json('data.pairing_token');
        $editor = $this->postJson('/api/v1/banners/editor/pair', ['pairing_token' => $first], ['Origin' => 'https://example.nl'])->assertOk()->json('data.editor_token');
        $second = $this->postJson($path, ['origin' => 'https://example.nl'])->assertCreated()->json('data.pairing_token');
        $membership->update(['status' => 'removed']);
        $this->getJson('/api/v1/banners/editor/campaigns/'.$this->campaign->public_id, ['Origin' => 'https://example.nl', 'X-Banner-Editor-Token' => $editor])->assertStatus(401)->assertJsonPath('error.code', 'editor_access_revoked');
        $this->postJson('/api/v1/banners/editor/pair', ['pairing_token' => $second], ['Origin' => 'https://example.nl'])->assertStatus(401)->assertJsonPath('error.code', 'editor_access_revoked');
        $this->putJson('/api/v1/banners/editor/campaigns/'.$this->campaign->public_id, ['revision' => $this->campaign->draft_revision, 'config' => BannerFixture::config()], ['Origin' => 'https://example.nl', 'X-Banner-Editor-Token' => $editor])->assertStatus(401);
    }

    public function test_consent_origin_tenant_version_and_allocation_validation(): void
    {
        $this->postJson($this->url, ['control_version' => 1, 'variant_version' => 1, 'variant_bps' => 5000])->assertStatus(422);
        $this->postJson($this->url, ['control_version' => 1, 'variant_version' => 2, 'variant_bps' => 0])->assertStatus(422);
        $this->start();
        $this->postJson('/api/v1/banners/assignments', ['key' => $this->installation->public_key, 'visitor_key' => (string) Str::uuid(), 'consent' => false], ['Origin' => 'https://example.nl'])->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/v1/banners/assignments', ['key' => $this->installation->public_key, 'visitor_key' => (string) Str::uuid(), 'consent' => true], ['Origin' => 'https://foreign.example'])->assertStatus(403);
        $this->assertDatabaseCount('banner_assignments', 0);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->url)->assertStatus(404);
    }

    public function test_consent_withdrawal_revokes_assignment_and_does_not_resurrect_it(): void
    {
        $this->start();
        $visitor = (string) Str::uuid();
        $a = $this->assign($visitor);
        $this->shop->forceFill(['status' => 'suspended'])->save(); // Privacy withdrawal remains possible after commercial suspension.
        $this->postJson('/api/v1/banners/assignments', ['key' => $this->installation->public_key, 'visitor_key' => $visitor, 'consent' => false], ['Origin' => 'https://example.nl'])
            ->assertOk()->assertJsonPath('data', []);
        $this->assertSame([], app(BannerMeasurement::class)->publishedConfig($this->installation, [$this->campaign->public_id => $a['assignment_token']]));
        $this->assertSame(1, app(BannerMeasurement::class)->ingest($this->installation, [$this->event($a)])['rejected']);
        $this->shop->forceFill(['status' => 'approved'])->save();
        $this->assertSame([], $this->postJson('/api/v1/banners/assignments', ['key' => $this->installation->public_key, 'visitor_key' => $visitor, 'consent' => true], ['Origin' => 'https://example.nl'])->json('data'));
    }

    public function test_signed_order_ingestion_carries_assignment_without_accepting_forged_payment(): void
    {
        $this->start(true);
        $a = $this->assign((string) Str::uuid());
        $created = $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $path = parse_url($created->json('data.setup.webhook_url'), PHP_URL_PATH);
        $secret = collect($created->json('data.secrets'))->first();
        $body = json_encode(['order' => ['id' => 'verified-order', 'reference' => 'R', 'customer' => ['first_name' => 'Fixture', 'email' => 'fixture@example.test'],
            'total_minor' => 10000, 'currency' => 'EUR', 'payment_status' => 'paid', 'created_at' => now()->toIso8601String(), 'paid_at' => now()->toIso8601String(),
            'attribution' => ['assignment_token' => $a['assignment_token']]]]);
        $timestamp = time();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SHOPBONUS_EVENT_ID' => 'experiment-order', 'HTTP_X_SHOPBONUS_TOPIC' => 'order.paid'];
        $server['HTTP_X_SHOPBONUS_SIGNATURE'] = 't='.$timestamp.',v1='.str_repeat('0', 64);
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(401);
        $server['HTTP_X_SHOPBONUS_SIGNATURE'] = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(202);
        $this->assertNotNull(WebshopOrder::sole()->getAttribute('banner_assignment_id'));
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.arms.'.$a['arm'].'.verified_paid_orders', 1);
        $this->call('POST', $path, [], [], [], $server, $body)->assertOk();
        $this->assertDatabaseCount('webshop_orders', 1);
    }

    public function test_verified_paid_orders_match_assignment_only_in_same_tenant_and_time_window(): void
    {
        $this->start(true);
        $a = $this->assign((string) Str::uuid());
        $service = app(BannerExperiments::class);
        $assignmentId = $service->orderAssignment($this->shop->id, $a['assignment_token']);
        $this->assertNull($service->orderAssignment($this->shop->id + 10000, $a['assignment_token']));
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::sole();
        foreach (['paid', 'pending'] as $status) {
            WebshopOrder::create(['webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'external_order_id' => $status,
                'ingestion_status' => 'complete', 'currency' => 'EUR', 'amount_minor' => 10000, 'refunded_minor' => 1000,
                'payment_status' => $status, 'paid_at' => $status === 'paid' ? now() : null, 'banner_assignment_id' => $assignmentId]);
        }
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.arms.'.$a['arm'].'.verified_paid_orders', 1)->assertJsonPath('data.arms.'.$a['arm'].'.net_revenue_minor', 9000);
        DB::table('banner_assignments')->where('id', $assignmentId)->update(['expires_at' => now()->subDay()]);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.arms.'.$a['arm'].'.verified_paid_orders', 0);
    }
}
