<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\CapabilityMatrix;
use App\Models\CatalogSync;
use App\Models\IntegrationOrderRecovery;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Catalog\ConnectionCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_evidence_is_feature_and_generation_specific_and_resolvable(): void
    {
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id, 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt', 'verified_domain' => 'synthetic.example.test']);
        $connection = WebshopConnection::create(['webshop_id' => $shop->id, 'platform' => 'ecwid', 'method' => 'private_module', 'status' => 'limited', 'webhook_token' => 'synthetic', 'created_by' => $owner->id]);
        Sanctum::actingAs($owner);
        $sync = CatalogSync::create(['connection_id' => $connection->id, 'credential_generation' => $connection->credential_generation, 'kind' => 'products', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        $report = fn () => collect(app(ConnectionCapabilities::class)->report($connection->fresh()))->keyBy('capability');
        $rows = $report();
        $this->assertSame('AVAILABLE', $rows['products']['status']);
        $this->assertSame($sync->public_id, $rows['products']['evidence_id']);
        $this->assertNull($rows['payments']['evidence_id']);
        $this->assertSame('LIMITED', $rows['payments']['status']);
        $this->getJson($rows['products']['source_url'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sync->public_id);
        $this->getJson(str_replace($sync->public_id, 'invalid', $rows['products']['source_url']))->assertUnprocessable();
        $this->getJson(str_replace($sync->public_id, (string) Str::ulid(), $rows['products']['source_url']))->assertOk()->assertJsonCount(0, 'data');
        $failed = CatalogSync::create(['connection_id' => $connection->id, 'credential_generation' => $connection->credential_generation, 'kind' => 'products', 'status' => 'failed', 'error_code' => 'provider_timeout', 'started_at' => now(), 'finished_at' => now()]);
        $this->assertSame('provider_timeout', $report()['products']['error_code']);
        $this->assertSame($failed->public_id, $report()['products']['evidence_id']);
        $connection->increment('credential_generation');
        $this->assertNull($report()['products']['evidence_id']);
        $connection->forceFill(['paused_at' => now()])->save();
        $this->assertSame('connection_paused', $report()['products']['error_code']);
        $foreign = User::factory()->create();
        Sanctum::actingAs($foreign);
        $this->getJson($rows['products']['source_url'])->assertNotFound();
    }

    public function test_native_order_evidence_cannot_prove_payment_refund_or_another_store(): void
    {
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id, 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt', 'verified_domain' => 'synthetic.example.test']);
        $connection = WebshopConnection::create(['webshop_id' => $shop->id, 'platform' => 'shopify', 'method' => 'private_module', 'status' => 'limited', 'webhook_token' => 'synthetic', 'external_shop_ref' => 'synthetic.myshopify.com', 'created_by' => $owner->id]);
        $event = WebhookEvent::create(['webshop_id' => $shop->id, 'connection_id' => $connection->id, 'credential_generation' => 1, 'source_method' => 'native_api', 'dedupe_key' => 'synthetic-evidence', 'payload_hash' => hash('sha256', 'synthetic'), 'status' => 'processed', 'received_at' => now(), 'processed_at' => now(), 'result' => ['ingested' => true, 'complete' => true, 'native_refund_recovered' => true]]);
        IntegrationOrderRecovery::create(['webshop_id' => $shop->id, 'connection_id' => $connection->id, 'credential_generation' => 1, 'verified_domain' => $shop->verified_domain, 'provider_store_ref' => $connection->external_shop_ref, 'external_order_id' => '4711', 'request_key' => Str::uuid()->toString(), 'status' => 'succeeded', 'webhook_event_id' => $event->id]);
        $report = fn () => collect(app(ConnectionCapabilities::class)->report($connection->fresh()))->keyBy('capability');
        $this->assertSame($event->public_id, $report()['orders']['evidence_id']);
        $this->assertNull($report()['payments']['evidence_id']);
        $this->assertNull($report()['refunds']['evidence_id']);
        $this->assertNull($report()['webhooks']['evidence_id']);
        $event->forceFill(['result' => ['ingested' => true, 'complete' => true, 'native_payment_evidence' => true, 'native_refund_cash_evidence' => true, 'native_refund_recovered' => true]])->save();
        $this->assertSame($event->public_id, $report()['payments']['evidence_id']);
        $this->assertSame($event->public_id, $report()['refunds']['evidence_id']);
        $connection->forceFill(['external_shop_ref' => 'different.myshopify.com'])->save();
        $this->assertNull($report()['orders']['evidence_id']);
        $connection->forceFill(['status' => 'disconnected'])->save();
        $this->assertSame('connection_disconnected', $report()['orders']['error_code']);
    }

    public function test_declarations_do_not_claim_observation_evidence(): void
    {
        foreach (app(CapabilityMatrix::class)->forPlatform(EcommercePlatform::Shopify) as $row) {
            $this->assertArrayHasKey('source_url', $row);
            $this->assertArrayHasKey('error_code', $row);
            $this->assertNull($row['evidence_id']);
            $this->assertNull($row['last_verified_at']);
            $this->assertSame('implementation_declaration_not_provider_acceptance', $row['evidence_scope']);
        }
    }
}
