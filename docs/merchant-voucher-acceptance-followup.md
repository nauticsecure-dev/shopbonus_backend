# Merchant voucher acceptance follow-up — 2026-11-02

Release acceptance remains PARTIAL. This supersedes the open-policy questions in older summaries, not their historical evidence. Backend base `bd76ff4`; frontend base `b92f46a`, both on `HassanDev`. Follow-up changes are uncommitted; no deployment or persistent database migration.

## Policy acceptance

- Reservation policy is resolved by the owner's 2026-11-02 clarification in `DECISIONS.md`: page opening calls only `POST /consumer/checkouts/quote`; Confirm/Pay invokes `POST /consumer/checkouts`. Preserve bounded reservations at that explicit payment-start boundary and release on failure/expiry. The checkout page's loading effect and `confirmPurchase` implement this distinction; the context's unlock flow also requires confirmation.
- Per-consumer limits are deliberately excluded by owner decision D-106 (2026-10-31). Keep the overall offer allocation limit. No new cap or migration is required under the current decision.
- The existing regression `CheckoutTest::test_opening_checkout_quotes_does_not_reserve_before_explicit_payment_start` passed in this follow-up.

## Executed local evidence

Command: PHP 8.5 `vendor/bin/phpunit --filter 'WebshopDashboardOffersE2ETest|VoucherDisputeTest|CheckoutTest|VoucherInventoryTest|MerchantOverviewE2ETest|OpenApiCoverageTest' --log-junit docs/merchant-voucher-followup.junit.xml`.

Result: 75 tests, 72 passed, 3 skipped, 8,468 assertions, no failures/errors. PHP emits an existing incompatible Xdebug DLL startup warning; PHPUnit still completes successfully. The three race cases require real database locking and `pcntl_fork`, unavailable in this Windows PHP. An elevated read-only WSL inventory returned no installed distributions. No race test was changed or reclassified as passing.

Command: PHP 8.5 `scripts/capture-voucher-inventory.php`. Final result: 23 real HTTP exchanges, exit 0, using a temporary PHP server, synthetic bearer-auth merchant and isolated migrated SQLite database. HTTP creates/publishes an offer and previews/commits a two-code import. Dashboard counters are asserted after import (2 available/0 reserved), service reservation (1/1), idempotent replay (1/1) and release (2/0). The reservation/release operations use the real inventory service in the fixture process; they do not simulate provider payment confirmation.

The same final run imports 2,001 additional codes: commit returns 202/committing; an actual isolated database queue worker completes the job; status becomes committed and available inventory becomes 2,003. A forced queued export returns 202, early download returns 409, the database worker builds the CSV, status becomes ready, download returns 200 and expiry returns 410. Temporary database/server/log/export removed by `finally`. Requests and actual responses: `api/VOUCHER_INVENTORY_HTTP_CAPTURE.json`. This proves local queue execution, not deployed worker supervision.

`--browser` additionally launches real Chrome/Next dev against the temporary backend, logs in with a synthetic merchant through CSRF/session auth, checks the desktop dashboard, imports another code through authenticated HTTP, verifies focus refresh returns actual updated stock, and opens the editor at 390px without horizontal overflow. Assertions passed with no intercepted requests. Evidence: sibling frontend `docs/merchant-voucher-local-acceptance.json` and desktop/mobile PNGs. Final browser assertions and bounded process cleanup completed; the subsequent queue extension initially failed, then passed in the independent final backend run above.

Final local timing sample (30 requests per endpoint after 5 warmups, concurrency 1, one shop/offer and three codes): dashboard P95 583.82 ms; offers P95 206.89 ms. Dashboard does not meet 300 ms in this small local dev-server run. This is not representative staging proof.

Validation history: first cold dashboard response wait timed out; subsequent real browser assertions passed. An initial browser shutdown hung and the confirmed helper process tree was stopped; cleanup is now bounded. Large request capture first exposed the Windows PHP temporary-directory startup notice; the harness now explicitly uses its writable storage directory and hides PHP display_errors. The queued-import assertion initially expected 200; corrected to the API's existing 202 contract. No application gate was relaxed.

