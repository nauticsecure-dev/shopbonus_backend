# Offers and discount codes review — 2026-11-03

Implementation is uncommitted on HassanDev alongside pre-existing work. Backend base b92f46ab2b471fc4796310e059568bdddde276a4. No migration, deployment, provider activation or customer-data mutation.

| Finding | Implementation / acceptance |
| --- | --- |
| Older batches | New `GET /workspaces/{workspace}/offers/{offer}/code-batches` returns paginated, offer-scoped metadata under `vouchers.view`. Frontend fetches every batch page independently of inventory rows and filters. Includes all import states. Cancelled requests cannot populate another offer's list. |
| Search | Placeholder and help explicitly require a complete, exact code; reference searches are unsupported. Existing indexed hash lookup and authorization remain. |
| Sort and dates | Inventory controls send all four supported sort values and added_from/added_to. Calendar dates convert from browser-local day start/end to UTC; the end date is inclusive. Each control resets pagination. |
| Export scope | Button says “Gefilterde voorraad exporteren”; help states selections do not restrict export. All inventory filters, including assignment and dates, reach export. Ordered chunks preserve sort without skipping codes across the 500-row boundary. |
| Provider checks | Automated coupon adapter remains WooCommerce-only. Missing connection and unsupported platform states remain explicit. Manual confirmation requires a note and returns `manually_confirmed`, distinct from provider `verified`. Desktop and mobile expose both actions. Genuine provider checkout acceptance is still OPEN; synthetic tests do not close it. |
| Historical terms | Optional voucher_id on platform-validation resolves the specific voucher inside the authorized workspace AND code. Frozen terms_snapshot is authoritative. Result is returned in verification and appended to voucher/code history, without overwriting inventory status. Assigned-row Vouchercheck supplies this reference. Other historical vouchers can be checked by explicit API reference. |
| Bulk invalidation | Successful selections are removed; failures remain selected. Toast distinguishes session, permission, concealed missing resource, state conflict, rate limit, server, validation and network failures. Duplicate submission is blocked while processing. |
| Tenant acceptance | Merchant A accessing Merchant B's resource must receive generic, audited **404**, with no mutation or disclosure. Missing permissions within a visible workspace still produce audited **403**. This corrects the imported brief's cross-tenant 403 expectation; concealment is preserved. |

Real HTTP evidence: [OFFERS_CODE_REVIEW_HTTP_CAPTURE.json](api/OFFERS_CODE_REVIEW_HTTP_CAPTURE.json), 22 actual exchanges from an isolated migrated SQLite database and temporary PHP HTTP server. Reproduce with `php scripts/capture-voucher-inventory.php --offers-review`. Includes batch pages, 401/403/422/404, inventory check, historical check and manual confirmation. No provider request is made.

Controlled browser evidence is in sibling frontend `e2e/offers-code-review.spec.ts`: older batch selection, matching inventory/export filters, exact search, mixed-success invalidation retaining failures, and mobile historical/manual requests. These fixtures do not prove provider checkout acceptance or authenticated deployed operation.

Validation results are recorded below after execution. Full suites, production build and live provider acceptance are not part of this checkpoint. Checklist remains unticked until a tested implementation commit records its SHA, as required by AGENTS.md.

Executed validation:

- PHP 8.4 isolated SQLite: OffersCodesE2ETest, VoucherInventoryTest and OpenApiCoverageTest — **25/25 passed, 8,092 assertions**, no skips. [JUnit](offers-code-review.junit.xml). Includes exact UTC-offset date boundaries, 501-code sorted CSV, frozen historical terms, foreign voucher references, manual/unsupported states and employee denials.
- Three controlled Chrome UI scenarios **passed** (2.3 minutes), including mobile. Batch pagination, filter/export parity and distinct mixed-success failure recovery are asserted against actual UI requests; APIs are intercepted fixtures.
- Real HTTP capture: **22 exchanges**, exit 0, fixture database/server cleaned by finally. New batch endpoint includes actual 200/401/403/404/422 responses.
- Targeted Pint and whitespace checks passed. Final standalone TypeScript and targeted ESLint passed after the temporary Next server stopped.

Validation history: corrected the historical edit test to use PATCH with editable fields; corrected mobile selector indexing because role locators exclude hidden desktop controls. An initial concurrent Node check exhausted host memory; the standalone rerun passed after stopping the temporary Next server. A capture-only staff-token override initially sent the owner token; corrected the harness before the successful 403 capture. An intermediate coverage run found an unrelated connection-pause route missing documentation; the later full 25-test run passed against the current workspace. No application access gate was relaxed.

Frontend base: HassanDev at bd76ff420cda1aa180e45e44a521ff0707670b3f. Both repositories retain unrelated uncommitted work. No commit was created.

After refreshing examples from the final 22-exchange capture, OpenApiCoverageTest passed again: 3/3 tests, 7,848 assertions. Final capture-script Pint passed.
