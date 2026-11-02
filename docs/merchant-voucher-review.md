# Merchant dashboard, offers and voucher review — 2026-11-02

Status: local implementation verified; release acceptance PARTIAL. Backend HassanDev at 926ab34, frontend HassanDev at 5abab50. Changes remain uncommitted alongside pre-existing CMS/launch work. No deployment, provider activation, new package or migration.

| Finding | Current result |
| --- | --- |
| 1. Offer configuration | Editor reads and submits price_minor, separate price_points, starts_at/ends_at, allocation_limit and code_mode through the existing versioned API. Money uses minor units; browser-local campaign inputs convert to explicit UTC timestamps; blank values explicitly clear nullable settings. Backend remains authoritative for eligibility and validation. |
| 2. Shared-code redemption | Backend already requires voucher_id for reusable codes and resolves it within the authorized webshop/code. Frontend now sends the full customer voucher reference. Existing backend negative/replay/ownership tests pass. |
| 3. Checkout reservations | Owner confirmed reserve when payment explicitly starts. Current checkout page loads quote only; Confirm/Pay invokes POST checkouts (provider payment start plus bounded reservation). No checkout engine change needed. Added a regression proving repeated quote calls create no checkout/voucher/reservation or external payment; explicit start reserves. Wallet-only atomicity and failure/expiry release retained. |
| 4. Per-consumer limits | D-106 explicitly declines this optional feature. Retained the overall allocation_limit; no new per-consumer rule or migration. |
| 5. Large imports | Commit response remains pending while committing. Poll existing batch status, report imported count only on committed, retain processing dialog/reopen action, show failed state and a fresh preview retry. A polling failure has a status retry. Concurrent replacement of an active batch is disabled; timers and late responses are cancelled on workspace unmount. |
| 6. Dashboard | Added scoped reserved_codes, expired_vouchers and expired_offers using existing models/lifecycle rules. Added visible drill-down summaries. Existing offer pagination verified. Compare claims/issuance/redemptions over an equal adjacent calendar period via existing lifecycle aggregates, using server business dates/timezone. Current inventory is not labelled as historical inventory. Existing 30-second/focus/visibility refresh retained; focus refresh verified with changing API fixtures. |
| 7. Disputes | Backend pagination already exists. Frontend now sends page, consumes metadata, browses older records and resets/clamps pages after filter/count changes. |
| 8. Large exports | Existing backend queues large reports, restricts job/status/download to requesting member/workspace, and checks readiness/expiry. Frontend now handles HTTP 202, polls, offers protected CSV download only when ready, and exposes failed/expired/status retry paths. No JSON is downloaded as CSV. |
| 9. Historical labels | Backend already uses terms_snapshot for merchant list and checkout history; regression coverage verifies issued labels survive offer edits. |
| 10. Operational evidence | Local evidence below; production acceptance remains open. |

Executed evidence:

- PHP 8.5 isolated SQLite: 75 tests, 72 passed, 3 skipped, 8,462 assertions. Classes: WebshopDashboardOffersE2ETest, VoucherDisputeTest, CheckoutTest (also matches PointsCheckoutTest and ConcurrentCheckoutTest), VoucherInventoryTest, MerchantOverviewE2ETest, OpenApiCoverageTest. JUnit: merchant-voucher-review.junit.xml. Final OpenAPI-only rerun is recorded in the handoff.
- Three skipped ConcurrentCheckoutTest cases: same-consumer simultaneous purchase, simultaneous points replay, two consumers racing for final code. RacesProcesses requires MySQL/PostgreSQL and pcntl_fork; this Windows/SQLite run cannot establish real concurrency. No skip was removed or reported as a pass.
- Five real Chrome browser journeys passed (50.5 seconds, exit 0), with intercepted synthetic merchant/API fixtures: mobile editor values/UTC submission/nullable clears/no horizontal overflow; queued import and status retry; failed import retry; shared voucher reference/dispute page 2/queued CSV download; dashboard summaries/adjacent-period query/pagination/focus refresh. Suite: ../shopbonus_frontend/e2e/merchant-voucher-review.spec.ts. These are browser UI evidence, not real-provider/authentication or staging evidence.
- Frontend TypeScript and targeted ESLint passed; targeted PHPStan passed with zero errors; targeted Pint passed after formatting the new capture script. Scoped whitespace checks passed.
- Three real loopback HTTP captures (200/401/422) from an isolated SQLite merchant fixture: api/VOUCHER_DASHBOARD_HTTP_CAPTURE.json. Script: ../scripts/capture-voucher-dashboard.php. Updated dashboard OpenAPI example and API handbook use this real captured response. Fixture database/server/log removed by the script.
- Composer locked dependency audit: no advisories or abandoned packages, merchant-voucher-composer-audit.json. Initial sandbox network failure was retried successfully with network access and a workspace cache.
- npm audit: 25 vulnerabilities (22 moderate, 2 high, 1 critical), ../shopbonus_frontend/docs/merchant-voucher-npm-audit.json. High/critical package entries: Next.js (critical), PostCSS (high), sharp (high). Report includes production and development dependencies. No dependency upgrade or unsupported mitigation was guessed.
- Dashboard browser screenshot: ../shopbonus_frontend/docs/merchant-voucher-dashboard.png (synthetic data).

Outstanding release evidence/actions:

1. Triage and remediate the npm advisories against supported versions, then rerun dependency and application checks.
2. Run the three real concurrency cases on Linux with pcntl and an isolated MySQL/PostgreSQL database; do not point truncating race fixtures at application data.
3. Execute genuine authenticated merchant/mobile flows against the deployed API, including real background import/export workers, protected report expiry and refresh after real inventory changes.
4. Measure staging P95 below 300 ms on a clean release SHA with representative data/load. No staging target or latency sample was supplied; no local timing is presented as staging P95.
5. Review this scoped patch and the unrelated pre-existing work before committing/releasing. Checklist remains unticked until a tested implementation commit provides its SHA, per AGENTS.md.

Known validation history: initial new quote regression used a voucher public ID where the existing endpoint expects an offer slug; corrected fixture and rerun passed. Initial import browser fixture intercepted the wrong preview path; corrected to the existing code-imports endpoint and all final journeys passed. Initial Pint found formatting only in the new capture script; fixed and rechecked. Whole repository suites and production build for this patch were not run; prior release evidence is not reclassified as current proof.
