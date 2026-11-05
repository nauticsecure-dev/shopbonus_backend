<?php

namespace App\Services\Funding;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\FundingInvoice;
use App\Models\FundingInvoiceExport;
use App\Models\SponsorshipObligation;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Checkout\MollieClient;
use App\Services\Reliability\FinancialSources;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class MoneybirdInvoices
{
    public function __construct(private readonly MoneybirdClient $client, private readonly AuditLogger $audit) {}

    public function prepare(FundingInvoice $invoice, User $actor): FundingInvoiceExport
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($invoice, $actor) {
            Webshop::whereKey($invoice->webshop_id)->lockForUpdate()->firstOrFail();
            $invoice = FundingInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $existing = FundingInvoiceExport::where('invoice_id', $invoice->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($invoice->status !== 'draft' || $invoice->external_ref !== null || ($invoice->reward_minor + $invoice->fee_minor <= 0 || $invoice->reward_minor + $invoice->fee_minor > 999999999999999)) {
                throw ApiException::conflict('Only an unexported positive draft can be prepared.', 'moneybird_statement_not_exportable');
            }
            $source = SponsorshipObligation::where('invoice_id', $invoice->id)->where('webshop_id', $invoice->webshop_id)->where('status', 'settled')
                ->selectRaw('COUNT(*) n, COALESCE(SUM(reward_minor),0) r, COALESCE(SUM(fee_minor),0) f')->firstOrFail();
            if ((int) $source->getAttribute('n') !== $invoice->lines || (int) $source->getAttribute('r') !== $invoice->reward_minor || (int) $source->getAttribute('f') !== $invoice->fee_minor) {
                throw ApiException::conflict('Prepare and reconcile the statement before exporting it.', 'moneybird_statement_stale');
            }
            if ($invoice->period >= now('UTC')->format('Y-m')) {
                throw ApiException::conflict('Export only closed monthly statements.', 'moneybird_period_open');
            }
            $sources = $this->sources($invoice, true);
            $payload = $this->payload($invoice);
            $export = FundingInvoiceExport::create(['invoice_id' => $invoice->id, 'created_by' => $actor->id,
                'administration_id' => config('moneybird.administration_id'), 'mapping_reference' => config('moneybird.mapping_reference'),
                'phase' => 'prepared', 'source_snapshot' => $sources, 'request_payload' => $payload, 'request_hash' => $this->hash($payload),
                'expected_total_minor' => $invoice->reward_minor + $invoice->fee_minor]);
            $this->audit->record('moneybird.export_prepared', category: AuditCategory::Financial, resource: $export, webshopId: $invoice->webshop_id, actorUserId: $actor->id);

            return $export;
        });
    }

    public function create(FundingInvoiceExport $export, User $actor): FundingInvoiceExport
    {
        $this->ready($export, $actor);
        $claim = DB::transaction(function () use ($export, $actor) {
            $e = FundingInvoiceExport::whereKey($export->id)->lockForUpdate()->firstOrFail();
            if (in_array($e->phase, ['created', 'issued'], true)) {
                return null;
            }
            if ($e->phase !== 'prepared') {
                throw ApiException::conflict('Reconcile the uncertain create; it must not be submitted again.', 'moneybird_write_uncertain');
            }
            $e->forceFill(['phase' => 'create_uncertain', 'attempted_at' => now(), 'last_error_code' => null])->save();
            $this->audit->record('moneybird.create_attempted', category: AuditCategory::Financial, resource: $e, actorUserId: $actor->id);

            return $e;
        });
        if ($claim === null) {
            return $export->fresh();
        }
        try {
            $remote = $this->client->request($claim->administration_id, 'POST', payload: $claim->request_payload);
            $this->verify($claim, $remote);
            if (($remote['state'] ?? null) !== 'draft') {
                throw new \RuntimeException('Create must return a draft.');
            }

            return $this->observe($claim, $remote, $actor);
        } catch (Throwable) {
            $this->uncertain($claim, 'moneybird_create_unconfirmed');
            throw new ApiException('Invoice creation is uncertain. Bind a verified existing provider invoice; do not create another.', 502, 'moneybird_create_unconfirmed');
        }
    }

    /** Explicit recovery by known remote ID, never a second blind POST after an ambiguous create. */
    public function bind(FundingInvoiceExport $export, string $remoteId, User $actor): FundingInvoiceExport
    {
        $this->ready($export, $actor);
        $e = $export->fresh();
        if ($e->phase !== 'create_uncertain' || $e->remote_id !== null) {
            throw ApiException::conflict('Only an unbound uncertain create can be recovered.', 'moneybird_bind_conflict');
        }
        $remote = $this->client->request($e->administration_id, 'GET', $remoteId);
        $this->verify($e, $remote, $remoteId);

        return $this->observe($e, $remote, $actor);
    }

    public function send(FundingInvoiceExport $export, User $actor): FundingInvoiceExport
    {
        $this->ready($export, $actor);
        if (! config('moneybird.allow_delivery')) {
            throw ApiException::conflict('Moneybird delivery is disabled.', 'moneybird_delivery_disabled');
        }
        $e = $export->fresh();
        if ($e->phase === 'issued') {
            return $e;
        }
        if ($e->phase !== 'created' || $e->remote_id === null) {
            throw ApiException::conflict('Reconcile this invoice before requesting delivery.', 'moneybird_write_uncertain');
        }
        $remote = $this->client->request($e->administration_id, 'GET', $e->remote_id);
        $this->verify($e, $remote, $e->remote_id);
        if ($remote['state'] !== 'draft') {
            return $this->observe($e, $remote, $actor);
        }
        DB::transaction(function () use ($e, $actor) {
            $locked = FundingInvoiceExport::whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($locked->phase !== 'created') {
                throw ApiException::conflict('Another delivery attempt must be reconciled first.', 'moneybird_write_uncertain');
            }
            $locked->forceFill(['phase' => 'send_uncertain', 'attempted_at' => now()])->save();
            $this->audit->record('moneybird.delivery_attempted', category: AuditCategory::Financial, resource: $locked, actorUserId: $actor->id);
        });
        try {
            $response = $this->client->request($e->administration_id, 'PATCH', $e->remote_id, ['sales_invoice_sending' => ['delivery_method' => 'Email']]);
            $this->verify($e, $response, $e->remote_id);
            if ($response['state'] === 'draft') {
                throw new \RuntimeException('Issuance was not confirmed.');
            }

            return $this->observe($e, $response, $actor, true);
        } catch (Throwable) {
            $this->uncertain($e, 'moneybird_delivery_unconfirmed');
            throw new ApiException('Delivery is uncertain. Reconcile provider state before any further action.', 502, 'moneybird_delivery_unconfirmed');
        }
    }

    public function reconcile(FundingInvoiceExport $export, User $actor): FundingInvoiceExport
    {
        $this->ready($export, $actor);
        $e = $export->fresh();
        if ($e->remote_id === null) {
            throw ApiException::conflict('Supply the existing provider ID to recover an uncertain create.', 'moneybird_remote_id_missing');
        }
        $remote = $this->client->request($e->administration_id, 'GET', $e->remote_id);
        $this->verify($e, $remote, $e->remote_id);

        return $this->observe($e, $remote, $actor);
    }

    private function authorize(User $actor): void
    {
        $current = User::find($actor->id);
        if (! $current?->isActive() || ! $current->isSuperAdmin()) {
            throw new ApiException('Active Super Admin authority is required.', 403, 'moneybird_forbidden');
        }
    }

    private function ready(FundingInvoiceExport $export, User $actor): void
    {
        $this->authorize($actor);
        $current = $export->fresh();
        $invoice = FundingInvoice::findOrFail($current->invoice_id);
        $payload = $this->payload($invoice);
        if ($this->sources($invoice) !== $current->source_snapshot || $current->administration_id !== config('moneybird.administration_id') || $current->mapping_reference !== config('moneybird.mapping_reference')
            || ! hash_equals($current->request_hash, $this->hash($payload)) || ! hash_equals($current->request_hash, $this->hash($current->request_payload))) {
            throw ApiException::conflict('The frozen Moneybird mapping has changed; review this export.', 'moneybird_mapping_changed');
        }
    }

    /** @return array<string,mixed> */
    private function payload(FundingInvoice $invoice): array
    {
        if (! config('moneybird.enabled') || ! is_string(config('moneybird.token')) || config('moneybird.token') === ''
            || ! is_string(config('moneybird.mapping_reference')) || trim(config('moneybird.mapping_reference')) === '' || strlen(config('moneybird.mapping_reference')) > 200) {
            throw ApiException::conflict('Configure the reviewed Moneybird mapping before exporting.', 'moneybird_unavailable');
        }
        $shop = Webshop::findOrFail($invoice->webshop_id);
        $contacts = config('moneybird.contacts');
        $contact = is_array($contacts) ? ($contacts[$shop->public_id] ?? null) : null;
        $identity = config('moneybird.identity_id');
        foreach ([config('moneybird.administration_id'), $identity, $contact, config('moneybird.workflow_id')] as $id) {
            $this->id($id);
        }
        $details = [];
        foreach (['reward' => $invoice->reward_minor, 'fee' => $invoice->fee_minor] as $component => $minor) {
            if ($minor < 0 || $minor > 999999999999999) {
                throw ApiException::conflict('Invalid statement amount.', 'moneybird_statement_invalid');
            }
            if ($minor === 0) {
                continue;
            }
            $tax = config('moneybird.'.$component.'_tax_rate_id');
            $ledger = config('moneybird.'.$component.'_ledger_account_id');
            $this->id($tax);
            $this->id($ledger);
            $details[] = ['description' => 'ShopBonus '.$component.' '.$invoice->period.' '.$invoice->public_id,
                'amount' => '1', 'price' => MollieClient::decimal($minor), 'tax_rate_id' => $tax, 'ledger_account_id' => $ledger];
        }

        return ['sales_invoice' => ['contact_id' => $contact, 'identity_id' => $identity, 'reference' => 'shopbonus:funding:'.$invoice->public_id,
            'currency' => 'EUR', 'prices_are_incl_tax' => true, 'workflow_id' => config('moneybird.workflow_id'),
            'payment_conditions' => 'Settled through ShopBonus merchant funding. No additional payment is requested by this statement.', 'details_attributes' => $details]];
    }

    /** @return list<array<string,mixed>> */
    private function sources(FundingInvoice $invoice, bool $checkFinancialEvidence = false): array
    {
        if (! preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D', $invoice->period)) {
            throw ApiException::conflict('Invalid statement period.', 'moneybird_statement_invalid');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $invoice->period, 'UTC');
        $rows = SponsorshipObligation::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $snapshot = [];
        foreach ($rows as $row) {
            if ($start === null || $row->webshop_id !== $invoice->webshop_id || $row->status !== 'settled' || $row->settled_at === null
                || $row->settled_at->lessThan($start) || $row->settled_at->greaterThanOrEqualTo($start->addMonth())) {
                throw ApiException::conflict('Statement source evidence is inconsistent.', 'moneybird_statement_stale');
            }
            if ($checkFinancialEvidence && app(FinancialSources::class)->inspect($row)['status'] !== 'matched') {
                throw ApiException::conflict('Statement source ledger evidence is incomplete or inconsistent.', 'moneybird_source_unverified');
            }
            $snapshot[] = ['id' => $row->public_id, 'reward_minor' => $row->reward_minor, 'fee_minor' => $row->fee_minor,
                'source_order_id' => $row->source_order_id === null ? null : (int) $row->source_order_id,
                'voucher_id' => (int) $row->voucher_id, 'wallet_reservation_id' => (int) $row->wallet_reservation_id,
                'settled_at' => $row->settled_at->utc()->toIso8601String()];
        }

        return $snapshot;
    }

    private function id(mixed $id): void
    {
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]{0,31}\z/D', $id)) {
            throw ApiException::conflict('Configure actual Moneybird administration, seller, contact, tax and ledger IDs.', 'moneybird_mapping_incomplete');
        }
    }

    private function minor(string $value): int
    {
        try {
            return Money::toMinor($value, 'EUR');
        } catch (\InvalidArgumentException) {
            throw ApiException::conflict('Provider money does not match the exact statement currency units.', 'moneybird_invoice_mismatch');
        }
    }

    private function stamp(mixed $value): string
    {
        try {
            if (! is_string($value) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new \InvalidArgumentException;
            }
            $at = CarbonImmutable::parse($value);
            if ($at->greaterThan(now()->addMinutes(5))) {
                throw new \InvalidArgumentException;
            }

            return $at->utc()->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            throw ApiException::conflict('Provider observation time is invalid.', 'moneybird_observation_invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $remote */
    private function verify(FundingInvoiceExport $export, array $remote, ?string $id = null): void
    {
        $this->id($remote['id'] ?? null);
        $this->stamp($remote['updated_at'] ?? null);
        $expected = $export->request_payload['sales_invoice'];
        foreach (['contact_id', 'identity_id', 'workflow_id', 'reference', 'currency', 'prices_are_incl_tax'] as $key) {
            if (($remote[$key] ?? null) !== $expected[$key]) {
                throw ApiException::conflict('Provider invoice does not match this statement.', 'moneybird_invoice_mismatch');
            }
        }
        if ((string) ($remote['administration_id'] ?? '') !== $export->administration_id || ($id !== null && $remote['id'] !== $id)
            || ! is_string($remote['total_price_incl_tax'] ?? null) || $this->minor($remote['total_price_incl_tax']) !== $export->expected_total_minor
            || ! is_string($remote['total_unpaid'] ?? null) || ! in_array($remote['state'] ?? null, ['draft', 'open', 'pending_payment', 'reminded', 'late', 'paid', 'uncollectible'], true)) {
            throw ApiException::conflict('Provider invoice does not match this statement.', 'moneybird_invoice_mismatch');
        }
        $unpaid = $this->minor($remote['total_unpaid']);
        if ($unpaid < 0 || $unpaid > $export->expected_total_minor || ($remote['state'] === 'paid' && $unpaid !== 0)
            || ($remote['state'] !== 'draft' && (! is_string($remote['invoice_id'] ?? null) || $remote['invoice_id'] === ''))) {
            throw ApiException::conflict('Provider invoice balance or issue state is inconsistent.', 'moneybird_invoice_mismatch');
        }
        $details = $remote['details'] ?? null;
        if (! is_array($details) || ! array_is_list($details) || count($details) !== count($expected['details_attributes'])) {
            throw ApiException::conflict('Provider invoice lines differ from the snapshot.', 'moneybird_invoice_mismatch');
        }
        foreach ($expected['details_attributes'] as $line) {
            $matches = array_values(array_filter($details, fn ($d) => is_array($d) && ($d['description'] ?? null) === $line['description']));
            $d = count($matches) === 1 ? $matches[0] : [];
            if (($d['tax_rate_id'] ?? null) !== $line['tax_rate_id'] || ($d['ledger_account_id'] ?? null) !== $line['ledger_account_id']
                || ! is_string($d['amount_decimal'] ?? null) || ! preg_match('/\A1(?:\.0+)?\z/D', $d['amount_decimal'])
                || ! is_string($d['price'] ?? null) || $this->minor($d['price']) !== $this->minor($line['price'])) {
                throw ApiException::conflict('Provider invoice line does not match the snapshot.', 'moneybird_invoice_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $remote */
    private function observe(FundingInvoiceExport $export, array $remote, User $actor, bool $deliveryAcknowledged = false): FundingInvoiceExport
    {
        return DB::transaction(function () use ($export, $remote, $actor, $deliveryAcknowledged) {
            $invoice = FundingInvoice::whereKey($export->invoice_id)->lockForUpdate()->firstOrFail();
            $e = FundingInvoiceExport::whereKey($export->id)->lockForUpdate()->firstOrFail();
            if (($e->remote_id !== null && $e->remote_id !== $remote['id']) || ($invoice->external_ref !== null && $invoice->external_ref !== $remote['id'])) {
                throw ApiException::conflict('This export is bound to another provider invoice.', 'moneybird_bind_conflict');
            }
            $stamp = $this->stamp($remote['updated_at']);
            $factHash = hash('sha256', json_encode([$remote['state'], $this->minor($remote['total_unpaid']), $remote['invoice_id'] ?? null], JSON_THROW_ON_ERROR));
            if ($e->remote_updated_at !== null && ($stamp < $e->remote_updated_at || ($stamp === $e->remote_updated_at && $factHash !== $e->remote_observation_hash))) {
                throw ApiException::conflict('An older or conflicting provider observation cannot replace the current one.', 'moneybird_observation_stale');
            }
            // A draft observation can never unlock an uncertain delivery for another PATCH.
            $issued = $remote['state'] !== 'draft';
            if (! $issued && $e->phase === 'issued') {
                throw ApiException::conflict('An issued invoice unexpectedly became a draft.', 'moneybird_invoice_mismatch');
            }
            $e->forceFill(['remote_id' => $remote['id'], 'remote_state' => $remote['state'], 'remote_updated_at' => $stamp, 'remote_observation_hash' => $factHash, 'unpaid_minor' => $this->minor($remote['total_unpaid']),
                'phase' => $issued ? 'issued' : ($e->phase === 'send_uncertain' ? 'send_uncertain' : 'created'),
                'delivery_acknowledged_at' => $deliveryAcknowledged ? now() : $e->delivery_acknowledged_at, 'reconciled_at' => now(), 'last_error_code' => null])->save();
            $invoice->forceFill(['external_ref' => $remote['id'], 'status' => $issued ? 'sent' : 'draft'])->save();
            $this->audit->record('moneybird.invoice_observed', category: AuditCategory::Financial, resource: $e, webshopId: $invoice->webshop_id,
                actorUserId: $actor->id, metadata: ['state' => $e->remote_state, 'unpaid_minor' => $e->unpaid_minor, 'delivery_acknowledged' => $e->delivery_acknowledged_at !== null]);

            return $e;
        });
    }

    private function uncertain(FundingInvoiceExport $export, string $code): void
    {
        FundingInvoiceExport::whereKey($export->id)->update(['last_error_code' => $code]);
        $this->audit->recordSafely('moneybird.operation_unconfirmed', category: AuditCategory::Financial, resource: $export, metadata: ['code' => $code]);
    }
}
