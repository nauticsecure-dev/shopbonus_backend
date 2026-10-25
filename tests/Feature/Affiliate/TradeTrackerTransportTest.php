<?php

namespace Tests\Feature\Affiliate;

use App\Affiliate\Adapters\TradeTrackerAdapter;
use App\Affiliate\Adapters\TradeTrackerSoapClient;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Services\Affiliate\AffiliateSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TradeTrackerResponses as Soap;
use Tests\TestCase;

class TradeTrackerTransportTest extends TestCase
{
    use RefreshDatabase;

    private AffiliateConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopbonus.affiliate.networks.tradetracker.enabled' => true]);
        Http::preventStrayRequests();
        $this->connection = AffiliateConnection::create(['network' => 'tradetracker', 'name' => 'Fixture', 'status' => 'error',
            'credentials' => ['customer_id' => '123', 'passphrase' => 'private-fixture-secret', 'site_id' => '456'],
            'postback_token_hash' => AffiliateConnection::hashToken('test')]);
    }

    private function program(string $id = '12'): array
    {
        return ['ID' => $id, 'name' => 'Fixture shop', 'URL' => 'https://example.nl', 'info' => [
            'assignmentStatus' => 'accepted', 'referencesSupported' => true, 'policyCashbackStatus' => 'allowed',
            'commission' => ['saleCommissionVariable' => '6.25'], 'trackingURL' => 'https://tc.tradetracker.net/?c=12#details',
        ]];
    }

    private function transaction(string $id = '99'): array
    {
        return ['ID' => $id, 'campaign' => ['ID' => '12'], 'reference' => 'unknown-click', 'transactionStatus' => 'accepted',
            'currency' => 'EUR', 'commission' => '6.25', 'orderAmount' => '100.00',
            'registrationDate' => '2026-01-01T12:00:00Z', 'assessmentDate' => '2026-10-25T12:00:00Z'];
    }

    private function fake(callable $handler): void
    {
        Http::fake(function (Request $request) use ($handler) {
            $action = trim($request->header('SOAPAction')[0], '"');
            $method = basename($action);
            if ($method === 'authenticate') {
                $this->assertFalse($request->hasHeader('Cookie'), 'Each SOAP session must authenticate with an isolated cookie jar.');

                return Http::response(Soap::response('authenticate'), 200, ['Content-Type' => 'text/xml', 'Set-Cookie' => 'TTSESSION=fixture-session; Path=/; Secure; HttpOnly']);
            }

            $this->assertStringContainsString('TTSESSION=fixture-session', $request->header('Cookie')[0] ?? '');

            return $handler($method, $request->body());
        });
    }

    public function test_serialized_authenticated_read_only_paged_campaigns_and_precise_mapping(): void
    {
        $this->fake(fn ($method, $body) => Http::response(Soap::response($method, str_contains($body, '>100</offset>') ? [$this->program('101')] : array_map(fn ($n) => $this->program((string) $n), range(1, 100)))));
        $programs = app(TradeTrackerAdapter::class)->fetchPrograms($this->connection);
        $this->assertCount(101, $programs);
        $this->assertSame(625, $programs[0]->commissionValue);
        $this->assertTrue($programs[0]->cashbackAllowed);
        $this->assertSame('https://tc.tradetracker.net/?c=12&r={click_ref}#details', $programs[0]->trackingUrlTemplate);
        Http::assertSent(fn ($r) => $r->url() === TradeTrackerSoapClient::ENDPOINT && str_contains($r->body(), '<sandbox xsi:type="xsd:boolean">true</sandbox>') && str_contains($r->body(), '<demo xsi:type="xsd:boolean">false</demo>'));
        Http::assertSentCount(3);
    }

    public function test_polling_includes_old_conversions_assessed_recently_and_deduplicates_queries(): void
    {
        $this->fake(function ($method) {
            $row = $this->transaction();
            $row['commission'] = '6.249999999'; // Native float conversion would round this up to 625.

            return Http::response(Soap::response($method, [$row]));
        });
        $transactions = app(TradeTrackerAdapter::class)->fetchTransactions($this->connection, CarbonImmutable::parse('2026-10-24T00:00:00Z'));
        $this->assertCount(1, $transactions);
        $this->assertSame('approved', $transactions[0]->networkStatus);
        $this->assertSame(624, $transactions[0]->commissionMinor);
        Http::assertSent(fn ($r) => str_contains($r->body(), '<assessmentDateFrom') && str_contains($r->body(), '2026-10-24T00:00:00+00:00'));
        Http::assertSentCount(3);
    }

    public function test_soap_auth_failure_is_actionable_and_never_exposes_provider_secrets(): void
    {
        Http::fake([TradeTrackerSoapClient::ENDPOINT => Http::response(Soap::fault('private-fixture-secret upstream debug'), 500)]);
        $result = app(TradeTrackerAdapter::class)->testConnection($this->connection);
        $this->assertSame(['ok' => false, 'status' => 'reauthentication_required', 'error' => 'authentication_failed'], $result);
        Http::assertSentCount(1);
        $sync = app(AffiliateSync::class)->sync($this->connection);
        $this->assertSame('authentication_failed', $sync['error']);
        $this->assertSame('authentication_failed', $this->connection->fresh()->last_error);
        $this->assertDatabaseMissing('audit_events', ['metadata' => 'private-fixture-secret']);
    }

    public function test_disabled_transport_or_connection_makes_no_external_request(): void
    {
        config(['shopbonus.affiliate.networks.tradetracker.enabled' => false]);
        $this->assertSame('limited', app(TradeTrackerAdapter::class)->testConnection($this->connection)['status']);
        $this->connection->update(['status' => 'disabled']);
        $this->assertSame('connection_disabled', app(AffiliateSync::class)->sync($this->connection)['error']);
        Http::assertNothingSent();
    }

    public function test_repeated_page_fails_without_advancing_watermark_or_importing_programs(): void
    {
        $this->fake(fn ($method) => Http::response(Soap::response($method, array_map(fn ($n) => $this->program((string) $n), range(1, 100)))));
        $this->assertSame('invalid_provider_pagination', app(AffiliateSync::class)->sync($this->connection)['error']);
        $this->assertNull($this->connection->fresh()->last_synced_at);
        $this->assertSame(0, AffiliateProgram::count());
        Http::assertSentCount(3);
    }

    public function test_invalid_xml_entity_or_http_redirect_is_not_followed_or_leaked(): void
    {
        foreach ([['<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo/>', 200, 'invalid_provider_response'], ['private-fixture-secret', 302, 'http_302'], ['private-fixture-secret', 401, 'credentials_rejected']] as [$body, $status, $expected]) {
            Http::swap(new Factory);
            Http::fake([TradeTrackerSoapClient::ENDPOINT => Http::response($body, $status, ['Location' => 'https://example.org'])]);
            $this->assertSame($expected, app(TradeTrackerAdapter::class)->testConnection($this->connection)['error']);
            Http::assertSentCount(1);
        }
    }

    public function test_invalid_conversion_cannot_become_a_positive_reward_or_advance_sync(): void
    {
        $this->fake(function ($method) {
            $row = $this->transaction();
            $row['commission'] = '-6.25';

            return Http::response(Soap::response($method, $method === 'getCampaigns' ? [$this->program()] : [$row]));
        });
        $this->assertSame('invalid_provider_response', app(AffiliateSync::class)->sync($this->connection)['error']);
        $this->assertDatabaseCount('affiliate_transactions', 0);
        $this->assertDatabaseCount('affiliate_merchant_programs', 0);
        $this->assertNull($this->connection->fresh()->last_synced_at);
    }

    public function test_connection_disabled_during_fetch_cannot_be_reenabled_or_ingest_data(): void
    {
        $this->fake(function ($method) {
            if ($method === 'getConversionTransactions') {
                $this->connection->update(['status' => 'disabled']);
            }

            return Http::response(Soap::response($method, $method === 'getCampaigns' ? [$this->program()] : [$this->transaction()]));
        });
        $this->assertSame('connection_disabled', app(AffiliateSync::class)->sync($this->connection)['error']);
        $this->assertSame('disabled', $this->connection->fresh()->status);
        $this->assertDatabaseCount('affiliate_transactions', 0);
        $this->assertDatabaseCount('affiliate_merchant_programs', 0);
    }

    public function test_failed_pull_cannot_overwrite_a_concurrent_disable(): void
    {
        $this->fake(function () {
            // Separate instance simulates the admin update while this service holds stale state.
            AffiliateConnection::whereKey($this->connection->id)->update(['status' => 'disabled']);

            return Http::response(Soap::fault('upstream failure'), 500);
        });
        $this->assertSame('provider_request_rejected', app(AffiliateSync::class)->sync($this->connection)['error']);
        $this->assertSame('disabled', $this->connection->fresh()->status);
        $this->assertNull($this->connection->fresh()->last_synced_at);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
    }

    public function test_unexpected_provider_exceptions_never_persist_the_response_or_token(): void
    {
        $this->connection->update(['network' => 'daisycon', 'credentials' => ['publisher_id' => '1', 'access_token' => 'private-fixture-secret', 'media_id' => '2']]);
        Http::fake(['*' => Http::response(['error' => 'private-fixture-secret'], 500)]);
        $this->assertSame('provider_sync_failed', app(AffiliateSync::class)->sync($this->connection)['error']);
        $this->assertSame('provider_sync_failed', $this->connection->fresh()->last_error);
        $metadata = json_encode(DB::table('audit_events')->pluck('metadata')->all());
        $this->assertStringNotContainsString('private-fixture-secret', $metadata);
    }

    public function test_cashback_policy_strings_are_not_treated_as_truthy_permissions(): void
    {
        $adapter = app(TradeTrackerAdapter::class);
        foreach (['limited', 'disallowed', null, false] as $policy) {
            $row = $this->program();
            $row['info']['policyCashbackStatus'] = $policy;
            $this->assertFalse($adapter->normalizeProgram($row)->cashbackAllowed);
        }
        $row['info']['referencesSupported'] = false;
        $this->assertFalse($adapter->normalizeProgram($row)->active);
    }

    public function test_legacy_minimal_postback_remains_accepted_but_cannot_credit_wallets(): void
    {
        $this->postJson('/api/v1/affiliate/postback/test', ['ID' => 'ping-only', 'campaign' => ['ID' => '12'],
            'reference' => 'unknown', 'commission' => '2.00', 'transactionStatus' => 'accepted'])
            ->assertStatus(202)->assertJsonPath('data.received', true);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        Http::assertNothingSent();
    }

    public function test_complete_empty_program_snapshot_removes_stale_tracking_eligibility(): void
    {
        $program = AffiliateProgram::create(['connection_id' => $this->connection->id, 'external_id' => 'removed', 'name' => 'Removed',
            'active' => true, 'commission_type' => 'percentage', 'commission_value' => 500, 'currency' => 'EUR',
            'category_restricted' => false, 'cashback_allowed' => true, 'tracking_url_template' => 'https://example.nl/?r={click_ref}']);
        $this->fake(fn ($method) => Http::response(Soap::response($method, [])));
        $this->assertNull(app(AffiliateSync::class)->sync($this->connection)['error']);
        $this->assertFalse($program->fresh()->active);
    }

    public function test_sync_imports_valid_provider_data_and_replays_do_not_duplicate_records(): void
    {
        $this->fake(fn ($method) => Http::response(Soap::response($method, $method === 'getCampaigns' ? [$this->program()] : [$this->transaction()])));
        $sync = app(AffiliateSync::class);
        $this->assertSame(['programs' => 1, 'transactions' => 1, 'error' => null], $sync->sync($this->connection));
        $this->assertSame(['programs' => 1, 'transactions' => 1, 'error' => null], $sync->sync($this->connection->refresh()));
        $this->assertSame(1, AffiliateProgram::count());
        $this->assertDatabaseCount('affiliate_transactions', 1);
        $this->assertDatabaseHas('affiliate_transactions', ['status' => 'unmatched']);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->assertSame('connected', $this->connection->fresh()->status);
    }
}
