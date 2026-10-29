<?php

namespace Tests\Feature\Integrations;

use App\Models\AuditEvent;
use App\Models\ShopifyComplianceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShopifyComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SYNTHETIC_client_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.shopify_oauth.client_secret' => self::SECRET]);
    }

    /** @param array<string,string> $headers */
    private function send(string $topic, string $body, array $headers = [], ?string $secret = self::SECRET): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'b54557e4-bdd9-4b37-8a5f-bf7d70bcd043', 'HTTP_X_SHOPIFY_EVENT_ID' => '98880550-7158-44d4-b7cd-2c97c8a091b5',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'fietsen.myshopify.com'];
        if ($secret !== null) {
            $server['HTTP_X_SHOPIFY_HMAC_SHA256'] = base64_encode(hash_hmac('sha256', $body, $secret, true));
        }
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', '/api/v1/integrations/shopify/compliance', [], [], [], $server, $body);
    }

    private function redactBody(): string
    {
        return json_encode(['shop_id' => 954889, 'shop_domain' => 'fietsen.myshopify.com', 'customer' => ['id' => 191167, 'email' => 'jan@example.com', 'phone' => '555-625-1199'], 'orders_to_redact' => [299938, 280263]]);
    }

    public function test_signed_requests_for_all_three_topics_are_stored_encrypted_without_touching_data(): void
    {
        $bodies = [
            'customers/redact' => $this->redactBody(),
            'customers/data_request' => json_encode(['shop_id' => 954889, 'shop_domain' => 'fietsen.myshopify.com', 'orders_requested' => [299938], 'customer' => ['id' => 191167, 'email' => 'jan@example.com', 'phone' => null], 'data_request' => ['id' => 9999]]),
            'shop/redact' => json_encode(['shop_id' => 954889, 'shop_domain' => 'fietsen.myshopify.com']),
        ];
        foreach ($bodies as $topic => $body) {
            $this->send($topic, $body, ['X-Shopify-Webhook-Id' => (string) Str::uuid()])->assertOk()->assertExactJson(['data' => ['received' => true, 'duplicate' => false]]);
        }
        $this->assertSame(3, ShopifyComplianceRequest::where('payload_valid', true)->where('status', 'pending')->where('shop_domain', 'fietsen.myshopify.com')->count());
        $r = ShopifyComplianceRequest::where('topic', 'customers/redact')->sole();
        $this->assertSame($bodies['customers/redact'], $r->payload);
        $this->assertSame(954889, $r->shop_id);
        $this->assertTrue($r->due_at->equalTo($r->received_at->addDays(30)));
        // Encrypted at rest; the audit trail carries no customer data.
        $this->assertStringNotContainsString('jan@example.com', (string) DB::table('shopify_compliance_requests')->where('id', $r->id)->value('payload'));
        $this->assertSame(3, AuditEvent::where('action', 'integration.shopify_compliance_received')->count());
        $this->assertStringNotContainsString('jan@example.com', AuditEvent::pluck('metadata')->toJson());
    }

    public function test_redelivery_is_acknowledged_once_and_counted(): void
    {
        $this->send('customers/redact', $this->redactBody())->assertOk();
        $this->send('customers/redact', $this->redactBody())->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(2, ShopifyComplianceRequest::sole()->delivery_count);
        $this->assertSame(1, AuditEvent::where('action', 'integration.shopify_compliance_received')->count());
    }

    public function test_invalid_or_missing_hmac_and_bad_headers_return_401_and_store_nothing(): void
    {
        $this->send('customers/redact', $this->redactBody(), [], 'SYNTHETIC_other_secret_value')->assertStatus(401)->assertJsonPath('error.code', 'webhook_rejected');
        $this->send('customers/redact', $this->redactBody(), [], null)->assertStatus(401);
        $this->send('customers/redact', $this->redactBody(), ['X-Shopify-Hmac-Sha256' => 'not base64!'], null)->assertStatus(401);
        // A signed body replayed under a non-compliance topic or without a delivery ID is refused.
        $this->send('orders/create', $this->redactBody())->assertStatus(401)->assertJsonPath('error.details.reason', 'invalid_event_headers');
        $this->send('customers/redact', $this->redactBody(), ['X-Shopify-Webhook-Id' => ''])->assertStatus(401);
        $this->assertDatabaseCount('shopify_compliance_requests', 0);
        $this->assertSame(2, AuditEvent::where('action', 'integration.shopify_compliance_rejected')->count());
    }

    public function test_tampered_body_fails_signature(): void
    {
        $body = $this->redactBody();
        $sig = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));
        $this->send('customers/redact', str_replace('299938', '299939', $body), ['X-Shopify-Hmac-Sha256' => $sig], null)->assertStatus(401);
        $this->assertDatabaseCount('shopify_compliance_requests', 0);
    }

    public function test_authentic_but_unexpected_shape_is_kept_for_review_not_dropped(): void
    {
        // shop/redact body sent under customers/redact topic: authentic, but does not fit the topic.
        $this->send('customers/redact', json_encode(['shop_id' => 954889, 'shop_domain' => 'fietsen.myshopify.com']))->assertOk();
        $r = ShopifyComplianceRequest::sole();
        $this->assertFalse($r->payload_valid);
        $this->assertNull($r->shop_domain);
        $this->assertSame('pending', $r->status);
    }

    public function test_unconfigured_secret_returns_503_and_oversized_body_413(): void
    {
        config(['integrations.shopify_oauth.client_secret' => '']);
        $this->send('shop/redact', '{}')->assertStatus(503)->assertJsonPath('error.code', 'shopify_app_not_configured');
        config(['integrations.shopify_oauth.client_secret' => self::SECRET]);
        $this->send('shop/redact', str_repeat(' ', 1_048_577))->assertStatus(413);
        $this->assertDatabaseCount('shopify_compliance_requests', 0);
    }

    public function test_operator_lists_and_resolves_once_without_erasing(): void
    {
        $this->send('customers/redact', $this->redactBody())->assertOk();
        $r = ShopifyComplianceRequest::sole();
        $this->artisan('shopbonus:shopify-compliance')->expectsOutputToContain($r->public_id)->assertExitCode(0);
        $this->artisan('shopbonus:shopify-compliance', ['--resolve' => $r->public_id, '--outcome' => 'deleted'])->assertExitCode(2);
        $this->artisan('shopbonus:shopify-compliance', ['--resolve' => $r->public_id, '--outcome' => 'retained'])->assertExitCode(0);
        $this->artisan('shopbonus:shopify-compliance', ['--resolve' => $r->public_id, '--outcome' => 'completed'])->assertExitCode(1);
        $this->assertSame('retained', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->resolved_at);
        $this->assertSame('retained', AuditEvent::where('action', 'integration.shopify_compliance_resolved')->sole()->metadata['outcome']);
    }
}