Dependency remediation: Next.js/eslint-config-next 16.4.4, PostCSS 8.5.32, locked sharp 0.35.5. Fresh full npm audit: 20 moderate, zero high/critical. Production-only npm audit: zero vulnerabilities. Remaining moderate development-tool chains require further compatible upstream remediation; audit proposes major downgrades (ESLint 4, eslint-config-next 0.2.4, drizzle-kit 0.18.1), which were not applied. TypeScript, targeted ESLint and a 65-page production webpack build passed. Existing metadataBase warning remains. No full green dependency audit is claimed.

## Full brief acceptance map

| Brief acceptance | Local evidence | Release gap |
| --- | --- | --- |
| 1 Dashboard and empty state | Backend dashboard cases; genuine local merchant login/dashboard/focus refresh | Full quick actions and staging acceptance |
| 2 Offer creation | Offer creation/publishing and terms in feature suite; real HTTP fixed-discount creation; prior mobile editor fixtures | Authenticated percentage/fixed creation through responsive UI |
| 3 Validation | Backend invalid terms/dates/foreign-offer cases; real HTTP 422; local session/CSRF login | Browser mutation/error-recovery matrix |
| 4 Lifecycle | Publish/schedule/advance/pause/resume/archive cases | Deployed scheduler execution and refreshed UI |
| 5 Code import | Real HTTP small and 2,001-code queued imports; actual database worker; browser focus refresh | Actual worker failure/retry and mobile progress |
| 6 Atomic issuance | Checkout/points/idempotency tests pass sequentially | Three process race cases SKIPPED; run Linux plus an isolated MySQL/PostgreSQL database |
| 7 Reusable codes | VoucherInventoryTest reusable quota and redemption tests; prior voucher-reference UI fixture | Real shared-code journeys and verified redemption source |
| 8 Historical validity | Backend immutable snapshot/ownership tests and historical merchant-label regression | Authenticated consumer journey after merchant edit/pause |
| 9 Permissions/disputes | Tenant isolation/disputes feature tests; real queued CSV 409/200/410 responses | Real employee sessions and browser disputes/audit review |
| 10 Production readiness | Local real-API desktop/mobile editor, queue/import/export/inventory captures; OpenAPI coverage; high/critical remediation | Remaining moderate tooling advisories, full real-service journeys, staging P95 <300 ms |

## API documentation acceptance

`OpenApiCoverageTest` passes in the fresh suite: every registered versioned API/auth operation has an OpenAPI entry, request sample, response examples, matching access and workspace permission declarations. This is structural coverage; it cannot prove that every example was captured or every sentence matches current behavior.

The readable handbook includes offers, code imports, checkout, voucher/dispute operations and the dashboard capture. Existing queued-export examples were recorded from tests at B46; this follow-up adds actual 202/queued/ready/CSV/409/410 HTTP captures to the handbook. Full documentation acceptance remains open for exhaustive provenance/semantic review of historical examples and the remaining permission/error cases. No hand-written success response was introduced to close that gap.

Fresh inventory exchanges are independently reproducible and are linked from the handbook. Other required error statuses retain their existing OpenAPI contracts and regression coverage; this follow-up does not claim to have exercised all of them over HTTP.

## Remaining execution requirements

Owner requested local dummy-data checks instead of staging access. The isolated real-API checks above honor that instruction. Complete the remaining local merchant/employee/consumer browser scenarios, including actual UI save/import/export/dispute failure recovery; representative staging release acceptance remains a separate requirement. Do not activate providers or mutate customer data to manufacture evidence.

Run the three `ConcurrentCheckoutTest` cases on Linux with `pcntl` and an isolated MySQL/PostgreSQL database. These fixtures truncate tables: never use the running application database.

Measure dashboard/list P95 on a clean staging release SHA with representative records, authenticated roles and load. Record dataset sizes, request count, concurrency, warmup, elapsed time, status/error rate and per-endpoint P95. A health response or local SQLite capture is not staging performance proof.
