# Revised platform brief review — 2026-11-02

Status: **PARTIAL / production NO-GO**. This is a scoped follow-up to the owner's six findings, not a new whole-platform readiness audit. Existing services are extended. Branch `HassanDev`, base `cb53b8453da51fbd376b09dc33ae234407988772`. Changes remain uncommitted; no push, deployment, provider activation or persistent application database migration.

## Finding dispositions

| Finding | Current code and evidence | Disposition |
| --- | --- | --- |
| Raw email verification code in logs | Logging was already removed in this checkout. `EmailVerificationCodeTest` verifies codes do not appear in logs/audit, with notification delivery retained. | Verified locally; no second removal needed. Existing deployed logs were not inspected or modified. |
| TradeTracker PHP 8.5 fatal | Current override already includes the optional sixth `?string $uriParserClass` parameter. All 14 transport tests execute on PHP 8.5.0. | Verified on installed runtime; exact PHP 8.5.11 unavailable / NOT RUN. WAMP emits an existing incompatible Xdebug DLL startup warning. |
| Replacement once per original voucher | Existing `assertVoucherNotCompensated()` locks the original voucher and checks compensation across direct-voucher and checkout reports. Allocation now uses `dispute-replacement:<original voucher public ID>`, backed by the existing unique `(webshop_id, idempotency_key)` inventory constraint. Reopening a report is allowed; granting another compensation is rejected. | Added original-voucher allocation key and direct replacement/re-report regression. No new table or migration. MySQL concurrent resolution acceptance remains NOT RUN. |
| Ecommerce installations incomplete | Every provider retains `verification: unverified`. Lightspeed E-Series, Squarespace, Mijnwebwinkel and CCV Shop have no connector. PrestaShop's private module ZIP remains unshipped. | Release blocker retained; fixtures do not prove installation. Follow [provider verification](../integrations/VERIFICATION.md). No availability or verification flag promoted. |
| Onboarding / catalog / connection failures | Current tests reproduce the intended contracts and pass. NL is the initial country, so draft missing fields include KvK, not country_code. Catalog fixtures carry verified ownership and return native `/data/currencies/current` EUR responses before product/coupon sync. Capability fixtures carry verified ownership and assert successful response envelopes before reading `data`. | Verified current fixes; production validation/ownership/currency gates preserved. Relevant suites: `OnboardingTest`, `CatalogSyncTest`, `CapabilityAndCatalogTest`, `ConnectionsAndWebhooksTest`. |
| Cover OpenAPI missing | Current OpenAPI already documents `GET /api/v1/public/webshops/{slug}/cover`, including binary success and error responses. Coverage passes. Fresh actual HTTP missing-cover 404 captured. | Verified locally; existing documented path retained. |

## Acceptance alignment

**Affiliate timing:** This owner brief supersedes D-089/D-107 and the earlier settlement-gated launch checkpoint for new reward evaluations. Default timing is `on_validated_attribution`. The processor reads it once and freezes it with the versioned rule. Trusted import/sync/admin attribution, currency, duplicate-purchase, source-conflict and review safeguards still apply; a click or untrusted postback earns nothing. Historical rules/timing and append-only ledger history are preserved. Rejected/reversed instant rewards keep their consumer credit and record the network change for reporting. Credit retains 12-month expiry.

**Scenario 6:** Configure an active EUR-credit rule with `share_bps: 10000`, minimum zero and no amount cap for the fixture. Import eligible commission **475 cents**, replay it, then reject it. Expected: exactly one 475-cent spendable credit, pending zero, no automatic clawback, 12-month expiry. Do not change the global 70% share to make this example pass. The regression uses 4.75 commission, not 9.50 at a 50% share. Independent settlement remains accounting evidence, not a gate for new consumer rewards.

**Scenario 2:** Retain the existing audited **404** policy when a non-member or inactive member requests another workspace. Verify `workspace.access_denied`, no resource data or mutation, and the same concealment response as a nonexistent tenant. A current member lacking an action permission still receives an audited **403**. The original Trello export remains a historical requirements snapshot; this acceptance clarification takes precedence over its unconditional 403 wording.

**Answer24 ownership:** `OrderEligibility` checks are ShopBonus platform disclosure safeguards before sharing paid-order/contact data and claim opportunities: merchant eligibility, verified payment, usable contact details, declared basis/consent and suppression. Eligibility does not instruct a call or establish Answer24's final decision. Answer24 owns final outreach decisions, scheduling and execution, including permitted reminders. No safeguard was removed and no calling engine was added.

## Executed evidence

- PHP 8.4.25 baseline: 86 passed / 8,445 assertions across OTP, SOAP, disputes, onboarding, catalog, connections and OpenAPI.
- PHP 8.4.25 final combined regression: 172 tests, **171 passed / 1 skipped**, zero failures/errors, **9,197 assertions**. Includes all affiliate tests, benefits, business journey, disputes, OTP, onboarding, WooCommerce catalog/capabilities, OpenAPI and workspace isolation. The single existing skip is the AI image-cover cross-reference case (`AffiliateCashbackOffersE2ETest::test_acceptance_04_ai_image_cover_is_covered_by_merchant_enrichment_e2e`); it is not image acceptance evidence.
- PHP 8.5.0 SOAP: **14 passed / 98 assertions**. Exact 8.5.11 NOT RUN.
- Additional wallet/checkout regression: **55 tests, 50 passed / 5 skipped**, zero failures/errors, **455 assertions**. Skips require MySQL process concurrency (two wallet and three checkout races). This overlaps the dispute suite above; counts must not be added as unique tests.
- Final OpenAPI check after documentation updates: **3 passed / 7,780 assertions**.
- Targeted application PHPStan: zero errors. Targeted Pint passed. Git whitespace check passed.
- Four actual loopback HTTP captures in [REVISED_BRIEF_HTTP_CAPTURE.json](../api/REVISED_BRIEF_HTTP_CAPTURE.json): wallet after trusted import/replay, wallet after rejection, non-member workspace 404, missing cover 404. Capture asserts one credit entry and one audited workspace denial. Reproduce with `php scripts/capture-revised-brief.php`. Synthetic actors/export fixtures and an isolated SQLite database; bearer tokens redacted; temporary database/server/log removed. This is not browser, CSRF or live-provider proof.
- Initial financial run: two failures (existing allocation-field expectation and points fixture assuming old default) corrected; final combined rerun above passed. Initial HTTP capture fixture setup missed required connection/program fields; corrected before the successful captures. No failed attempt is counted as acceptance evidence.

JUnit files are retained beside this report with the `revised-brief-` prefix. Checklist items remain unticked pending an implementation commit/SHA, per `AGENTS.md`.

## Remaining release work

Review and commit the scoped patch, then deploy through the established process and rebuild Laravel's config cache so new evaluations use the revised timing. Do not mass-update existing transaction snapshots or credit evidence. Verify the matching consumer UI language against the new default. Run clean Linux/full CI and MySQL concurrency tests against the release SHA, including overlapping reports through voucher/checkout subjects. Complete authentic staging installation, payment, webhook/refund, recovery and performance evidence for each supported provider; implement missing adapters and ship private modules before claiming the full ecommerce definition of done. Provider accounts, modules and staging evidence are not supplied by this local run. No production readiness claim is made.
