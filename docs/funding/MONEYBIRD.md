# Moneybird funding statements — B68

The backend can create a Moneybird sales-invoice draft, explicitly request Email delivery and reconcile provider invoice/payment state. Creation and delivery default OFF. Existing EUR funding obligations are already reserved/captured by ShopBonus: no export operation credits/debits a wallet, registers a guessed payment or creates a second merchant charge.

Owner authorization on 2026-11-03 delegated technical defaults (“do it based on whats recommended, approve it yourself”). D-110 records the chosen defaults. Actual seller, contact, VAT and accounting records must exist; the implementation does not certify those records or resolve external statutory obligations. OPEN-FINANCIAL-LEGAL remains deployment-specific. No credentials or real company/tax IDs were invented.

## Deployment configuration

Apply migration `2026_11_03_180000_create_funding_invoice_exports_table.php` before deploying this code or running the invoice-preparation cron. It adds one export per statement, a unique administration/provider-ID binding, an encrypted immutable request/source snapshot and durable operation/observation fields. No existing liability or affiliate policy is rewritten.

Senior configuration in `config/moneybird.php` / local environment:

- Actual Moneybird token with sales-invoice access; administration and seller identity IDs.
- Exact per-webshop contact IDs, keyed by workspace public ID in `contacts`. Use independently checked existing contacts, not approximate name matching or fake company data. No automatic contact creation is performed.
- Separate actual tax-rate and ledger-account IDs for reward and fee components. No VAT percentage or tax exemption is guessed. Prices are tax-inclusive within the existing fixed funding totals; no additional tax is added to wallet liabilities.
- A reviewed Moneybird workflow ID appropriate for settled merchant funding statements. Disable additional collection/dunning in Moneybird for already funded amounts; reconcile actual funding/prepayments in the administration. The invoice payment conditions explicitly say no additional payment is requested. This backend reads balances and does not manufacture provider payment entries to make an invoice look paid.
- A nonempty `MONEYBIRD_MAPPING_REFERENCE` naming the reviewed actual seller/contact/workflow/tax/accounting setup. `MONEYBIRD_ENABLED=false` and `MONEYBIRD_ALLOW_DELIVERY=false` until configured; these are separate creation/read versus delivery controls.

Changes to administration, contact, identity, workflow, tax/ledger IDs, mapping reference, statement amounts or source evidence invalidate a frozen export for further operations. Restoring/rotating credentials for the same administration is allowed; a new account is not silently substituted. Preserve Laravel encryption keys for existing request/source snapshots.

## Operator workflow

These are reproducible command instructions, not records of live provider execution. Every action requires a currently active Super Admin public user ID; authority is re-read by the service. Trusted console operators are responsible for selecting their own authorized actor identity. There are no new browser mutation endpoints.

```bash
php artisan shopbonus:prepare-invoices 2026-10
php artisan shopbonus:moneybird-invoice prepare <STATEMENT_ULID> --actor=<ADMIN_ULID>
php artisan shopbonus:moneybird-invoice create <EXPORT_ULID> --actor=<ADMIN_ULID>
php artisan shopbonus:moneybird-invoice send <EXPORT_ULID> --actor=<ADMIN_ULID>
php artisan shopbonus:moneybird-invoice reconcile <EXPORT_ULID> --actor=<ADMIN_ULID>
```

Only closed monthly statements can be exported. Preparation checks settled source records, period, counts, exact amounts and workspace, and reuses FinancialSources to require matched wallet/redemption evidence before freezing them. Later `prepare-invoices` calls leave that prepared statement unchanged, even while the remote record is still a draft. Any late/unallocated historical obligations require a reviewed adjustment workflow; they are never silently appended to an already frozen provider invoice. Credit-note issuance and automatic prepayment allocation are not part of this implementation.

The provider invoice uses an exact ShopBonus statement reference, separate aggregate reward/fee lines with configured tax/ledger IDs, configured seller/contact/workflow, EUR and exact decimal strings. Remote reference, administration, contact, seller/workflow, currency, tax mode, gross amount, unpaid balance, line descriptions/quantities/prices/tax/ledger IDs and state are checked before recording success. No redirect is followed and no write is retried automatically.

Create and send are claimed under a row lock, durably entering `create_uncertain` / `send_uncertain` before egress. A concurrent or replayed command cannot start a second write. Crash, timeout, rejected/malformed response or ambiguous persistence leaves a reviewable uncertainty; an HTTP error is not taken as proof that no external record exists.

Recover an uncertain creation only by explicitly supplying the known existing Moneybird invoice ID:

```bash
php artisan shopbonus:moneybird-invoice bind <EXPORT_ULID> --remote-id=<MONEYBIRD_ID> --actor=<ADMIN_ULID>
```

`bind` performs an authenticated read and verifies the complete snapshot. Confirm the unique actual provider invoice in Moneybird; do not manually create a replacement to work around an ambiguous POST. An uncertain send followed by a draft read stays uncertain and cannot resend. An issued provider read records issuance, while recipient delivery remains unverified. A successful send response records acceptance of the delivery request; it does not prove inbox delivery. Provider updated_at and selected financial facts are pinned monotonically: older observations and conflicting facts at the same timestamp are rejected, so a slow GET cannot overwrite a newer paid observation. Operations/audits retain these distinctions without raw provider bodies or tokens.

Provider `paid` plus exact zero unpaid can be recorded as an observed accounting state. It never causes a ShopBonus funding top-up, consumer credit or payment-registration request. Bank/prepayment reconciliation and corrections must use genuine administration evidence. No webhook endpoint is added; explicit reconciliation reads current provider state, so untrusted webhook claims cannot mark an invoice paid.

## API and evidence

The existing authorized GET `/api/v1/workspaces/{workspace}/invoices` retains its fields. `moneybird` is `not_connected` until a verified remote ID is bound. A bound provider draft remains `status=draft`; `status=sent` means provider-issued, independent of recipient delivery. Existing workspace/member permission and audited foreign404 rules apply.

Three actual local HTTP exchanges in `docs/qa/B68_MONEYBIRD_INVOICE_HTTP_CAPTURE.json` cover an issued synthetic statement, foreign404 and unauthenticated401. The fixture exercised the real funding/export service with explicitly fake Moneybird transport before actual Laravel HTTP serialization. Genuine Moneybird administration configuration, issuance/delivery/bank matching, recipient delivery and installed-provider acceptance remain NOT RUN. No production migration, provider egress or credential activation was performed.

Reviewed primary contract: [Moneybird sales invoices](https://developer.moneybird.com/api/sales-invoices), [payment-update guidance](https://developer.moneybird.com/webhooks/subscribing-to-payment-updates). Reads verify current provider state. Outgoing write idempotency was not assumed from Moneybird's separate incoming-webhook idempotency contract.
