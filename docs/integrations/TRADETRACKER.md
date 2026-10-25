# TradeTracker backend handoff — #19 / B10b

The main SOAP transport is implemented; the senior developer configures account access. No credentials are required from the owner in chat. No new routes or migrations.

## Deployment configuration

1. Install PHP `ext-soap` (`composer install` checks it); CI installs it explicitly. Keep the pinned `resources/integrations/tradetracker.wsdl` in deployment artifacts.
2. Obtain an authorized publisher Web Services customer ID/passphrase and affiliate site ID through the TradeTracker account. Confirm site currency is EUR and program cashback/reference policies permit the intended traffic. Program commission snapshots currently use the existing EUR mapping; do not enable non-EUR site accounts without a currency-aware program mapping. There is no inferred points/EUR exchange rate.
3. Set `TRADETRACKER_API_ENABLED=true` in the deployment environment and reload config/workers. Default false reports `limited` / `transport_disabled` without sending credentials. This switch permits explicit sync requests, not a new automatic schedule.
4. As a confirmed-MFA Super Admin with recent password confirmation, use existing `POST /api/v1/admin/affiliate/connections` with `network: tradetracker`, name and credentials `{customer_id, passphrase, site_id}`. IDs are digit strings. These are encrypted connection values, not public settings or client environment variables. Never commit them. Existing connections can be re-tested and synced; disabled connections remain disabled.
5. Run the existing `/connections/{public_id}/test` and `/sync` routes, inspect safe status/error codes, program permissions and unmatched conversion review. Adapters still return `verified:false` because a fixture test is not account verification. Record genuine evidence in VERIFICATION.md before production activation.

## Contract and behavior

Source: [official HTTPS WSDL](https://ws.tradetracker.com/soap/affiliate?wsdl), retrieved 2026-10-25. SHA256 `a16326978afe0eab2c94bc30a6d7b4e60f7c756332a7897b414e1f4d68d6fb0d`. Provider context: [official publisher knowledge centre](https://tradetracker.com/caen/knowledge-centre/for-publishers/). The WSDL defines `authenticate`, `getCampaigns`, `getConversionTransactions`, limit/offset filters, registration/assessment date filters and policy enums. Review/version a WSDL change and rerun serialization tests rather than downloading it dynamically in production.

- Fixed HTTPS provider endpoint. Native SOAP serializes the pinned schema; Laravel HTTP handles TLS, isolated per-client authentication cookies, no redirects/retries, 5-second connect / 20-second request timeouts. Only authenticate and read methods are used; sandbox=true, demo=false. SOAP tracing is off.
- Pages contain up to 100 records, sorted by ID. Repeated IDs/pages and malformed arrays fail closed; 100-page ceiling per query rejects truncation instead of advancing the watermark. Large accounts require a deliberate partition/backfill extension before sync. Synchronous admin sync can take multiple requests; deployment proxy/request timeouts must accommodate the account size. No unbounded retries.
- Initial conversion window is 90 days. Subsequent polling uses existing last-sync minus seven days. Registration and assessment queries are unioned by conversion ID so recent approval/rejection of an older conversion is included. This is not full historical backfill.
- A complete successful program snapshot deactivates disappeared programs. Failed pulls do not partially import records or advance last sync. Sync locks/rechecks the connection before atomic ingestion, preventing a disable during fetch from re-enabling it.
- Decimal XML values stay strings before integer minor-unit conversion. Negative commissions are rejected for review rather than converted to positive rewards. Existing status mapping and WalletService remain authoritative. Postbacks still cannot authorize rewards; no new live credit policy is activated.
- Cashbacks require `allowed` (legacy boolean true import retained). `limited`, `disallowed`, null and false never authorize cashback. Programs explicitly lacking reference support are inactive. Legacy admin-import field names remain supported; missing import currency retains the existing EUR default. Provider conversion IDs/order references are not invented; unknown click references enter review.
- Provider faults/bodies, tokens and passphrases never enter connection errors or sync audit metadata. Codes include `transport_disabled`, `connection_disabled`, `credentials_invalid`, `credentials_rejected`, `authentication_failed`, `connection_failed`, `http_<status>`, `provider_request_rejected`, `invalid_provider_response`, `invalid_provider_pagination`, `provider_page_limit_exceeded`; unexpected adapter exceptions become `provider_sync_failed`.

## Verification boundary

Synthetic SOAP fixtures exercise real native WSDL serialization/deserialization, application sync, idempotent imports and negative paths. Real HTTP documentation captures use those fixtures explicitly. Genuine account authentication, cookie behavior against the provider, actual attribution/commission examples, account-specific limits/terms and staging performance remain senior-developer verification. Existing Daisycon live verification remains outstanding; its independent REST transport is unchanged apart from safe shared sync-error handling and atomic ingestion.

Rollback: set `TRADETRACKER_API_ENABLED=false` and reload configuration. Imports remain available. Disabling an individual connection additionally stops imports/postbacks/selection through existing controls. No database rollback is needed; previously posted wallet entries remain immutable.
