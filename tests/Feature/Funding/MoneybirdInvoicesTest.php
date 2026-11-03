<?php

namespace Tests\Feature\Funding;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherOrigin;
use App\Exceptions\ApiException;
use App\Models\FundingInvoice;
use App\Models\FundingInvoiceExport;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Funding\MerchantFunding;
use App\Services\Funding\MoneybirdClient;
use App\Services\Funding\MoneybirdInvoices;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class MoneybirdInvoicesTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private User $owner;

    private Webshop $shop;

    private FundingInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $actualNow = now()->startOfSecond();
        $this->travelTo($actualNow->subMonthNoOverflow());
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->shop = $this->activeWebshop($this->owner);
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward,
            ['name' => 'Synthetic reward', 'redemption_validity_days' => 30, 'discount_type' => 'fixed', 'discount_value' => 1000]), OfferStatus::Active);
        VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => 'SYNTHETIC', 'code_hash' => VoucherCode::hashFor($this->shop->id, 'SYNTHETIC'), 'code_last4' => 'ETIC']);
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->reserve($offer, VoucherOrigin::MerchantReward, 'synthetic-moneybird');
        $inventory->issue($voucher, User::factory()->create());
        $inventory->redeemByMerchant($voucher->fresh(), $this->owner->id, 'Synthetic order');
        app(MerchantFunding::class)->prepareInvoices(now()->format('Y-m'));
        $this->invoice = FundingInvoice::sole();
        $this->travelTo($actualNow);
        config(['moneybird.enabled' => true, 'moneybird.allow_delivery' => true, 'moneybird.token' => 'SYNTHETIC_PRIVATE_TOKEN',
            'moneybird.mapping_reference' => 'synthetic-mapping-not-legal-approval', 'moneybird.administration_id' => '100', 'moneybird.identity_id' => '200', 'moneybird.workflow_id' => '600',
            'moneybird.reward_tax_rate_id' => '301', 'moneybird.reward_ledger_account_id' => '401', 'moneybird.fee_tax_rate_id' => '302', 'moneybird.fee_ledger_account_id' => '402',
            'moneybird.contacts' => [$this->shop->public_id => '500']]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function fake(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function export(): FundingInvoiceExport
    {
        return app(MoneybirdInvoices::class)->prepare($this->invoice, $this->admin);
    }

    /** @return array<string,mixed> */
    private function remote(FundingInvoiceExport $export, string $state = 'draft'): array
    {
        $payload = $export->request_payload['sales_invoice'];
        $details = array_map(fn ($row) => $row + ['amount_decimal' => '1.0'], $payload['details_attributes']);
        unset($payload['details_attributes']);

        return $payload + ['id' => '777', 'administration_id' => 100, 'state' => $state, 'invoice_id' => $state === 'draft' ? null : 'SYNTHETIC-2026-001',
            'updated_at' => now()->subSeconds(match ($state) {
                'paid' => 1, 'draft' => 3, default => 2
            })->toIso8601String(),
            'total_price_incl_tax' => '13.00', 'total_unpaid' => $state === 'paid' ? '0.00' : '13.00', 'details' => $details];
    }

    private function fails(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_create_delivery_and_payment_observation_are_exact_replay_safe_and_do_not_post_wallet_entries(): void
    {
        $before = WalletLedgerEntry::count();
        $export = $this->export();
        $state = 'draft';
        $this->fake(function (Request $request, array $options) use ($export, &$state) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('Bearer SYNTHETIC_PRIVATE_TOKEN', $request->header('Authorization')[0]);
            if ($request->method() === 'POST') {
                $this->assertSame($export->request_payload, $request->data());
            }
            if ($request->method() === 'PATCH') {
                $this->assertSame(['sales_invoice_sending' => ['delivery_method' => 'Email']], $request->data());
                $state = 'open';
            }

            return Http::response($this->remote($export, $state), $request->method() === 'POST' ? 201 : 200);
        });
        $service = app(MoneybirdInvoices::class);
        $this->assertSame('created', $service->create($export, $this->admin)->phase);
        $service->create($export, $this->admin);
        $sent = $service->send($export, $this->admin);
        $this->assertSame('issued', $sent->phase);
        $this->assertNotNull($sent->delivery_acknowledged_at);
        $service->send($export, $this->admin);
        $state = 'paid';
        $paid = $service->reconcile($export, $this->admin);
        $this->assertSame(0, $paid->unpaid_minor);
        $this->assertSame('paid', $paid->remote_state);
        $this->assertSame('sent', $this->invoice->fresh()->status);
        $this->assertSame($before, WalletLedgerEntry::count());
        Http::assertSentCount(4);
        $this->fake(fn () => Http::response($this->remote($export, 'open')));
        $this->fails('moneybird_observation_stale', fn () => $service->reconcile($export, $this->admin));
        $this->assertSame('paid', $export->fresh()->remote_state);
        $this->assertSame(0, app(MerchantFunding::class)->prepareInvoices($this->invoice->period));
        $this->assertCount(1, $export->source_snapshot);
    }

    public function test_create_timeout_cannot_repeat_and_requires_bound_recovery(): void
    {
        $export = $this->export();
        $service = app(MoneybirdInvoices::class);
        $attempts = 0;
        $this->fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('SYNTHETIC_PRIVATE_TOKEN');
        });
        $this->fails('moneybird_create_unconfirmed', fn () => $service->create($export, $this->admin));
        $this->fails('moneybird_write_uncertain', fn () => $service->create($export, $this->admin));
        $this->assertSame(1, $attempts);
        $this->assertSame('create_uncertain', $export->fresh()->phase);
        $this->fake(fn () => Http::response($this->remote($export)));
        $this->assertSame('created', $service->bind($export, '777', $this->admin)->phase);
        $this->assertSame('777', $this->invoice->fresh()->external_ref);
    }

    public function test_in_flight_create_claim_prevents_a_second_writer(): void
    {
        $export = $this->export();
        $service = app(MoneybirdInvoices::class);
        $this->fake(function () use ($export, $service) {
            $this->fails('moneybird_write_uncertain', fn () => $service->create($export, $this->admin));

            return Http::response($this->remote($export), 201);
        });
        $service->create($export, $this->admin);
        Http::assertSentCount(1);
    }

    public function test_uncertain_delivery_is_never_unlocked_by_a_draft_read_and_issuance_is_not_delivery_acknowledgement(): void
    {
        $export = $this->export();
        $service = app(MoneybirdInvoices::class);
        $this->fake(fn () => Http::response($this->remote($export), 201));
        $service->create($export, $this->admin);
        $this->fake(fn (Request $r) => $r->method() === 'PATCH' ? throw new ConnectionException : Http::response($this->remote($export)));
        $this->fails('moneybird_delivery_unconfirmed', fn () => $service->send($export, $this->admin));
        $this->assertSame('send_uncertain', $service->reconcile($export, $this->admin)->phase);
        $this->fails('moneybird_write_uncertain', fn () => $service->send($export, $this->admin));
        $this->fake(fn () => Http::response($this->remote($export, 'open')));
        $reconciled = $service->reconcile($export, $this->admin);
        $this->assertSame('issued', $reconciled->phase);
        $this->assertNull($reconciled->delivery_acknowledged_at);
    }

    public function test_authority_configuration_mapping_and_delivery_gates_precede_egress(): void
    {
        $service = app(MoneybirdInvoices::class);
        $this->fails('moneybird_forbidden', fn () => $service->prepare($this->invoice, $this->owner));
        config(['moneybird.enabled' => false]);
        $this->fails('moneybird_unavailable', fn () => $this->export());
        config(['moneybird.enabled' => true, 'moneybird.contacts' => []]);
        $this->fails('moneybird_mapping_incomplete', fn () => $this->export());
        config(['moneybird.contacts' => [$this->shop->public_id => '500']]);
        $export = $this->export();
        config(['moneybird.fee_tax_rate_id' => '999']);
        $this->fails('moneybird_mapping_changed', fn () => $service->create($export, $this->admin));
        config(['moneybird.fee_tax_rate_id' => '302', 'moneybird.allow_delivery' => false]);
        $this->fails('moneybird_delivery_disabled', fn () => $service->send($export, $this->admin));
        $this->admin->platformRoles()->update(['revoked_at' => now()]);
        $this->fails('moneybird_forbidden', fn () => $service->create($export, $this->admin));
        Http::assertNothingSent();
    }

    public function test_foreign_invoice_and_changed_money_or_source_details_never_reconcile(): void
    {
        $export = $this->export();
        $service = app(MoneybirdInvoices::class);
        $this->fake(fn () => Http::response($this->remote($export), 201));
        $service->create($export, $this->admin);
        foreach (['contact_id' => '999', 'identity_id' => '999', 'administration_id' => '999', 'reference' => 'other-shop', 'currency' => 'USD', 'total_price_incl_tax' => '13.001', 'total_unpaid' => '14.00'] as $field => $wrong) {
            $remote = $this->remote($export);
            $remote[$field] = $wrong;
            $this->fake(fn () => Http::response($remote));
            try {
                $service->reconcile($export, $this->admin);
                $this->fail('Mismatch accepted: '.$field);
            } catch (ApiException) {
                $this->assertSame('created', $export->fresh()->phase);
            }
        }
        $remote = $this->remote($export);
        $remote['details'][0]['ledger_account_id'] = '999';
        $this->fake(fn () => Http::response($remote));
        $this->fails('moneybird_invoice_mismatch', fn () => $service->reconcile($export, $this->admin));
    }

    public function test_transport_errors_are_bounded_single_attempts_and_do_not_expose_credentials(): void
    {
        $client = app(MoneybirdClient::class);
        foreach ([301, 401, 403, 422, 429, 500] as $status) {
            $this->fake(fn () => Http::response('SYNTHETIC_PRIVATE_TOKEN', $status, ['Location' => 'https://other.example.test']));
            $this->fails('moneybird_unconfirmed', fn () => $client->request('100', 'GET', '777'));
            Http::assertSentCount(1);
        }
        $this->fake(fn () => Http::response(str_repeat('x', 1048577)));
        $this->fails('moneybird_unconfirmed', fn () => $client->request('100', 'GET', '777'));
        config(['moneybird.allow_delivery' => false]);
        $this->fake(fn () => $this->fail('Delivery gate must precede egress.'));
        $this->fails('moneybird_delivery_disabled', fn () => $client->request('100', 'PATCH', '777'));
    }

    public function test_open_period_and_export_snapshot_mutation_are_rejected(): void
    {
        $this->invoice->forceFill(['period' => now('UTC')->format('Y-m')])->save();
        $this->fails('moneybird_period_open', fn () => $this->export());
        $this->invoice->forceFill(['period' => now('UTC')->subMonthNoOverflow()->format('Y-m')])->save();
        $export = $this->export();
        $this->expectException(\LogicException::class);
        $export->forceFill(['request_hash' => str_repeat('0', 64)])->save();
    }

    public function test_console_preparation_enforces_authority_and_preserves_source_checks(): void
    {
        $this->artisan('shopbonus:moneybird-invoice', ['action' => 'prepare', 'id' => $this->invoice->public_id, '--actor' => $this->owner->public_id])->assertFailed();
        $this->artisan('shopbonus:moneybird-invoice', ['action' => 'prepare', 'id' => $this->invoice->public_id, '--actor' => $this->admin->public_id])->assertSuccessful();
        $this->assertDatabaseCount('funding_invoice_exports', 1);
        Http::assertNothingSent();
    }

    public function test_missing_redemption_proof_cannot_be_invoiced_from_a_settled_label(): void
    {
        Voucher::query()->update(['redeemed_at' => null]);
        $this->fails('moneybird_source_unverified', fn () => $this->export());
        $this->assertDatabaseCount('funding_invoice_exports', 0);
        Http::assertNothingSent();
    }
}
