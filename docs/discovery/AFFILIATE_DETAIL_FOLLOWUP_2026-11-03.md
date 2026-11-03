# Affiliate detail review follow-up — 2026-11-03

Status: PARTIAL. Backend branch HassanDev, base b92f46ab2b471fc4796310e059568bdddde276a4; changes uncommitted. Existing unrelated edits preserved. No deployment, application-database migration, live provider request or reward-policy change.

## Cover diagnosis and fix

Before edits, all nine enrichment tests passed on CLI PHP 8.4.25 (42 assertions). The two reported failures also passed unchanged on Wamp PHP 8.5.0 (18 assertions). CLI GD reports WebP support; direct encode and PNG decode/scale/WebP probes succeeded. The reported line numbers match the current generator/test, but the earlier failure is not reproduced. No root cause for that earlier execution is established; do not attribute it to missing codecs or claim live AI operation.

An independent storage defect was found and fixed: an adapter throwing during put escaped the ProviderFailure cover fallback, and cleanup could mask the original error. The generator now maps write exceptions to cover_storage_unavailable, includes the attempted path in cleanup (a failed adapter may have written partial bytes), and reports cleanup exceptions while preserving the original failure. Cleanup is best effort: a failed deletion may leave an orphan; no durable cleanup/retry guarantee is claimed. Existing validation, dimension/pixel bounds and responsive WebP transcoding remain intact.

All enrichment tests now use isolated fake storage. Three new pipeline cases cover false writes, thrown writes and thrown cleanup, checking attempted-path cleanup, actual encoded WebP bytes, retained copy proposal and awaiting_review fallback.

## Executed checks

- PHP 8.4.25 enrichment: 12 passed, 72 assertions. JUnit: cover-review-php84.junit.xml.
- PHP 8.5.0 enrichment: 12 passed, 72 assertions. JUnit: cover-review-php85.junit.xml.
- PHP 8.5.0 Enrichment + Affiliate + WebshopDiscoveryE2E + OpenApiCoverage: 93 tests, 92 passed, one skipped, 8558 assertions. JUnit: affiliate-detail-review-2026-11-03.junit.xml. The existing skipped affiliate scenario delegates image coverage to the enrichment suite.
- Added an explicit positive raw access-rule snapshot guard afterward: DaisyconCompleteness 5 passed, 16 assertions. JUnit: provider-evidence-guard.junit.xml. An allowed=true snapshot is retained without enabling program cashback or creating offer versions/reward rules.
- Wamp PHP 8.5 emits an existing mismatched Xdebug DLL startup warning; PHP configuration unchanged.
- Initial targeted PHPStan hit its 128M memory limit; retry uses --memory-limit=512M. Final result recorded in the checkpoint below.

No API routes, payload contracts, packages or schema changed. Full suite, MySQL concurrency, live AI/provider calls and browser journeys NOT RUN in this follow-up.

## Remaining work and evidence required

1. Provider-to-offer normalization remains incomplete. SyncDaisyconEvidence stores snapshots; it does not establish reviewed conditions, exclusions or advertiser permission. Obtain verified account-specific payload/schema evidence, define the mapping and review/version transitions, then implement and test those mappings. Fixture field names are not a provider contract.
2. WebsiteResearcher stores candidate URLs/alt text only. Approved reusable assets still need safe fetching, decode/type/size checks, rights/source evidence, explicit approval, private staging, publication and lifecycle handling. Discovery alone is not asset approval.
3. Execute genuine Daisycon and TradeTracker imports and account-permission checks, followed by replay/rejection and wallet reconciliation against frozen rules. For historical settlement-gated rewards, retain actual remittance references/amount/currency/date. New immediate rewards follow the November 2 owner policy; do not restore old settlement gating.
4. Supply the reference design and execute real-service NL/EN desktop/mobile reveal/copy/tracked-shopping journeys. Existing controlled fixture screenshots do not prove reference fidelity, real code inventory, attribution or settlement.

Exact next action: collect authorized provider sample/schema and advertiser-rights evidence for a reviewed normalization boundary, then execute acceptance against a chosen release SHA. Keep the checklist open until a tested implementation SHA and applicable acceptance evidence are available.

Final quality checks: targeted PHPStan passed with zero errors at 512M; targeted Pint and scoped git diff --check passed. No whole-repository green claim is made.
