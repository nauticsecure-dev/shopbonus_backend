# Merchant overview backend evidence — B62

Baseline `ddc698c`, branch `frank/review-fixes`, 2026-11-03. Backend only; no frontend implementation or runtime/API payload change in this checkpoint.

The review's missing-KPI assertion is stale: the current exact expected array already includes reserved_codes, expired_vouchers and expired_offers. Keep these fields; removing them would regress the existing contract. New nonzero lifecycle assertions exercise one reserved code, one expired issued voucher and one effectively expired offer using normal inventory/expiry services, then verify zeroes in the other workspace. Existing exact-array, code inventory reconciliation, business-timezone chart, filters, workspace switching and notification tests remain.

Foreign/nonmember workspace requests return audited404, as required by the concealment policy. A real active employee can view the dashboard (dashboard.view is a default permission) but receives audited403 when requesting the offers list without offers.view. Do not assert that such an employee is forbidden from the dashboard or change foreign requests to403. The tests now name and assert these distinct cases accurately.

Executed:

- SQLite merchant dashboard plus promotional claim API tests:15 passed /127 assertions.
- MySQL dashboard/claim tests plus real concurrent reservation and promotional-claim tests:20 passed /153 assertions. [Captured test result](B62_MYSQL_DASHBOARD_CLAIMS_RESULTS.json).
- Five actual OS-process races using committed rows and separate MySQL connections: existing last-code, eight-worker inventory and same-key reservation races; new four-way same-consumer claim retry and eight-consumer/three-code claim contention. One voucher per retry key; unique code allocation, issued stock counts and no sponsored obligations for promotional claims checked.
- PHPStan512MiB, targeted Pint and diff checks passed.

Only isolated `shopbonus_testing` was used for MySQL, including concurrency cleanup. Main shopbonus DB and sharedRedis were untouched. SQLite concurrency is not claimed: these process races require MySQL/PostgreSQL and are skipped on SQLite. Local race tests call the actual backend service; they are not concurrent HTTP browser journeys or deployed Redis/worker tests.

No new staging benchmark was run. The earlier 10,000-code/claim SQLite number is not evidence of staging P95<300ms, concurrent browser requests or representative MySQL/Redis load. No staging environment/access was supplied. Real authenticated browser navigation/workspace switching/filtering/badge reconciliation, deployed workers, staging load/P95 and original approved screenshot comparison remain NOT RUN/unverified here. Do not mark the full merchant overview card or independent release gate accepted from these tests.

Full current-build suite is not declared green. Prior merged SQLite broad run failed, including independently reproduced local bicubic GD limitations. CI41650964112 for baseline ddc698c had failed its global Pint job while both database jobs were still running at inspection. Backend-focused formatting/static passes do not supersede that CI result. No provider/frontend acceptance or production deployment occurred.
