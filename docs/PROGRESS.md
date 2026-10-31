# Active handoff — B31 Trello review fixes for the 10 cards in the Frank list (2026-10-31, Claude)

Branch `frank/review-fixes` (not merged, not pushed), base `main` `59916e9`. Commits: `e171ca5` regressions, `5546489` OpenAPI recovery, `1175fe1` money/vouchers, `322d538` cockpit/support/notifications, `52adf2a` Quality Center, plus a docs commit. Source: the reviewer (Oluwasola Peter) comments on #19 #20 #21 #22 #23 #26 #29 #30 #36 #37. Each item was checked against the code before it was changed. D-089 unchanged and re-applied. Credentials senior-owned.

Fixed (each with a regression test):
- Regressions from the HassanDev merge (`9f927ef`/`4aa26c2`/`2b0db78`/`04ecbfd`/`05a47b8`):
  - Plaintext OTP log removed.
  - `AFFILIATE_CREDIT_TIMING` default restored to `on_network_approval` (D-089). Config, `.env.example` and docs had been switched to instant credit.
  - `TradeTrackerSoapClient::__doRequest` accepts PHP 8.5's optional `$uriParserClass`.
  - OnboardingTest now expects `kvk_number` (country defaults to NL).
  - CapabilityAndCatalogTest targets the canonical `catalog-syncs` API. HassanDev's parallel catalog code (`CatalogSync` service, `CatalogProduct`, `CatalogCoupon`, `CapabilityMatrix`) has no routes left; it is still used by LiveBannerStudioE2ETest. Remove or merge it in a later task.
- OpenAPI: merge `9f927ef` dropped the 92 operations HassanDev had documented. They are restored with paths, parameters and schemas, and every example was re-captured on a real local server (168 exchanges, `docs/qa/B31_MERGE_RECOVERY_HTTP_CAPTURE.json`, README section).
  - Not captured, labelled illustrative: AI suggest 200 (live OpenAI key) and enrichment retry 202 (503 captured).
- Cover endpoint 404'd for shops without a logo (looked up via `logo()`). Fixed.
- #19:
  - The direct/affiliate duplicate check uses an indexed `merchant_commission_conversions.order_reference_hash` (migration `2026_10_30_200000`, backfilled). It previously checked only the first 500 rows.
- #20/#23 vouchers:
  - A reusable code redeems only the buyer's own voucher (order email hash).
  - An order placed after expiry never redeems (`voucher_expired`).
  - One compensation per voucher across consumer, merchant and checkout reports (409 `voucher_already_compensated`, voucher row lock).
  - Mollie refunds are tracked by provider status (migration `2026_10_30_210000`): checkout `refund_pending` → `refunded`, or back to `paid_unfulfilled` on failed/canceled. Retries use a new idempotency key. Synced by `shopbonus:expire-checkouts`.
  - Payment creation sends `Idempotency-Key: checkout:<id>`. Timeouts and 5xx keep the checkout for a retry; 4xx fails it.
  - Checkout history shows the purchased title.
- #21:
  - Suspending an admin ends the support sessions they started.
  - Cockpit `change_percent` compares with totals as of the end of the previous period; open actions → null.
  - One consumer definition (`User::consumers()` scope) for the cards, the user list and search.
- #26:
  - Order references like `FS-20482`, `#1042` and `bestelling 77812` are recognised.
  - Pending and reserved credit are worded separately.
  - Action labels follow the locale.
  - Deterministic FAQ matching for free-text questions.
  - `provider_cost_estimate` comes from `SUPPORT_AI_PRICE_EUR_PER_MILLION_*` (null when unset; billed cost still null).
- #29:
  - Templates may use `{{webshop_name}}` and `{{first_name}}` (plain text, bounded).
  - SPA paths come from `shopbonus.identity.frontend_paths` (`FRONTEND_PATH_*`; defaults unchanged `/app/...`).
- #30:
  - Backups include dispute, ownership-verification and QA evidence.
  - The operations overview watches every `RELIABILITY_MONITORED_QUEUES` queue (default `default,affiliate`).
- #36/#37 Quality Center:
  - Freshness follows the tested build: `provenance` is sha_verified, sha_outdated or time_only.
  - Runs accept `executed_at` / `--executed-at`.
  - Fingerprints include the controller class, type-hinted App classes and route middleware.
  - Discovery adds middleware, policies and the affiliate adapters.
  - Retests need a build containing the fix (`retest_build_outdated` / `retest_build_unverified` unless `build_includes_fix`).
  - Browser/manual PASS needs attached evidence (`QA_EVIDENCE_REQUIRED_SOURCES`).

Not changed (owner or other input needed):
- Reviewers asked for instant credit (#23, #36) and pending credit (#19, #22). D-089 stands.
- Insufficient wallet → Mollie remainder is D-053, not a bug.
- Points funding checkout is still OPEN-REWARD-UNITS.
- Admin direct-merchant commission credit (`commission-credits`) credits immediately. Check it against D-089 with the owner.
- The frontend paths for emails and support actions must be confirmed by the frontend; the defaults remain unverified.
- Hourly backups vs RPO ≤ 15 min, offsite verification, connectors for 4 platforms, CI/Postman wiring, the 175 PDF v2 checks, E2E evidence packages, and all frontend items: not in this batch.
- PHPStan: 97 errors repository-wide, all in HassanDev code from `4aa26c2` (none in lines changed here). They need a separate cleanup task.
- PHP 8.5 is not installed locally; the SOAP signature is verified on 8.4 only.

Evidence:
- Full SQLite suite 1117 tests (1093 passed + 24 skipped) after the last code commit. Full MySQL run: see PROGRESS.
- OpenApiCoverageTest PASS.
- Pint PASS.
- Capture environment (isolated SQLite, local server) and capture uploads/sessions removed afterwards.

Main local `shopbonus` DB: migrations `2026_10_30_200000` and `210000` NOT applied there.

Exact next:
- Push the branch and open a PR after the owner/senior review.
- Answer the reviewers on the D-089/D-053 items.
- Separate tasks: HassanDev PHPStan cleanup, and the duplicate catalog code.

---
# Active handoff — `shopbonus:create-admin` command (2026-10-30, Claude)

Owner request: a command to create an admin with name, email and password. `php artisan shopbonus:create-admin [--name=] [--email=] [--password=]` prompts for missing values (password hidden, asked twice), validates with the signup rules (`Password::defaults()`: min 10, letters + numbers, breached-password check in production; unique normalized email), then creates a verified active user, grants Super Admin (`granted_via: console`) and audits `admin.super_admin_granted` (`created: true`) in one transaction. Existing email → fails and points to `shopbonus:grant-super-admin`. 2FA is still required before the admin API opens. Test: `tests/Feature/Admin/CreateSuperAdminCommandTest.php` (3 PASS on SQLite and MySQL); PHPStan 0. No migration or route. Previous: B30 `a412e89`, [CI 40929686025](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40929686025) SUCCESS on all 3 jobs.

---
# Active handoff — #37 B30 Quality Center backend; backend slice done, card not Done (2026-10-29, Claude)

Branch `main`. Base `2276fe6` (B29, [CI 40895099427](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40895099427) SUCCESS on all 3 jobs). Owner: "do it" (build #33–#35, then #37's "Laravel Backend: Frank" part). D-089 unchanged. Credentials senior-owned. #12 still waits for the owner's cashback-timing decision.

B30:
- Roles: `PlatformRole::QaTester` (`qa_tester`) and `PlatformRole::Developer` (`developer`); `EnsurePlatformRole` takes several roles (`platform:super_admin,qa_tester`) and still requires confirmed 2FA. Every QA route group includes `super_admin`, so the RoleAccessMatrix rule (Super Admin passes every platform route) holds. Neither QA role reaches any other admin route. Grant: `php artisan shopbonus:qa-role <email> qa_tester|developer [--revoke]` (audited). `ApiRouteRegistry` now reports the real roles per route.
- Migration `2026_10_29_150000_create_qa_tables`: qa_inventory_snapshots, qa_endpoints, qa_components, qa_test_cases, qa_test_coverage, qa_schedules, qa_test_runs, qa_test_results, qa_evidence, qa_findings, qa_ai_quotas.
- `App\Services\Qa\QaInventory` (discovery): routes (fingerprint = method, path, middleware, handler source lines), OpenAPI summary/documented flag, jobs, events, listeners, commands, schedules, migrations, connectors (webshop connectors + Mollie/Moneybird/Answer24/Daisycon/TradeTracker clients). Diff vs previous scan (added/changed/removed/undocumented); removed rows keep `removed_at`, nothing is deleted. Cache lock, one scan at a time. `php artisan shopbonus:qa-discover --sha=<sha>` after every deploy.
- `QaStatus`: per-endpoint status from approved test cases' latest results only: FAIL > NOT_IMPLEMENTED > BLOCKED > STALE > UNTESTED > PASS; evidence older than the endpoint's last change or `QA_STALE_AFTER_DAYS` is STALE; mocked passes are labelled. `UNREFERENCED — REVIEW` only after a frontend usage report and never for webhook/integration/external routes. Dashboard summary (totals, coverage %, P0–P3 open findings, last run/probe/scan, next run).
- `QaRuns`: JUnit import (no DOCTYPE, LIBXML_NONET; failure/error → fail, `BLOCKED:`/`NOT_IMPLEMENTED:` skipped prefixes, no assertions → never PASS, "MOCKED" names or `mocked=true`); report SHA-256 dedupe; keys use the PHP `class` attribute (dotted `classname` converted); unknown keys become draft cases; redacted messages (contact data, bearer tokens, IBANs, configured secrets). Coverage map import. JSON/JUnit/HTML export (escaped, attachment, no-store, noindex, CSP none). Encrypted evidence with SHA-256 check on download, per-run dedupe, retention; `shopbonus:qa-probe --prune` daily.
- `QaFindings`: grouped by routes+tests fingerprint (409 `finding_exists`); owner must be a developer; developer fixes with commit; retest by someone other than owner/fixer, with a run imported strictly after the fix; outcome derived from that run (every linked test PASS, unmocked). Regression plan (tests on affected routes/same controllers, money/tenant/webhook first). Advisory GPT analysis via Responses API (`store:false`, strict schema with the card's fields), references restricted to the input, never changes status/severity, proposals become draft `ai` test cases; quota + 5/min throttle `qa-ai`; 503 until `QA_AI_*` is set.
- `QaProbe` + `shopbonus:qa-probe` every 5 minutes (no-op until `QA_PROBE_ENABLED`): read-only HealthChecker + latency, run with source `probe`; failure → grouped incident via IncidentRecorder::monitoring.
- Schedules (Super Admin): production only allows the `safe` suite. Staging suites are executed by CI/staging runners, which import with `shopbonus:qa-import --schedule=<id>`.
- `GET /api/v1/admin/qa/openapi.json`: the OpenAPI contract for the self-hosted Swagger/Stoplight portal; no-store, noindex, audited.
- 28 routes under `/api/v1/admin/qa`; inventory now 362 routes (admin 163). Env: QA_AI_ENABLED/API_KEY/MODEL/DAILY_LIMIT, QA_EVIDENCE_RETENTION_DAYS, QA_STALE_AFTER_DAYS, QA_PROBE_ENABLED, QA_PROBE_SLOW_MS, APP_RELEASE_SHA.

Executed evidence:
- QualityCenterTest 10 PASS (231 assertions); OpenApiCoverageTest PASS.
- Full SQLite: 931 tests, 908 passed + 23 skipped, 13667 assertions.
- Full MySQL 8: 931 tests, 929 passed + 2 skipped, 13750 assertions, 421.9s.
- Pint PASS; PHPStan 0 errors. Migration rollback/reapply on isolated SQLite PASS (11 tables).
- Real local HTTP server capture (61 requests; imports a real PHPUnit 12 JUnit log; model stub synthetic): `docs/qa/B30_QUALITY_CENTER_HTTP_CAPTURE.json`; examples in OpenAPI and README. The capture found and fixed one defect: a run imported in the same second as the fix was accepted as a retest (now 409 `retest_run_outdated`). Some 409/410/422/429/503 examples are illustrative (`<request id>`).

Main local `shopbonus` DB: migrations 2026_10_29_100000–150000 NOT applied there.

Not done (#37 is not Done): Quality Center UI and the qa.shopbonus.nl Swagger/Stoplight hosting (Hassan/infra; backend serves the protected spec); executing the 25 PDF scenarios, Postman/browser suites and the ten provider install/order/refund subruns on staging with real Mollie TEST/provider sandboxes (Hassan + senior credentials); CI wiring of `shopbonus:qa-import` and a coverage map generator; real GPT model verification (senior sets QA_AI_*); Martijn's release approval. Exact next: #12 after the owner decides D-089, or staging verification.

---
# Active handoff — #33/#34/#35 B29 CMS scheduled publishing and "Improve with AI"; backend slice done (2026-10-29, Claude)

Branch `main`. Base `2bd4ba5` (B28, CI SUCCESS on all 3 jobs). Owner: "do it" after the board review (build #33–#35 CMS gaps, then #37 backend; #12 waits for the owner decision on D-089). D-089 unchanged. Credentials senior-owned.

B29:
- Migration `2026_10_29_140000_create_cms_schedules_and_suggestions`: `cms_publication_schedules`, `cms_ai_suggestions`, `cms_ai_quotas`.
- `App\Services\Cms\CmsScheduler`: schedule publish (per locale, pinned `draft_revision`) or archive for `due_at` > now + 1 min. Refuses archived pages, duplicate pending schedules, stale revision (409 `draft_revision_conflict`), legal publish without version/effective date/approve (422), Terms/Privacy archive (409). `runDue()` claims rows (scheduled→running), re-checks the scheduler is an active Super Admin, publishes through `CmsService::publish(..., expectedDraftRevision)` (new parameter, 409 `draft_changed` inside the lock) → `stale` when the draft changed. Rows stuck in `running` > 10 min become `failed/interrupted`. Audits `cms.schedule_created|cancelled|executed|stale|failed`.
- `shopbonus:publish-scheduled-content`, scheduled every minute without overlap.
- `App\Services\Cms\CmsSuggestions`: `improve` (same locale) or `translate` (other locale) of the current draft (or published content). Extracts text segments (title, SEO, text nodes, button labels, image alts), sends them to the OpenAI Responses API (`store:false`, strict `{segments: string[]}`), rejects wrong count, empty/too long segments, `<>`, any URL/email/number/currency not in the source, or the API key; re-inserts segments into the original structure and validates with the CMS rich-text allowlist. Idempotent per user + `request_key` (409 on different payload), daily quota (429 + Retry-After), per-user throttle `cms-ai` 5/min, 503 `cms_ai_not_configured` when off. `apply` saves the draft only (revision checked, never publishes); `reject`. Audited.
- Config `config/cms.php`; env `CMS_AI_ENABLED` (default false), `CMS_AI_API_KEY` (falls back to `OPENAI_API_KEY`), `CMS_AI_MODEL`, `CMS_AI_DAILY_LIMIT`=20.
- 8 routes under `/api/v1/admin/cms` (reads: schedules, page suggestions, suggestion detail; `password.recent`: create/cancel schedule, request/apply/reject suggestion). Inventory now 334 routes.

Executed evidence:
- CmsScheduleAndSuggestionTest 9 PASS; all CMS tests 19 PASS (244 assertions).
- Full SQLite: 921 tests, 898 passed + 23 skipped, 12912 assertions.
- Full MySQL 8: 921 tests, 919 passed + 2 skipped, 12996 assertions, 343.3s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server capture (23 requests, model upstream faked): `docs/cms/B29_CMS_SCHEDULE_AI_HTTP_CAPTURE.json`; examples in OpenAPI and README. Some 409/422/429/503 examples are illustrative.

Main local `shopbonus` DB: migrations 2026_10_29_100000–140000 NOT applied there.

Not done: Hassan's UI (schedule picker, suggestion diff/apply); senior sets `CMS_AI_*` and verifies a real model response. Exact next: #37 Quality Center backend (B30).

---
# Active handoff — #22 B28 backend release slice (B18); card not Done (2026-10-29, Claude)

Branch `main`. Base `8352e08` (B27, [CI 40880825276](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40880825276) SUCCESS on all 3 jobs). Owner: "do all you can do" on #22; frontend testing goes to Hassan. D-089 unchanged. Credentials senior-owned.

B28 (closes the backend part of B18):
- `tests/Feature/Journey/BusinessJourneyTest.php`: card #22 steps 1–17 through the real API with cross-module integrity assertions. Only DNS, mail, Answer24, Mollie and the affiliate report are synthetic. A Mollie top-up funds rewards (an offer can't activate without funding).
- `tests/Feature/Api/RoleAccessMatrixTest.php`: every `/api/v1` route as anonymous/suspended/unverified/consumer/owner/manager/employee/Super Admin (±2FA), asserting exact denial codes and exact per-role permissions. No defect found.
- `App\Http\Middleware\SecurityHeaders` (global, appended): nosniff, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, HSTS on HTTPS (`shopbonus.security.hsts_max_age`, env `HSTS_MAX_AGE`, default 1 year). Existing controller headers are kept. Proxy trust via `shopbonus.security.trusted_proxies` (`TRUSTED_PROXIES`), applied in `AppServiceProvider::boot` with `TrustProxies::at`; default off, so behaviour is unchanged.
- `shopbonus:launch-check [--json]` (25 checks, no values printed; FAIL exits 1).
- `docs/release/API_INVENTORY.md` (326 routes, from `shopbonus:api-routes`) and `docs/release/LAUNCH_READINESS.md` (evidence map, deploy/rollback runbook, open items).
- `composer audit`: 0 advisories/abandoned (PHP 8.4.17, Laravel 13.38.0).
- No migration, no new route. `.env.example` gains TRUSTED_PROXIES and HSTS_MAX_AGE.

Executed evidence:
- BusinessJourneyTest 1 PASS (95 assertions); RoleAccessMatrixTest 4 PASS (1637 assertions); SecurityHeadersTest 2 PASS; LaunchCheckTest 2 PASS.
- Full SQLite: 912 tests, 889 passed + 23 skipped, 12649 assertions.
- Full MySQL 8: 912 tests, 910 passed + 2 skipped, 12734 assertions, 339.0s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.

Main local `shopbonus` DB: migrations 2026_10_29_100000–130000 NOT applied there.

#22 remains not Done: frontend/design QA (Hassan); live store/Mollie/Answer24/affiliate runs and a staging load test (senior); owner/legal decisions (affiliate credit timing D-089, Answer24 lawful basis, live Mollie); deployment/infrastructure (TLS, TRUSTED_PROXIES, off-site backups, mail DNS, alerting). All of Frank's backend cards (#1, #22, #23, #32) now have their buildable backend delivered; the next work is staging verification with real credentials.

---
# Active handoff — #32 B27 remaining native catalog/coupon readers; #32 buildable backend complete, card not Done (2026-10-29, Claude)

Branch `main`. Base `5816192` (B26, [CI 40875885780](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40875885780) SUCCESS on all 3 jobs). Owner: "do it all" for #32. D-089 unchanged. Credentials senior-owned.

B27 adds `CatalogReader` to every remaining connector that has a native read transport, each mapping checked against the official reference or core source:
- BigCommerce: `GET /v3/catalog/products?include=primary_image` (meta.pagination), `GET /v2/coupons` (page/limit, empty page 204), currency from `/v2/store`. Types percentage_discount/per_total_discount/per_item_discount/free_shipping; shipping_discount/promotion skipped.
- Ecwid: `/products`, `/discount_coupons` (offset/limit/total), `profile.formatsAndUnits.currency`. ABS/PERCENT/SHIPPING and *_AND_SHIPPING.
- Magento 2.4 (admin-schema-2.4.8): `products` (total_count), `salesRules/search` coupon_type SPECIFIC_COUPON + primary `coupons/search`; by_percent/by_fixed/cart_fixed; buy_x_get_y and auto-generated codes skipped; to_date → end of day in store timezone; currency from storeConfigs.
- PrestaShop 9: `products`, `cart_rules` (limit offset,100), currency via `configurations` PS_CURRENCY_DEFAULT → `currencies/{id}`. Price stored as returned (tax basis not documented). Codeless rules skipped.
- Shopware 6: `search/product` (main products visible in the bound sales channel) and `search/promotion` (sales channel, discounts association); system currency `b7d2554b…`. Individual codes, multiple/set-group/fixed-price discounts skipped.
- Wix: Catalog version via `/stores/v3/provision/version`; V3 `/stores/v3/products/query` (cursor) or V1 `/stores-reader/v1/products/query` (offset); coupons `/stores/v2/coupons/query`; currency `/site-properties/v4/properties`. Fixed-price and buyXGetY skipped.
- `PinnedJsonReader::read(..., allowEmpty: true)` returns an empty list for 204. Shared helpers moved to `CatalogValues` (row defaults, enum, exact percent, ISO/epoch dates).
- No migration, no new route.

Executed evidence:
- PlatformCatalogTest 6 PASS (one products + coupons pass per platform, unsupported shape skipped, encrypted codes, no write methods).
- Full SQLite: 903 tests, 880 passed + 23 skipped, 10898 assertions.
- Full MySQL 8: 903 tests, 901 passed + 2 skipped, 10980 assertions, 331.0s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server capture with synthetic official-schema fixtures: docs/integrations/B27_PLATFORM_CATALOG_HTTP_CAPTURE.json, OpenAPI examples, README.

Main local `shopbonus` DB: migrations 2026_10_29_100000–130000 NOT applied there.

Whole #32 remains not Done (external/out of scope): real installed stores and live evidence for all ten integrations (senior credentials); Lightspeed C/E, CCV, OpenCart, Squarespace, Mijnwebwinkel have no native read connector (no verified contract/credentials) and report UNSUPPORTED; storefront banner publication is merchant-side plugin/app scope. Exact next: #22 (release QA, mostly external), or live verification on staging when the senior configures store credentials.

---
# Active handoff — #32 B26 Shopify catalog/discount reader; whole #32 not Done (2026-10-29, Claude)

Branch `main`. Base `9d5281d` (B25, [CI 40851533268](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40851533268) SUCCESS on all 3 jobs). Owner: #1 backend done → moved to frontend (Hassan); continue #32 then #22. D-089 unchanged. Credentials senior-owned.

B26:
- `CatalogReader::catalogPage(..., ?string $cursor, ...)` now returns `{items, next}` (cursor-based). WooCommerce uses the REST page number as cursor. `CatalogSynchronizer` follows `next`, refuses a repeated cursor (`provider_pagination_invalid`).
- `ShopifyApi::catalog()` / `shopCurrency()`: fixed Admin GraphQL 2026-10 queries `ShopbonusCatalogProducts` (products first 50, read_products), `ShopbonusCatalogDiscounts` (discountNodes first 25, `method:code`, read_discounts), `ShopbonusCurrency`; identity/domain gate first (`identity()` accepts a null scope for currency only).
- `ShopifyCatalog` mappers (fields verified on shopify.dev 2026-10 reference; deprecated codeDiscountNodes/customerSelection/featuredImage avoided). Single-code DiscountCodeBasic/FreeShipping → coupon; BXGY/app/bulk skipped. Percentage parsed from the exact decimal string the pinned reader returns (0.00–1.00). Discount targets first 10 (`*_partial` keys when more).
- Shared `CatalogValues` trait (extracted from WooCommerceCatalog). `ConnectionCoupon::isActive` accepts Shopify `active`.
- No migration, no new route.

Executed evidence:
- ShopifyCatalogTest 3 PASS; CatalogSyncTest 4 PASS; Shopify suites PASS.
- Full SQLite: 897 tests, 874 passed + 23 skipped, 10798 assertions.
- Full MySQL 8: 897 tests, 895 passed + 2 skipped, 10881 assertions, 322.5s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server capture with synthetic Shopify GraphQL stubs: docs/integrations/B26_SHOPIFY_CATALOG_HTTP_CAPTURE.json, OpenAPI examples, README, SHOPIFY_READ_API.md.

Main local `shopbonus` DB: migrations 2026_10_29_100000–130000 NOT applied there.

Whole #32 remains not Done: catalog/coupon readers for Shopware, Wix, Magento, BigCommerce, Lightspeed, CCV, Ecwid, PrestaShop (only from verified official schemas); real installed stores for all ten integrations; storefront banner publication; live lifecycle evidence. Exact next: Shopware 6 Admin API products/promotions reader (verify the official Admin API schema first), then Wix Stores products + coupons, then #22.

---
# Active handoff — #1 B25 catalog/coupon sync + capability report; #1 backend slice complete, whole card not Done (2026-10-29, Claude)

Branch `main`. Base `425571b` (B24, [CI 40847704631](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40847704631) SUCCESS on all 3 jobs). Owner request: continue remaining Frank cards (#1, #22, #32) easiest first; #23 backend done and moved to frontend (Hassan) by the owner. D-089 unchanged. Credentials senior-owned.

B25 adds the missing #1 backend pieces (catalog/coupon sync, per-connection capability report):
- Migration `2026_10_29_130000`: `catalog_syncs`, `connection_products`, `connection_coupons` (coupon `code` encrypted cast; email restrictions stored only as boolean `customer_restricted`).
- `App\Integrations\Contracts\CatalogReader`; `WooCommerceConnector` implements it via `WooCommerceCatalog` mappers over official REST v3 `products`, `coupons` and `data/currencies/current` (added to the `WooCommerceApi` allowlist), using the existing pinned GET-only transport. `CATALOG_SYNC_MAX_PAGES` (default 50) × 100 per page.
- `App\Services\Catalog\CatalogSynchronizer`: `start()` (409 `capability_unsupported` / `connection_disconnected` / `catalog_sync_running`), queued `SyncConnectionCatalog` job runs `run()`: cache lock, generation check, each page stored under the connection row lock only at the starting credential generation, unseen items marked `removed_at` only after a complete pass; page cap → `partial` + `catalog_page_limit`. Audits `integration.catalog_sync_requested/finished`.
- `ConnectionCapabilities::report()`: orders, payments, refunds, products, categories, coupons_read, coupons_write (always UNSUPPORTED), banner_embed (LIMITED, manual embed), webhooks, backfill — AVAILABLE only on evidence.
- Routes under `workspaces/{workspace}/connections/{connection}/`: `GET capabilities`, `GET products`, `GET catalog-syncs` (integrations.view); `GET coupons`, `POST catalog-syncs` (integrations.manage, commerce-probe throttle).

Executed evidence:
- CatalogSyncTest 4 tests PASS (sync + encrypted codes + no PII + GET-only, removal on complete pass but not partial, generation-change discard, 422/409/403/404 isolation).
- Full SQLite: 894 tests, 871 passed + 23 skipped, 10740 assertions.
- Full MySQL 8: 894 tests, 892 passed + 2 skipped, 10824 assertions, 326.1s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server captures (synthetic WooCommerce stubs, isolated SQLite): 401, 200, 422, 202, 404, 403, 409 in docs/integrations/B25_CATALOG_HTTP_CAPTURE.json, OpenAPI and docs/api/README.md.

Main local `shopbonus` DB: migrations 2026_10_29_100000, 110000, 120000, 130000 are NOT applied there.

Whole #1 remains not Done: live provider connections/orders, catalog/coupon readers for Shopify/Shopware/Wix/Lightspeed/Magento/CCV (#32), native storefront banner publication, AI Banner Studio, Order Sheet/catalog/coupon frontend and real Answer24 E2E. Exact next: #32 per-provider line-item/catalog/coupon mappings from official schemas (start with Shopify GraphQL products/discounts as a second `CatalogReader`), then #22 (release QA, mostly external).

---
# Active handoff — #23 B24 "Code does not work" screenshot evidence; #23 backend slice complete, whole card not Done (2026-10-29, Claude)

Branch `main`. Base `737219f` (B23o + CI evidence). Owner request: continue all remaining Frank cards (#1, #22, #23, #32) easiest first. #31 was moved to Testing by the owner. D-089 unchanged. Credentials senior-owned.

Audit of #23 against the code: every backend requirement already existed (12-month lots, oldest-expiry-first spend, one voucher model with Answer24 handoff and wallet/Mollie purchase, one-time compensation, workspace isolation, signed webhooks, audit chain) except the screenshot on the "Code Does Not Work" form. B24 adds it:
- `POST /api/v1/consumer/disputes` accepts optional multipart `screenshots[]` (max 3, JPEG/PNG/WebP, 5 MB, content-sniffed and must decode with 16–8000 px dimensions).
- Files are encrypted (`Crypt`) under `dispute-evidence/{dispute}/{uuid}.enc` before the row is created; a duplicate-report 409 or any failure removes them. Metadata in new `voucher_disputes.evidence` JSON (migration `2026_10_29_120000`).
- New `GET /api/v1/admin/disputes/{dispute}/evidence/{file}`: Super Admin only, audited `admin.dispute_evidence_downloaded`, attachment + nosniff + no-store. Merchant view exposes only a screenshot count.
- Not implemented: AI analysis of screenshots (card says "may"); replacement stays an admin decision applied at most once (existing).

Executed evidence:
- VoucherDisputeTest 6 tests PASS (new test: disguised file 422 with no stored file, encrypted at rest, orphan-free 409, consumer 403, merchant count only, admin byte-identical audited download, unknown/traversal 404).
- Full SQLite: 890 tests, 867 passed + 23 skipped, 10619 assertions.
- Full MySQL 8: 890 tests, 888 passed + 2 skipped, 10702 assertions, 319.8s.
- Pint --test PASS; PHPStan L6 0 errors; OpenApiCoverageTest PASS.
- Actual HTTP captures (php artisan serve, isolated SQLite, real session login + TOTP challenge): 422, 201, 403, 200 (byte-identical), 404, 401 in docs/support/B24_DISPUTE_SCREENSHOT_HTTP_CAPTURE.json, OpenAPI and README.

Main local `shopbonus` DB: migrations 2026_10_29_100000, 110000 and 120000 are NOT applied there.

Whole #23 remains not Done: real WooCommerce/second-platform/Mollie/Answer24 runs, frontend audit, backups and production evidence are external. Exact next (easiest first): #1 remaining backend gaps (catalog/coupon sync API, capability report per connection), then #32 per-provider line/catalog/coupon mappings from official schemas, then #22 (release QA, mostly external).

---
# Active handoff — #31 B23o Wix webhook envelope fix + AppRemoved, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `daecc72` (B23n, [CI 40842608297](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40842608297) SUCCESS on all 3 jobs). Session commits: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l, `9f0d7fa` B23m, `daecc72` B23n; all CI green. D-089 unchanged. Credentials senior-owned.

B23o fixes a real defect from B23e–h: `WixConnector::signedData` required the JWT `data` claim to be an object, but the official `@wix/sdk` parseJWT shows it is a JSON string (inner `data` also a JSON string). Every genuine Wix webhook would have been rejected (401 `store_mismatch`). The connector now parses the string envelope only, and the test fixture uses it. `AppRemoved` → `KIND_DISCONNECT`, processed by `ShopifyUninstall` (now Shopify + Wix): native Get App Instance outside locks, disconnect only on a definite 401/403, replay with a valid grant ignored. `AppInstalled` is stored without effect. App-instance events dedupe on a content hash (no event id). No migration, no endpoint or response change.

Executed evidence for this commit's tree:
- WixConnectorTest 2/9 and WixAppRemovedTest 3/13 PASS; Integrations + Api folders 162 tests PASS.
- Full SQLite: 889 tests, 866 passed + 23 skipped, 10584 assertions, 68.5s.
- Full MySQL 8: 889 tests, 887 passed + 2 skipped, 10666 assertions, 311.9s.
- Pint --test PASS; PHPStan L6 0 errors.
- CI for f14afc8: [run 40843782709](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40843782709) SUCCESS on all 3 jobs (SQLite, MySQL, lint/static analysis).

Main local `shopbonus` DB: migrations 2026_10_29_100000 (B23k) and 110000 (B23m) are NOT applied there.

Exact next (remaining #31 Laravel gaps): remaining provider line/catalog/coupon/payment/refund mappings, per provider and only from official schemas (Wix lineItems from the Order object; BigCommerce products; Shopware lineItems; Magento items). Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). Live provider verification for every platform is NOT RUN. The B23n handoff is below.

---
# Active handoff — #31 B23n Shopware app webhooks, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `9f0d7fa` (B23m, [CI 40841263271](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40841263271) SUCCESS on all 3 jobs). Session commits: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l, `9f0d7fa` B23m; all CI green. D-089 unchanged. Credentials senior-owned.

B23n: `ShopwareAppWebhookController` at public `POST /api/v1/integrations/shopware/app/webhook` (limiter `webhooks-shopware-app` 600/min per IP). It verifies the shop-secret signature via `ShopwareAppRegistration::signedByShop` (previous secret within 60s of rotation), requires a confirmed `source.shopId` and a ±600s timestamp, and dedupes on eventId. `order.written` stores one derived `order_lookup` WebhookEvent per order ID (max 25) on the bound, generation-current connection; `ShopwareConnector` now implements `OrderNotification`, reading only that derived hint. `app.deleted` → `ShopwareAppRegistration::revoke` (unbind, strip credentials, generation bump; delete the shop unless keepUserData). Activation/deactivation are audited; other events are ignored. No migration.

Executed evidence for this commit's tree:
- ShopwareAppWebhookTest: 5 tests, 58 assertions; with ShopwareAppRegistrationTest 12/118 PASS. OpenApiCoverageTest PASS.
- Full SQLite: 886 tests, 863 passed + 23 skipped, 10569 assertions, 61.3s.
- Full MySQL 8: 886 tests, 884 passed + 2 skipped, 10654 assertions, 305.6s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures: docs/integrations/B23_SHOPWARE_APP_WEBHOOK_HTTP_CAPTURE.json, OpenAPI, README (database queue; no outbound reads).
- CI for daecc72: run 40842608297 SUCCESS on all 3 jobs.

Main local `shopbonus` DB: migrations 2026_10_29_100000 (B23k) and 110000 (B23m) are NOT applied there; run `php artisan migrate` when the senior is ready.

Exact next (remaining #31 Laravel gaps): Wix App Instance Installed/Removed webhooks (verify the current Wix contract and its app-level JWT payload first); remaining provider line/catalog/coupon/payment/refund mappings (per provider, only from official schemas); Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). Live provider verification for every platform remains NOT RUN. The B23m handoff is below.

---
# Active handoff — #31 B23m Shopware app registration, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `0274a77` (B23l, [CI 40839539332](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40839539332) SUCCESS on all 3 jobs). Session commits so far: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l; all CI green. D-089 unchanged. Credentials senior-owned.

B23m: `App\Integrations\ShopwareAppRegistration`, `ShopwareAppController`, model `ShopwareAppShop`, public routes `GET /api/v1/integrations/shopware/app/registration` and `POST …/registration/confirm` (limiter `shopware-app` 60/min per IP). Opt-in `SHOPWARE_APP_ENABLED/NAME/SECRET`. The signature/proof/rotation algorithms match the official shopware/app-php-sdk source (downloaded and read on 2026-10-29); re-registration always requires the dual signature. Shopware connections accept `app_registration: true` (+ `store_url`, `sales_channel_id`; client credentials prohibited). The confirmed handshake's Admin API credentials are bound into exactly one such connection on the verified domain, whichever comes first (`ConnectionManager::create/replaceCredentials` call `attach()`), bumping the generation and resetting readiness. Lock order: connections, then the app shop row. Details: docs/integrations/REMAINING_BACKEND.md.

Migration: `2026_10_29_110000_create_shopware_app_shops` (new table, FK to webshop_connections nullOnDelete). Applied only by test suites and the isolated capture DB; **not to the main local `shopbonus` DB or production.** The B23k migration `2026_10_29_100000` is likewise not applied to the main DB.

Executed evidence for this commit's tree:
- ShopwareAppRegistrationTest: 7 tests, 60 assertions PASS. Integrations folder plus OpenApiCoverageTest PASS.
- Full SQLite: 881 tests, 858 passed + 23 skipped, 10505 assertions, 61.3s.
- Full MySQL 8: 881 tests, 879 passed + 2 skipped, 10585 assertions, 300.3s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures: docs/integrations/B23_SHOPWARE_APP_HTTP_CAPTURE.json, OpenAPI, README (proof re-derived with openssl and matched).
- CI for 9f0d7fa: run 40841263271 SUCCESS on all 3 jobs.

Exact next (remaining #31 Laravel gaps): (1) Shopware app webhooks: app-level endpoint, `shopware-shop-signature` with the current secret (previous within 60s of `secrets_rotated_at`), route by `source.shopId` to the bound connection into the existing WebhookEvent pipeline, `app.deleted`/`app.deactivated` lifecycle → revoke the binding and credentials; (2) Wix App Instance Installed/Removed webhooks; (3) remaining provider line/catalog/coupon/payment/refund mappings; (4) Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). The B23l handoff is below.

---
# Active handoff — #31 B23l Wix app-instance tokens, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `c478831` (B23k, [CI 40838253435](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40838253435) SUCCESS on all 3 jobs). Earlier `f23f3a9` CI 40836390758 also passed all 3. D-089 unchanged. Credentials senior-owned.

B23l: new `App\Integrations\WixAppTokens`; opt-in `WIX_APP_ENABLED/WIX_APP_ID/WIX_APP_SECRET` (`config('integrations.wix_app')`). With app mode on, Wix connect/replace prohibits `access_token` and stores `wix_app_id`. `WixConnector::read` gets its Authorization from `WixAppTokens::accessToken()`: a manual token when not app-bound, otherwise a token minted via `POST https://www.wixapis.com/oauth2/token` (client_credentials, 4h). It re-mints when less than 5 minutes are left, under a cache lock and transaction guard, stores the token encrypted with `access_token_expires_at`, and audits it. `WebshopConnection::hasSameCredentialAuthority` treats app-bound Wix renewals as the same authority (same app ID, instance, site, public key). Errors: `wix_app_changed` 409, `wix_app_not_configured` 503, `provider_response_invalid`/`provider_authentication_failed` with credentials untouched. No migration, no new endpoint; OpenAPI create/replace descriptions updated.

Executed evidence for this commit's tree:
- WixAppTokensTest: 5 tests, 22 assertions PASS. WixDiscoveryTest still passes.
- Full SQLite: 874 tests, 851 passed + 23 skipped, 10422 assertions, 64.0s.
- Full MySQL 8: 874 tests, 872 passed + 2 skipped, 10505 assertions, 307.5s.
- Pint --test PASS; PHPStan L6 0 errors.
- CI for 0274a77: run 40839539332 SUCCESS on all 3 jobs.

Exact next (remaining #31 Laravel gaps): Shopware 6 native app registration handshake/signatures/lifecycle (verify the current official app-system docs: registration GET with shopware-app-signature, confirmation POST, app-secret HMAC on webhooks and action buttons). Then Wix App Instance Installed/Removed webhooks; remaining provider line/catalog/coupon/payment/refund mappings; Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). The B23k handoff is below.

---
# Active handoff — #31 B23k Shopify privacy compliance intake, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `f23f3a96d4199ccf0a7aa75b62881d96d3645ea4` (B23i/B23j, [CI 40836390758](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40836390758) SUCCESS on all 3 jobs: SQLite, MySQL, lint/static). This commit adds B23k. D-089 is unchanged. Credentials are senior-owned.

B23k: public `POST /api/v1/integrations/shopify/compliance` (route `api.v1.integrations.shopify.compliance`, limiter `shopify-compliance` 300/min per IP) for `customers/data_request`, `customers/redact` and `shop/redact`. Order of checks: app client secret configured (else 503 `shopify_app_not_configured`), body ≤1 MiB (else 413), base64 HMAC-SHA256 of the raw body with `SHOPIFY_CLIENT_SECRET` (else 401 `webhook_rejected`/`signature_invalid`), topic is one of the three and webhook ID is present (else 401 `invalid_event_headers`). The request is stored once per `X-Shopify-Webhook-Id` in the new `shopify_compliance_requests` table (encrypted raw body, payload hash, event ID, topic, shop domain/ID when the shape is valid, `payload_valid`, status `pending`, `due_at` = received + 30 days). Redelivery returns 200 `duplicate:true` and increments `delivery_count`. An authentic body that doesn't fit its topic is kept with `payload_valid=false`. Audit events record receipt, resolution and rate-limited rejections, with no customer data. Operator command `shopbonus:shopify-compliance` lists pending requests (with the matching Shopify connection count and an overdue flag) and `--resolve=<ID> --outcome=completed|retained` records the outcome once.

**Nothing is erased or exported automatically.** See D-099 and OPEN-SHOPIFY-PRIVACY-EXECUTION. Legal/owner must decide which fields may be erased before this is automated, and someone must work the queue within 30 days. The senior must add the URI under `compliance_topics` in `shopify.app.toml`.

Migration: `2026_10_29_100000_create_shopify_compliance_requests` (additive, new table only). Applied by the test suites and the isolated capture DB only. **Not applied to the main local `shopbonus` DB or production.**

Executed evidence for this commit's tree:
- ShopifyComplianceTest: 7 tests, 46 assertions PASS. OpenApiCoverageTest PASS.
- Full SQLite: 869 tests, 846 passed + 23 skipped, 10401 assertions, 56.8s.
- Full MySQL 8: 869 tests, 867 passed + 2 skipped, 10483 assertions, 520.1s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures (php artisan serve, isolated SQLite, openssl-signed synthetic bodies): 200 for each of the three topics, duplicate 200, 401, 413, 503. Stored in docs/integrations/B23_COMPLIANCE_HTTP_CAPTURE.json, docs/api/openapi.yaml and docs/api/README.md. Rate-limit headers omitted (array cache). No live Shopify store or App Store review.
- CI for c478831: run 40838253435 SUCCESS on all 3 jobs.

Limitations: no dual-secret window during client-secret rotation (Shopify can take up to 1h to switch digests; its 8 retries over 4h should cover that). `X-Shopify-Shop-Domain` is not trusted; the signed body's `shop_domain` is used.

Exact next (remaining #31 Laravel gaps, in order): Wix app token install/renewal (verify the current official Wix OAuth/app-instance token contract first); Shopware native app registration handshake/signatures/lifecycle; remaining provider line/catalog/coupon/payment/refund mappings; Lightspeed C only once its current official contract is verified. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION. Previous B23i/B23j handoff is below.

---
# Active handoff — #31 B23i/B23j committed checkpoint, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`, base `8f76727b9ee9463963c89da9fd8f0b4bb6e97531`. This commit finishes the Codex WIP for B23i (Shopify webhook provisioning) and B23j (Wix durable order discovery); nothing was reset or rebuilt. No new migration, public route, package or live provider action. Entire #31 IN_PROGRESS. D-089 affiliate timing unchanged. Credentials senior-owned.

Review fixes made by Claude on top of the WIP:
- **Wix pagination bug (fixed):** the WIP sent filter+sort together with `cursorPaging.cursor` on page 2+. The official Search Orders doc states "`cursor` can not be used together with `filter` or `sort`" and its 2nd-request example sends only `cursorPaging`. Continuation pages now send only `{cursorPaging:{limit:25,cursor}}`; every returned row is still status/window/UUID validated. Test asserts both request shapes.
- Wix cursor maxLength is 16000 natively; the existing journal column is 2048 chars, so a longer cursor fails closed (`provider_pagination_invalid`) rather than truncating. The doc example cursor for a comparable filter is ~720 chars. Widening the column needs a migration and was not done.
- Command `shopbonus:provision-shopify-webhooks` now prints an error for an unknown connection; added tests for the command (invalid ULID/topic/review flags → exit 2, sync queue without --inline refused, inline create, async 7-job dispatch, disabled refusal) and for a review ID with no outstanding intent (`webhook_intent_review_invalid`, no mutation).
- Reviewed and kept: after-commit OAuth dispatch (audited failure never relabels the grant), durable create intent before mutation with list-first recovery, generation/authority/store rechecks under row lock before intent and observation, cache lock owner-safe release, dedicated committing OAuth fixture resets `RefreshDatabaseState` (verified under both drivers with RefreshDatabase tests running after it).

Executed evidence (this machine, exact working tree committed here):
- Focused SQLite: ShopifyWebhookSetupTest + WixDiscoveryTest 13 tests/56 assertions PASS; with ShopifyOAuthProvisionDispatchTest + BulkSynchronizationTest 20 tests PASS.
- Focused MySQL 8 (`shopbonus_testing`): same 4 files 20 tests/83 assertions PASS/44.0s.
- Full SQLite: 862 tests, 839 passed + 23 skipped, 10343 assertions, 61.1s.
- Full MySQL: 862 tests, 860 passed + 2 skipped, 10426 assertions, 301.9s.
- Pint --test PASS; PHPStan L6 0 errors. OpenAPI coverage test included in full suites (no endpoint change, no new HTTP captures needed).
- CI for f23f3a9: run 40836390758 SUCCESS on all 3 jobs (SQLite, MySQL, lint/static analysis).

Known limitations (documented in docs/integrations/REMAINING_BACKEND.md): Wix Search never returns INITIALIZED orders (webhook/Get Order only); Shopify provisioning not verified against a live store and does not inspect TOML-declared app subscriptions; `--review-intent` must only follow manual confirmation in Shopify. Historical reconciliation migration down-index failure observed under DatabaseMigrations teardown remains unfixed and unrelated; do not claim historical rollback works.

Exact next: Shopify mandatory privacy compliance webhooks (customers/data_request, customers/redact, shop/redact). These are app-level (single configured URI, HMAC with app client secret, shop/redact ~48h after uninstall when the connection may be disconnected), so they need a new public route + OpenAPI entry with real captured examples per AGENTS rule 11, and mapping onto existing PrivacyRequest/retention rules (D-013) without deleting accounting evidence. Then Wix app token install/renewal, Shopware native app lifecycle/signatures, remaining provider line/catalog/coupon/payment/refund mappings, Lightspeed C only with a verified current official contract.

---

# Active handoff — #31 B23e–h backend checkpoint, whole card IN_PROGRESS (2026-10-28)

Branch `main`, base `78642dedf9ccfd02b544f7460f85ab989b189577`. Application `74608718c15afd96a045c5ee2017a85556548381` and timestamp follow-up `f90860ff5f91d720285b3978f352812190f27a18` committed/pushed. [Final CI40778138872](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40778138872) SUCCESS all3jobs for exactf90860f: quality/audit, SQLite824pass+23skip/10278assertions/52.29s and MySQL845pass+2skip/10361assertions/218.01s (847tests/database). Prior7460871 CI40777263930 also all3SUCCESS/846each. This is a docs-only closeout; no uncommitted application/test changes. Timestamp follow-up rejects ambiguous PrestaShop DST times and leaves Wix payment/refund record-created timestamps null, preserving authoritative current status/amount without inventing settlement-time proof. No pre-existing user changes overwritten. Owner requests all main code and focused testing. **OAuth/bulk/Woo+Shopify line boundary implemented; remaining provider/lifecycle/catalog gaps are still PARTIAL, not whole-card Done.** D-089/D-098 apply.

Delivered disabled-by-default browser-bound Shopify OAuth/expiring offline pair renewal, immediate invalidation on changed app authority, same-grant generation continuity, and signed uninstall with native revocation proof outside locks (valid current grant survives old uninstall replay). Durable fixed-window page journal/leases/UUID-bound native recovery, separate reconciliation vs verification purpose and opt-in allowlisted automatic scans. Added authenticated native read boundaries for Wix, BigCommerce, Ecwid, PrestaShop9, Shopware6 and Magento2.4 self-hosted/PaaS through existing connectors/pinned transport/canonical ingestion. ID notifications never supply payment values. Exact JSON decimal lexemes and bounded encrypted immutable Woo/Shopify line snapshots; existing order list/show additive line_items field. No provider/financial policy/mail activation, package change, storefront/plugin implementation or production deploy.

Executed local full SQLite842total819pass+23skip/10247assertions/57.509s, then MySQL843total841pass+2skip/10344assertions/269.076s sequentially. One native binding test was added between driver runs; final config-rotation/uninstall changes additionally verified with latest MySQL integrations90tests609assertions/14.502s, SQLite lifecycle23tests139assertions/1.503s, contract3tests3720assertions/1.003s. Pint/PHPStanL6/composer validate/audit (0advisories)/diff PASS. No repeated local full suites per edit. Final exactf90860f CI40778138872 passed all3jobs/847tests each; no additional local broad rerun for this docs-only closeout.17 actual running-server captures: normal login/CSRF/file sessions, isolated SQLite, explicitly synthetic provider HTTP/DNS, successful auth/exchange/replay rejection and401/409/419/422/429/503, current catalogue/order snapshots and uninstall revocation. Generated secrets/state/code/signatures redacted. Common403/500 envelopes reuse prior real-kernel examples; provider-live/multi-process acceptance NOT RUN.

Migrations180000/190000/200000 applied successfully to local main MySQL. Isolated SQLite exact latest3 down/up PASS, canonical order retained, removed line evidence remains null on reapply; MySQL full suite exercises schema creation. No main/production rollback. Quiesce old workers/intake for rollout/rollback and preserve encrypted source history/keys. Main DB and private B21 fixture credentials preserved; approximately36GiB free. Task capture helper/server/temp DB/sessions and downloaded task source HTML removed; no shared Redis flush or unrelated cleanup.

Exact next: normal docs-closeout commit/push/check clean; then continue remaining **Laravel** gaps listed in docs/integrations/REMAINING_BACKEND.md: Shopify privacy callbacks/automatic hook registration, Wix token install/renewal/backfill, Shopware native app registration/signatures/lifecycle, full native catalog/coupon/detailed line/capture/refund mappings. Lightspeed C current official contract/plan/API verification is unresolved; do not invent it. OpenCart merchant extension and provider storefront/app activation are outside Laravel scope. Senior configures real credentials locally; no credentials needed in chat. Whole #31 cannot be marked Done or represented as backend-complete for Hassan yet.

---

# Verified Backend Progress

## Windows local installation — 2026-10-27

User requested installation in this checkout. Branch `HassanDev`, base `665bc1d`.
Composer installed 150 locked packages using Wamp PHP 8.4.15 with SOAP; platform check passed.
Created ignored `.env` and application key, new MySQL `shopbonus`, database cache/queue/session.
Initial migration failed because Wamp defaults to MyISAM (1,000-byte index limit).
Confirmed only the two newly created tables (`users`, `migrations`) existed with zero rows,
removed those task-owned empty tables, set the MySQL connection engine to InnoDB and retried.
All 46 migrations passed; 122/122 tables are InnoDB. No global DB settings changed.
Actual HTTP health/readiness/docs/catalog: 200; unauthenticated me: 401.
API/identity suite: 86 passed, 4,063 assertions, 23.181s, SQLite memory.
Composer platform requirements, empty database queue `--once`, launcher migrate status passed.
Added `start-local.ps1` and `LOCAL_SETUP.md`; server started on loopback port 8000.
Full regression/MySQL test suites, live providers and frontend integration NOT RUN.
Changes remain uncommitted; no push or seed performed.

## Project status as of 2026-10-25

Greenfield Laravel 13.38 / PHP 8.4 API in this repository (decision D-001). B01–B07 are implemented and tested: foundation, identity, workspaces/onboarding, connectors, orders, offers/vouchers, and Answer24 (eligibility, signed channel, handoff, claim, reminders; outbound dispatch off by default). B08–B15 are done (B10 adapters NOT VERIFIED live; Mollie stubbed; Moneybird not connected). B16a (admin 2FA, step-up code, support sessions) and B16b (cockpit, user administration) are done; B16c is committed/CI-green (`4786e65`); B16d is committed/tested (`7b2c8d1`), implementation CI passed; whole-card limitations remain. Statuses below come from committed code and commands actually run.

## B00 audit inventory (2026-10-23, Claude)

Audit method: checked this folder, every Laravel project under `/Users/kcoder/Projects` (`find -name artisan`), and the owner's GitHub account and org `JOPHAB-BASE-ORG` (`gh repo list`) for any Shopbonus code. Only the requirements pack existed: no `composer.json`, no git history, no migrations, no tests. `Projects/Answer24/answer24-backend` exists, but it is the separate Answer24 provider system and out of scope.

| Module | State | Evidence |
| --- | --- | --- |
| Laravel app/runtime | VERIFIED_WORKING | Laravel 13.38.0, PHP 8.4.17 locally; `composer.json` requires `php ^8.3`. CI matrix covers 8.3 and 8.4. |
| API foundation (versioning, errors, request ID, health, log redaction, CORS, rate limit) | VERIFIED_WORKING | B01a, commit `a51a9b6`. Tests in `tests/Feature/Api/*`, `tests/Feature/Logging/*`, `tests/Unit/Logging/*`. |
| Audit events / application error store | VERIFIED_WORKING | B01b. Tests in `tests/Feature/Audit/*`, `tests/Feature/Errors/*`. The PostgreSQL trigger variant is written but NOT RUN (no PG locally). |
| Auth (signup/login/OTP/recovery), Sanctum SPA + bearer | VERIFIED_WORKING | B02. Tests in `tests/Feature/Identity/*`. Real captures in `docs/api/README.md` → Auth. Staging p95 NOT RUN. |
| Workspaces, onboarding, agreements, admin review | VERIFIED_WORKING (B03a–d); staging p95 NOT RUN | `tests/Feature/Workspaces/*` |
| Provider adapters and webhooks | PARTIAL: framework + WooCommerce/Shopify/Shopbonus-v1 adapters VERIFIED_WORKING against fixtures (B04); live provider behaviour NOT VERIFIED; 7 platforms BLOCKED | `tests/Unit/Integrations/*`, `tests/Feature/Integrations/*` |
| Normalised orders, Order Sheet | VERIFIED_WORKING (B05) against signed fixture webhooks; live stores NOT VERIFIED | `tests/Feature/Orders/*` |
| Offers / voucher codes | VERIFIED_WORKING (B06a+B06b): versioned offers, fee preview, single code inventory, atomic reservation (real concurrency test), lifecycle, verified redemption; platform coupon verification BLOCKED | `tests/Feature/Offers/*` |
| Answer24 | VERIFIED_WORKING on the Shopbonus side (B07a+B07b): eligibility, grouping, cancellation, outbox, signed inbound channel, handoff → reserved code + single-use claim, consumer claim, reminder slots, dispatcher. Outbound dispatch disabled by default; real Answer24 NOT VERIFIED (no API) | `tests/Feature/Answer24/*` |
| Consumer profile/discovery | VERIFIED_WORKING (B08a discovery, B08b profile/email change/preferences/favourites/My Vouchers/dashboard/privacy requests); wallet in dashboard waits for B09 | `tests/Feature/Discovery/*`, `tests/Feature/Consumer/*` |
| Wallet ledgers | VERIFIED_WORKING (B09): central consumer wallet, append-only ledger, lots/expiry, reservations, pending, reversal policy, reconcile; merchant funding kind reserved for B12 | `tests/Feature/Wallet/*` |
| Affiliate (Daisycon/TradeTracker) | PARTIAL: framework, selection, clicks, idempotent processing, rewards, admin review VERIFIED_WORKING against fixtures (B10); live networks NOT VERIFIED; TradeTracker transport implemented/fixture-tested in B10b | `tests/Feature/Affiliate/*` |
| Mollie checkout, merchant funding, Moneybird | PARTIAL: checkout (B11) and merchant funding with 30% fee (B12) VERIFIED_WORKING against faked/stubbed Mollie; real Mollie sandbox NOT RUN; Moneybird not connected; live BLOCKED (OPEN-FINANCIAL-LEGAL) | `tests/Feature/Checkout/*` |
| Analytics | VERIFIED_WORKING (B13): merchant dashboard, lifecycle chart, overview, notifications, owner dashboard; banner metrics depend on B14 | `tests/Feature/Analytics/*` |
| Banners | VERIFIED_WORKING (B14) backend: campaigns, versions, rules, embed config/events, attribution, results, pairing; editor/embed frontend and AI not built | `tests/Feature/Banners/*` |
| CMS | VERIFIED_WORKING (B15): pages, legal versions, FAQ, cookie consent, NL/EN API messages | `tests/Feature/Cms/*` |
| Admin, AI support | Admin B16a–c committed/CI-green; B16d committed/tested (`7b2c8d1`), implementation CI green. Card #21 diagnostic adapter implemented/tested; live OpenAI not configured. AI support-chat B17 NOT BUILT. Whole Trello #21 still lacks AI/client/staging acceptance. | `tests/Feature/Admin/*`, `tests/Feature/Audit/*`; see current handoff and Frank-card audit. |
| CI | VERIFIED on GitHub (nauticsecure-dev/shopbonus_backend): PHP 8.4 × SQLite/MySQL + lint/static analysis | `.github/workflows/ci.yml` |
| Staging / deployment / backups | MISSING / UNKNOWN | No deployment config or target known. |

## Agent activity log

| Date | Agent | Task | Git SHA | Tests/results | Notes |
| --- | --- | --- | --- | --- | --- |
| 2026-10-25 | Codex | B16e #21 OpenAI diagnostics + private operational budget | `dea03b5` (implementation CI `40199851559` passed) | SQLite 469 pass + 11 skip (6841 assertions) / MySQL 478 pass + 2 skip (6880 assertions); Pint/PHPStan/audit clear; migration rollback/reapply pass; 8 guard mutants detected/restored; real HTTP captures incl. simulated provider result; quota race pass | Owner selected OpenAI and confirms no staging. Minimal allowlisted context, single attempt, idempotent queued run, strict output/evidence validation, append-only human review; no tools or business mutations. Live provider NOT VERIFIED (no key/model), staging NOT RUN. |
| 2026-10-25 | Codex | B16d + owner Frank-card audit | `7b2c8d1` (CI `40196742388` passed) | SQLite 459 pass + 10 skip; MySQL 467 pass + 2 skip; Pint/PHPStan pass; composer audit clear; 3 migrations apply/rollback/reapply + audit verify pass; 9 guard mutations detected/restored; 11 routes captured from running server | Incident workflow, 256-operation generated registry, audit chain/export, versioned safe public metadata. MySQL concurrent initialization deadlock fixed via exclusive no-op upsert. New downloaded Frank cards: no full card Done; detailed audit report added. |
| 2026-10-25 | Codex | B16c oversight and dual-admin corrections | `4786e65` (CI green) | SQLite 445 pass + 8 skip; MySQL 453 pass; Pint/PHPStan pass; composer audit clear; both new migrations rollback/reapply OK; 9 guard mutations detected/restored; real HTTP captures for 13 new routes | D-064 owner delegated risk policy: second admin for every adjustment; source-linked request, locked single ledger post; safe retries retain identities and recheck consent. Provider behaviour NOT VERIFIED. |
| 2026-10-23 | Requirements author | Requirements & handoff pack only | N/A | NOT RUN | |
| 2026-10-23 | Claude (Opus 5.5) | B00 audit | `7cc47a8` (pack commit) | N/A | Nothing to audit; greenfield chosen by owner |
| 2026-10-23 | Claude (Opus 5.5) | Scaffold | `48520b7` | 2 stock tests PASS | `laravel/laravel` 13 + `install:api` (Sanctum) |
| 2026-10-23 | Claude (Opus 5.5) | B01a API foundation | `a51a9b6` | `php artisan test`: 28 passed / 196 assertions on SQLite, same on local MySQL; `pint --test` PASS; `phpstan` (level 6) 0 errors; `composer audit` no advisories | Real HTTP captures in `docs/api/README.md` |
| 2026-10-23 | Claude (Opus 5.5) | B01b audit & error store | `088fb20` | `php artisan test`: 47 passed / 271 assertions on SQLite and on local MySQL; Pint PASS; PHPStan L6 0 errors; `migrate:rollback --step=2` + `migrate` on MySQL OK (trigger dropped and recreated) | Owner-requested `docs/CHECKLIST.md` added |
| 2026-10-23 | Claude (Opus 5.5) | B02 identity | `c95e1e4` | `php artisan test`: 107 passed / 690+ assertions on SQLite and on local MySQL; Pint PASS; PHPStan L6 0 errors; `composer audit` clean; `migrate:rollback --step=4` + `migrate` OK; full real HTTP flow captured against MySQL + Redis sessions | Fortify + Sanctum SPA |
| 2026-10-23 | Claude (Opus 5.5) | B03a workspace core | `2fe8db6` | 125 tests / 880 assertions pass on SQLite and MySQL; Pint, PHPStan L6, composer audit clean; real captures against MySQL + Redis | Tenant isolation, permissions, switcher |
| 2026-10-23 | Claude (Opus 5.5) | B03b team management | `ec20038` | 144 tests / 1130 assertions pass on SQLite and MySQL; Pint, PHPStan L6, composer audit clean; real captures against MySQL + Redis | Invitations, member management |
| 2026-10-23 | Claude (Opus 5.5) | B03c business profile & ownership | `b230455` | 199 tests / 1444 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; real captures (Google 200 and DNS/file success NOT live) | SSRF-safe verification |
| 2026-10-23 | Claude (Opus 5.5) | B03d agreements, review, activation | `5acdfbd` | 213 tests / 1772 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; real captures incl. session-based admin step-up and evidence round-trip | B03 complete |
| 2026-10-23 | Claude (Opus 5.5) | B04 connector framework | `820c6b5` | 255 tests / 2046 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; webhook signatures exercised with openssl-computed HMACs against the real server | No live provider tested |
| 2026-10-23 | Claude (Opus 5.5) | B05 orders & Order Sheet | `7e391be` | 270 tests / 2190 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; end-to-end capture via signed webhooks | Out-of-order safe ingestion |
| 2026-10-23 | Claude (Opus 5.5) | B06a offers | `c6485dc` | 279 tests / 2355 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; real captures | Versioned terms, 30% fee preview |
| 2026-10-23 | Claude (Opus 5.5) | B06b code inventory & vouchers | `c07f778` | SQLite 298 passed + 3 skipped; MySQL 301 passed (incl. forked-process race tests; mutation-tested); Pint, PHPStan L6, audit clean | One voucher system |
| 2026-10-23 | Claude (Opus 5.5) | B07a Answer24 eligibility | `5c24240` | MySQL 314 passed; SQLite 311 + 3 skipped; Pint, PHPStan L6, audit clean; end-to-end capture via signed webhooks | Dispatch off |
| 2026-10-23 | Claude (Opus 5.5) | B07b Answer24 channel, handoff, claim | `0c6c035` | MySQL 327 passed; SQLite 324 + 3 skipped; Pint, PHPStan L6, audit clean; `migrate:rollback --step=1` + `migrate` OK; real captures (signed requests computed with openssl; dispatcher run against a local Answer24 stand-in); 2 mutations caught | B07 complete; dispatch still off |
| 2026-10-23 | Claude (Opus 5.5) | B08a public discovery + `/docs` viewer | `4c4e26c` | MySQL 335 passed; SQLite 332 + 3 skipped; Pint, PHPStan L6, audit clean; `migrate:rollback --step=1` + `migrate` OK; real captures incl. multipart logo upload and binary logo; 2 mutations caught | |
| 2026-10-23 | Claude (Opus 5.5) | B08b consumer profile, preferences, benefits, privacy | `c2dfb15` | MySQL 347 passed; SQLite 344 + 3 skipped; Pint, PHPStan L6, audit clean; `migrate:rollback --step=1` + `migrate` OK; real captures (email change via logged mail code, session password throttle); 2 mutations caught; 1 bug found by capture and fixed | B08 complete |
| 2026-10-23 | Claude (Opus 5.5) | B09 wallet ledger | `862d3d0` | MySQL 363 passed (incl. forked-process wallet races); SQLite 358 + 5 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures; lock-removal mutation caught by the race test | |
| 2026-10-24 | Claude (Opus 5.5) | B10 affiliate tracking | `37edebf` | MySQL 374 passed; SQLite 369 + 5 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures (Daisycon via a local stub, TradeTracker via import; adapters NOT VERIFIED); 2 mutations caught | WIP checkpoint `93926ee` |
| 2026-10-24 | Claude (Opus 5.5) | B11a voucher checkout + Mollie | `5fca11a` | MySQL 386 passed (incl. forked checkout races, 5 repeat runs); SQLite 379 + 7 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures with a local Mollie test-mode stub; mutation caught (after fixing a test that could not fail) | WIP `b175c83`; deadlock found + fixed |
| 2026-10-24 | Claude (Opus 5.5) | B11b vouchers detail, redemption, disputes | `cd48d26` | MySQL 391 passed; SQLite 384 + 7 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures (Mollie refunds via the local stub); 2 mutations caught | WIP `3b58e93`; B11 complete |
| 2026-10-24 | Claude (Opus 5.5) | B12 merchant funding | `1edd8d9` | MySQL 396 passed; SQLite 389 + 7 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures (Mollie stub); mutation caught; first GitHub CI run: 8.4 jobs green, 8.3 failed → fixed (D-055) | WIP `f419888` |
| 2026-10-24 | Claude (Opus 5.5) | B13 merchant dashboard + analytics | `2bfa953` | SQLite 395 + 7 skipped; MySQL 402 passed (a first MySQL run hit error 3675 from a 99%-full disk, passed on re-run); Pint, PHPStan L6, audit clean; rollback OK; real captures; mutation caught | WIP `716993e` |
| 2026-10-24 | Claude (Opus 5.5) | B14 banner campaigns backend | `e5df771` | MySQL 410 passed; SQLite 403 + 7 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures (embed simulated with Origin headers); mutation caught; capture found `draft_revision` null on create (fixed) | WIP `641e86d` |
| 2026-10-24 | Claude (Opus 5.5) | B15a CMS pages, legal, FAQ | `4604215` | SQLite 410 + 7 skipped; MySQL CMS suite passed after a MySQL-only reorder bug was found and fixed; Pint, PHPStan L6, audit clean; rollback OK; real captures; mutation caught | WIP `ca6ef84` |
| 2026-10-24 | Claude (Opus 5.5) | B15b cookie consent + NL/EN API | `b2abb82` | MySQL 420 passed; SQLite 413 + 7 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures incl. Dutch validation; mutation caught | B15 complete |
| 2026-10-24 | Claude (Opus 5.5) | B16a admin 2FA, step-up code, support sessions | `4865e34` | MySQL 426 passed; SQLite 419 + 7 skipped; Pint, PHPStan L6, audit clean; rollback OK; real captures (secrets redacted); 9 mutations caught (one survived at first and the test was strengthened); found and fixed: unthrottled 2FA challenge, route-binding 404 before the admin check | WIP `962af29` |
| 2026-10-24 | Claude (Opus 5.5) | B16b cockpit, user administration, webshop admin | `6d782d8` | MySQL 437 passed (407 s, disk 100% full); SQLite 430 + 7 skipped; Pint, PHPStan L6, audit clean; real captures; 10 mutations caught (the affiliate status filter is redundant, an equivalent mutant); found: cockpit period cut off its last minute (fixed), a duplicate last-login listener (removed) | WIP `0d51699` |

### B01a detail

- **Delivered:** `routes/api.php` (v1 group + `throttle:api`); `app/Exceptions/ApiExceptionRenderer.php` (envelope for 400/401/403/404/405/409/415/419/422/429/500/503); `app/Exceptions/ApiException.php`; `app/Http/Middleware/AssignRequestId.php` (global), `ForceJsonResponse.php` (api group); `app/Services/Health/HealthChecker.php` + `Api/V1/HealthController.php`; `app/Logging/SensitiveDataProcessor.php` + `RedactSensitiveData.php` tap on all channels in `config/logging.php`; `config/cors.php` allowlist; `config/shopbonus.php`; `phpstan.neon`, `pint.json`, `.github/workflows/ci.yml`; `docs/api/openapi.yaml` + `docs/api/README.md`.
- **Negative/security tests:** no leakage of model names, policy messages, SQL or paths in 403/404/500; unsafe client request IDs replaced; readiness detail hidden without the token; 429 with `Retry-After`; log redaction verified through a real channel write.
- **Verified outside tests:** readiness against real local MySQL + Redis returned `{"database":"ok","cache":"ok","redis":"ok"}`. A 503 was captured with a missing database. Log lines carry `request_id` via Context.
- **Not done in B01a (moved to B01b/B03):** audit event store, failed-job alerting, the roles/permissions pattern (needs users/workspaces, so B03), workspace-resolution middleware (B03).

### B01b detail

- **Delivered:** migrations `2026_10_23_130000_create_audit_events_table` (with UPDATE-blocking trigger) and `2026_10_23_130100_create_application_errors_table`; models `AuditEvent` (refuses update/delete) and `ApplicationError`; enums `AuditOutcome`, `AuditCategory`, `ErrorSource`; `App\Services\Audit\AuditLogger` (`record` / `recordSafely`, reads `request_id`, `webshop_id`, `real_actor_user_id` from Context); `App\Http\Middleware\AuditAccessDenials` (api group, audits 401/403); `App\Services\Errors\ErrorRecorder` (singleton, re-entrancy guard, redaction, SQL template without bindings, fingerprint); report callback in `bootstrap/app.php` for HTTP faults; `App\Listeners\RecordFailedJob` (auto-discovered, `JobFailed`); alert hook event `App\Events\ApplicationErrorRecorded` (no listener yet, see B16). Added `HasApiTokens` to `User`.
- **Negative/security tests:** raw `UPDATE` rejected by the trigger on SQLite and MySQL; model update/delete refused; audit or error-store outage never changes the API response; query-string PII and IDs never stored (route template only); bound SQL values never stored; `ApiException`/422/404 not recorded as faults; IP stored only as a keyed hash.
- **Known gaps:** console-command failures are not yet recorded (only HTTP and queue). Faults thrown in global middleware before routing are logged but not stored. No retention/pruning yet (B18). No admin read API yet (B16).

### B02 detail

- **Delivered:** Fortify headless (`config/fortify.php`, `FortifyServiceProvider`): login only for active accounts with timing-equalised failures, per-endpoint rate limiters, a reset URL pointing to `FRONTEND_URL`. Actions `CreateNewUser` (atomic user, legal acceptances and consent; race-safe 422), `ResetUserPassword` (revokes tokens, deletes DB sessions), `UpdateUserPassword` (keeps this session, others fail Sanctum's password-hash check). Migrations `2026_10_23_1400xx_*` (users identity columns + public ULID, `email_verification_challenges`, `legal_acceptances`, `consent_records`). Services `EmailVerificationCodes`, `LegalConsents`, `DestinationResolver`. Middleware `EnsureAccountIsActive` (`account.active`, also on Fortify routes), `EnsureEmailIsVerified` (replaces `verified`), `ThrottleIdentityEndpoints` (+ Lockout event), `RejectAuthenticatedJsonRequests` (replaces `guest`). Endpoints `GET /api/v1/me`, `POST /api/v1/auth/email/verify-code`, `POST /api/v1/auth/email/resend-code`. Queued + encrypted NL/EN notifications `EmailVerificationCode`, `ResetPasswordLink`. Listeners `SendVerificationCodeOnRegistration`, `AuditIdentityEvents`. `AppendOnly` trait shared by audit/legal/consent models.
- **Card #4 E2E mapping:** 1 → `RegistrationTest::test_e2e1_*`; 2 → `test_e2e2_*`; 3 → B03 (needs memberships); 4 → `EmailVerificationCodeTest` (invalid/expired/superseded/attempts/replay/race/429/no-log); 5 → `SessionTest` (CSRF cookie, 419 with enforcement on, session rotation, cookie flags, logout → 401); 6 → `PasswordRecoveryTest`; 7 → `RegistrationTest::test_e2e7_*` + DB unique test; 8 → `RegistrationTest::test_e2e8_*`, `AccountStatusTest`; 9 → frontend-owned (UI, a11y, NL/EN screens); 10 → `OpenApiCoverageTest` (access registry, default deny), envelope tests, audit tests, `composer audit`, local timings below.
- **Session tests use a real cookie jar** (`tests/Concerns/BrowsesLikeSpa.php`). Laravel's test client otherwise neither sends cookies on JSON requests nor resets cached users, which made the first logout test pass for the wrong reason. The jar exposed a real bug: the current device was signed out after a password change, because the session hash was stored under the wrong key.
- **Local timings** (2026-10-23, `php artisan serve` single process, MySQL + Redis, mail queued): signup n=5 p50 251 ms / p95 256 ms (dominated by bcrypt cost 12); `GET /api/v1/me` n=100 p50 12 ms / p95 14 ms. Card targets (staging p95 <400 ms / <250 ms) are **NOT RUN** on staging.
- **Known gaps:** no MFA (B16); no email change flow (B08); no public bearer-token issuance; registration reveals existing emails (OPEN-SIGNUP-ENUMERATION); "deleted" accounts are not anonymised (D-013); validation messages are English only (NL in B15).

### B03a detail

- **Delivered:** migration `2026_10_23_150000_create_webshops_table` (`webshops`, `webshop_memberships` unique per user+webshop, `users.current_webshop_id`, `platform_role_assignments`); enums `WebshopStatus`, `MembershipRole`, `MembershipStatus`, `WorkspacePermission`, `PlatformRole`, `EcommercePlatform`; models `Webshop`, `WebshopMembership` (server-side effective permissions), `PlatformRoleAssignment`; `CurrentWorkspace` (request-scoped), middleware `ResolveWorkspace` (`workspace`) and `RequireWorkspacePermission` (`workspace.can:<perm>`); `WorkspaceService`; `WorkspaceController` (index/store/show/update/select); `/me` roles and memberships; `DestinationResolver` adds `workspace` and `admin`; console `shopbonus:grant-super-admin`; `WebshopFactory`.
- **Isolation evidence:** `WorkspaceTest::test_other_tenants_webshop_is_indistinguishable_from_a_missing_one_and_audited` (same 404 body for foreign/unknown/garbage IDs; GET/PATCH/select all blocked; 5 `workspace.access_denied` audits). Removed/suspended memberships lose access immediately; `current` re-validated on each use; permission denials audited with `webshop_id`.
- **Card mapping:** #2 acceptance "one owner creates five webshops" and "one user has different roles in different workspaces"; #3 F1 (create part), F13 (switching scope), F16–F17 (scoping, blocked + audited); #4 E2E 3 (`test_card4_e2e3_*`).
- **Registry:** `OpenApiCoverageTest` now also checks `x-permission` against `workspace.can:` middleware.

### B03b detail

- **Delivered:** migration `2026_10_23_160000_create_webshop_invitations_table`; `WebshopInvitation`, `InvitationStatus`; `TeamService` (invite/resend/cancel/findOpen/accept, updateMember/suspend/reactivate/remove, with all authority rules in one place); `InvitationController`, `MemberController`; `WorkspaceInvitationMail` (queued, encrypted, NL/EN, token in the URL fragment); routes under `workspaces/{workspace}` (members, invitations, membership) plus public `invitations/preview` and verified `invitations/accept`; limiters `invitation-create`, `invitation-preview`.
- **Card #16 mapping:** E2E 7 → `TeamTest::test_e2e7_*` (existing and new user accept; reused, expired, cancelled, unknown and mismatched tokens fail); E2E 8 → `test_e2e8_*` (owner never grantable, manager limits, no grants beyond own, other webshop's members and invitations give 404, denials audited); E2E 9 membership half → `test_e2e9_*` (removal and suspension block immediately; current selection cleared; final owner protected). Webshop suspension half → B03d.
- **Bugs found and fixed while building:** the recipient-mismatch audit was written inside a transaction and rolled back with it (now audited before the transaction); new invitations returned `resend_count: null` (seen only in the real capture).

### B03c detail

- **Delivered:** migration `2026_10_23_170000_create_onboarding_tables` (webshop ownership columns, `webshop_business_profiles` with encrypted contact fields, `webshop_verifications` with encrypted evidence); models `BusinessProfile`, `WebshopVerification`; `DomainName` normaliser; `Network\{HostResolver, SystemHostResolver, PublicAddressGuard, WebsiteFileFetcher, SafeWebsiteFileFetcher}`; `OwnershipVerification` (start/check/confirmEmailCode/submitManualReview); `BusinessProfileService`; `OnboardingProgress`; `Directory\{BusinessDirectory, GooglePlacesDirectory}`; `OwnershipVerificationCode` mail; controllers in `Api/V1/Onboarding`; `Support\Mask`; app-wide immutable dates.
- **Card #16 mapping:** E2E 1 → `OnboardingTest::test_e2e1_*` (manual entry, Google prefill with faked HTTP); E2E 2 → `test_e2e2_*` (DNS, file and email succeed; expired, superseded and replayed fail; attempt cap); E2E 5 → `test_e2e5_*` (derived progress, skipping refused, resume). Plus duplicate-claim, evidence-encryption and cross-tenant tests. SSRF: `tests/Unit/Onboarding/NetworkSafetyTest.php` (21 blocked address forms, domain normalisation) and `OnboardingTest::test_safe_fetcher_*` (no request to private IPs; redirects ignored; 1 KB cap).
- **Bugs found while building:** the race-loss failure was marked inside a transaction that then threw (now committed first); `firstOrNew` tripped the `webshop_id` mass-assignment guard; the Places adapter froze its API key at construction, so a rotated key would be ignored by cached controllers.
- **Not verified live:** Google Places responses (no key); DNS/file success (needs a controlled public domain).

### B03d detail

- **Delivered:** migration `2026_10_23_180000_create_agreement_tables` (`agreement_templates`, `webshop_agreement_acceptances`, `webshops.rejected_at`); models `AgreementTemplate` (immutable once published), `AgreementAcceptance` (append-only); `AgreementService` (server-selected version, escaped rendering, SHA-256, accept); `WebshopLifecycle` (auto-approval, approve/reject/suspend/unsuspend); middleware `EnsurePlatformRole` (`platform:`), `RequireRecentPassword` (`password.recent`), `EnsureWorkspaceCommerciallyActive` (`workspace.commercial`); `AgreementController`; `Admin\WebshopController`, `Admin\AgreementTemplateController`; `AdminWebshopResource`, `AgreementTemplateResource`; `OnboardingProgress` agreement step; registry `x-access: admin`.
- **Card #16 mapping:** E2E 3 → `AgreementAndReviewTest::test_e2e3_*`; E2E 4 → `test_e2e4_*` (escaped rendering, auto-activation, unticked/forged/outdated refused, re-acceptance); E2E 9 suspension half → `test_e2e9_*`; E2E 10 backend journey → `test_e2e10_backend_journey_*`; every admin route refuses non-admins (`test_every_admin_route_refuses_non_admins`).
- **Card #3 F18 / #2 "support sessions"**: done in B16a (support sessions with real-actor audit).

### B04 detail

- **Delivered:** migration `2026_10_23_190000_create_integration_tables` (`webshop_connections`, `webhook_events`); `config/integrations.php` capability matrix; `App\Integrations\{ConnectorRegistry, ConnectionManager}`; `Contracts\Connector`; `Connectors\{WebhookConnector, WooCommerceConnector, ShopifyConnector, GenericSignedWebhookConnector}`; `Data\{NormalizedOrder, PaymentStatus, VerifiedEvent}`; `Support\Money` (exact, never rounds); `WebhookController` (public intake), `ConnectionController`; `ProcessWebhookEvent` job; `OrderEventVerified` event (B05 hook); `shopbonus:prune-webhook-payloads` scheduled daily; docs: webhook format v1 spec and `docs/integrations/VERIFICATION.md`.
- **Card #1 mapping (backend, fixtures):** E2E 1 partial (WooCommerce verified order → connected; live Order Sheet is B05); E2E 2 "unsupported connections cannot activate" → `test_card1_e2e2_*`; E2E 3 Shopify redacted → limited → `test_card1_e2e3_*` (unit + feature); E2E 4 normalisation rules → `ConnectorTest::test_card1_e2e4_*`; E2E 5 duplicate deliveries processed once (`test_card1_e2e1_*`). Real-store parts of E2E 1–3 are NOT RUN.

### B05 detail

- **Delivered:** migration `2026_10_23_200000_create_order_tables` (`webshop_orders`, `order_events`); `WebshopOrder` (encrypted PII, email hash), `OrderEvent` (append-only); `Services\Orders\{OrderIngestion, PaymentTransitions}`; `IngestOrderEvents` listener (inside the processing transaction); `RefundEventVerified` + Shopify `refundTarget`; `NormalizedOrder::providerUpdatedAt`; `OrderController` (sheet, detail, CSV export); `OrderResource`; event retry endpoint; `OrderSheetUpdated` broadcast + `routes/channels.php`; `orders.export` permission; `shopbonus.business_timezone`.
- **Card mapping:** #1 E2E 4 → `test_card1_e2e4_*`; E2E 5 and #3 F5 → `test_card1_e2e5_and_card3_f5_*`; #3 F3 → `test_card3_f3_*`; out-of-order, non-forward and redaction rules → `test_out_of_order_*`, `test_without_timestamps_*`, `test_redacted_*`; isolation → `test_orders_are_never_visible_across_webshops`; export → `test_export_*`; retry → `test_failed_event_can_be_retried`; channel auth → `test_broadcast_channel_authorisation`.
- **Not done here:** Answer24 eligibility/contact (B07; columns exist with `not_evaluated`); connection health monitor and alerts (B16); staging performance (B18).

### B06 detail

- **Delivered:** migrations `2026_10_23_210000` (offers, versions) and `2026_10_23_220000` (voucher_code_batches, voucher_codes, vouchers, voucher_events); `OfferService`, `FeeCalculator`, `VoucherInventory` (reserve/issue/release/redeem/expire), `CodeImporter` + `CommitCodeBatch` job, `RedemptionMatcher` (hooked into order ingestion), `shopbonus:expire-vouchers` (hourly); `OfferController`, `VoucherCodeController`; `vouchers.export` permission; `NormalizedOrder::couponCodes`.
- **Card #7 mapping:** E2E 1/2 → `OfferTest`; E2E 3 → `OfferTest::test_e2e3_*` + `VoucherInventoryTest::test_e2e3_*`; E2E 4/9/10 → `CodeInventoryApiTest`; **E2E 5 → `ConcurrentReservationTest` (pcntl_fork, MySQL)**; E2E 6 → `VoucherInventoryTest::test_e2e6_*` (redemption via a real signed webhook carrying the coupon); E2E 8 partial (one reward per order via idempotency key; funding in B12); E2E 7 BLOCKED (no coupon APIs).
- **Bugs found while building:** bulk-inserted codes had upper-case ULIDs, so they were unaddressable via the API (fixed; also fixed the B02 migration backfill); the concurrency test leaked committed rows into later tests (now truncates afterwards); released reservations still counted as "assigned".

### B07 detail

- **Delivered (B07a):** see `5c24240`: eligibility with reasons, one-hour grouping, cancellation suppression, transactional outbox, merchant settings/suppressions/queue.
- **Delivered (B07b):** migration `2026_10_23_240000_create_answer24_channel_tables` (`integration_clients`, `integration_inbound_events`, `contact_opportunity_events`, `voucher_claims`, `voucher_claim_reminders`); `VerifyIntegrationSignature` (`integration.signed:<scope>`), `Support\SignatureV1`; `Services\Answer24\{ContactResults, ClaimLinks, Answer24Client, OutboxDispatcher}`; `Answer24Controller` (contact-results, handoffs, claim status, reminders); `Claims\ClaimController` (preview, accept); commands `shopbonus:create-integration-client`, `shopbonus:dispatch-outbox` (every minute; no-op while disabled); `shopbonus:expire-vouchers` now also closes claims; access level `integration` in `OpenApiCoverageTest`.
- **Card mapping:** #1 E2E 9 → `Answer24ChannelTest::test_card1_e2e9_*` and `test_callbacks_cannot_cross_tenants_*`; #10 §6 reminder test → `test_card10_reminders_*`, `test_suppressed_customer_gets_no_reminder`; #23 Answer24 free voucher (reserve at handoff, single use, 90-day expiry and release) → `test_card1_e2e9_*`, `test_unclaimed_entitlement_expires_*`, `test_out_of_stock_handoff_*`; signature/scope/replay → `test_signature_scope_and_replay_are_enforced`; dispatcher → `test_dispatcher_*`, `test_cancellation_during_dispatch_*`.
- **Not verified:** real Answer24 (no API; OPEN-ANSWER24-CONTRACT). Consumer account creation from a claim link uses the normal signup; linking the central points wallet is B09.

### B08 detail

- **Delivered (B08a):** migration `2026_10_23_250000_create_public_discovery_tables` (`webshop_public_profiles`, `webshop_public_categories`, `offers.slug` with backfill); `Services\Discovery\{PublicCatalog, PublicProfileService, DiscoveryCache}`; `PublicDiscoveryController` (`/api/v1/public/...`), `Workspaces\PublicProfileController`; cache invalidation hooks on `Webshop`, `Offer`, `OfferVersion`, `WebshopPublicProfile`; docs viewer `Docs\ApiDocsController` + `resources/views/docs/*`.
- **Card #18 mapping:** E2E 2 → `PublicDiscoveryTest::test_card18_e2e2_*`; E2E 3 → `test_card18_e2e3_*`; E2E 4 → `test_card18_e2e4_*` (sold-out/expired/paused; viewing reserves nothing); E2E 10 partial → cache invalidation, logo validation, docs. E2E 1 (homepage/CMS/cookies) is frontend + B15; E2E 5–9 are B08b/B09.
- **Delivered (B08b):** migration `2026_10_23_260000_create_consumer_profile_tables` (users phone/avatar, `email_change_requests`, `consumer_favorite_webshops`, `consumer_category_preferences`, `privacy_requests`); `Services\Consumer\{EmailChange, CommunicationPreferences, ConsumerFiles}`; `Consumer\{Profile, EmailChange, Preference, Benefits, Privacy}Controller`; notifications `EmailChangeCode`, `EmailChangeRequested`; consent purposes `marketing_inspiration`, `voucher_reminders`; password-change limiter `identity-change-password`.
- **Card #10 mapping:** E2E 1–6, 9, 10 → `ConsumerProfileTest::test_card10_*`; E2E 7 → B07b `Answer24ChannelTest::test_card10_reminders_*`; E2E 8 BLOCKED on B09. Card #18 E2E 9 → `test_card10_e2e10_and_card18_e2e9_*`.
- **Bug found by the real capture:** the completion notice to the old address was typed for `User` and crashed for the on-demand recipient after the email was already changed (500). Fixed; `test_email_change_mails_render_for_on_demand_recipients` renders both mails.

### B09 detail

- **Delivered:** migration `2026_10_23_270000_create_wallet_tables` (`wallets`, `wallet_ledger_entries` + append-only triggers, `wallet_credit_lots`, `wallet_reservations`, `wallet_pending_credits`, `wallet_reconciliation_flags`); enums `WalletUnit`, `WalletKind`, `LedgerEntryType`; `Services\Wallet\WalletService`; commands `shopbonus:wallet-expire` (every 5 min) and `shopbonus:wallet-reconcile` (nightly); `Consumer\WalletController`; dashboard wallet; test helper `Tests\Concerns\RacesProcesses`.
- **Card mapping:** #19 E2E 8 → `ConcurrentWalletTest` (MySQL, forked) + `WalletServiceTest`; #23 test 7 (simultaneous wallet-only purchases debit once) → `test_two_simultaneous_requests_with_the_same_key_debit_once`; #10 E2E 8 → `WalletApiTest`. #19 E2E 1–7 and 9 belong to B10/B16.

### B10 detail

- **Delivered:** migration `2026_10_23_280000_create_affiliate_tables`; `app/Affiliate/*` (contract, `DaisyconAdapter`, `TradeTrackerAdapter`, registry, DTOs); `Services\Affiliate\{ProgramSelector, AffiliateProcessor, AffiliateSync}`; `Affiliate\{Visit,Postback}Controller`, `Consumer\AffiliateActivityController`, `Admin\AffiliateController`; config `shopbonus.affiliate`.
- **Card mapping:** #19 E2E 2 → `test_card19_e2e2_*`; E2E 3 → `test_card19_e2e3_*`; E2E 4 → `test_card19_e2e4_*` (fixtures); E2E 5 → `test_card19_e2e5_*`; E2E 6 → `test_card19_e2e4_and_e2e6_*`; E2E 7 → `test_card19_e2e7_*`; E2E 9 → `test_card19_e2e9_*` + admin guard test; #23 test 6 → `test_card23_test6_*`. E2E 1 (live connect) and real provider data: BLOCKED.

### B11 detail

- **Delivered (B11a):** migration `2026_10_24_100000_create_checkout_tables`; `Services\Checkout\{MollieClient, CheckoutService}`; `Consumer\CheckoutController`; `Payments\MollieWebhookController`; `shopbonus:expire-checkouts`.
- **Card mapping:** #20 E2E 1 → `test_card20_e2e1_*`; E2E 2 → `test_card20_e2e2_*`; E2E 3 → `ConcurrentCheckoutTest` (MySQL, forked); E2E 4 → `test_card20_e2e4_*`; E2E 5 → `test_card20_e2e5_*`; E2E 6 → `test_card20_e2e6_*`; E2E 10 → `test_card20_e2e10_*` + `test_a_paid_payment_for_another_amount_*`; #23 tests 7/8 → `ConcurrentCheckoutTest` + `test_card20_e2e5_and_card23_test8_*`.
- **Delivered (B11b):** migration `2026_10_24_110000_create_voucher_dispute_tables`; `VoucherInventory::redeemByMerchant`; `Services\Disputes\DisputeService`; `Consumer\DisputeController`, `Workspaces\VoucherOperationsController`, `Admin\DisputeController`; My Vouchers masked list + audited detail. Card #20 E2E 7 → `VoucherDisputeTest::test_card20_e2e7_*`; E2E 8 → `test_card20_e2e8_*`; E2E 9 → `test_card20_e2e9_*` + `test_replacement_and_refund_*` + `test_paid_unfulfilled_*`.
- **Bug found:** concurrent checkouts deadlocked (insert's FK shared lock on the offer, then FOR UPDATE); fixed by locking the offer first.

### B12 detail

- **Delivered:** migration `2026_10_24_120000_create_funding_tables`; `Services\Funding\MerchantFunding` (hooked into `VoucherInventory` reserve/release/redeem/expire); `OfferService` activation check; `Workspaces\FundingController`; `shopbonus:prepare-invoices`; the Mollie webhook routes top-ups; test webshops are funded by `CreatesActiveWebshops`.
- **Card mapping:** #24 E2E 7 → `FundingTest::test_card24_e2e7_*` + `test_top_up_*` + `test_monthly_invoice_*` (Moneybird BLOCKED); #7 E2E 8 → `test_card7_e2e8_*`.

## How to update

For each changed module include: evidence (paths/test names), actual test command/result, commit SHA, resolved/unresolved issues, permissions impact, migration impact, and exact next action. Do not use percentages without an auditable denominator.

## B10b checkpoint — #19 main backend transport, 2026-10-25

Owner: “just work on the MAIN CODE; the senior dev will configure credentials.” Started clean `main` / `a9edfb3`. Official HTTPS WSDL retrieved without credentials and pinned (source/hash in `integrations/TRADETRACKER.md`). Implemented native SOAP serialization over Laravel HTTP, per-client cookie jar, read-only authentication, fixed TLS host, disabled-by-default transport, bounded paginated programs/conversions and dual registration/assessment-date queries. Fixed cashback enum truthiness, preserved exact decimal parsing, rejected negative commission, guarded against repeated pages and disable-during-fetch; atomic ingestion preserves watermark on failed pulls. Raw provider exceptions no longer enter shared sync errors/audits. Imports and wallet policy reused. No migrations/new routes/frontend/provider calls.

Focused affiliate suite: 23 passed, 149 assertions. Real local-server captures of create/list/test/sync/disable/disabled-sync use explicitly synthetic SOAP fixtures and real login/password/CSRF. Captures are not live-provider evidence. Full final checks running. Senior developer deployment/account verification instructions in `docs/integrations/TRADETRACKER.md`.

Validation note: concurrently executing SQLite/MySQL suites interfered with shared `Storage::fake` upload folders (PublicDiscovery logo replacement / AgreementAndReview evidence serving). No affiliate failures. Final suites are rerun sequentially; do not use the interrupted/interfered results as final acceptance.

### B10b executed final evidence

- Sequential full SQLite: **483 passed + 11 skipped**, 6939 assertions, 51s. Sequential full MySQL: **492 passed + 2 skipped**, 6978 assertions, 138s (494 total each). SQLite skips MySQL-only races; MySQL skips two existing destructive trigger-bypass simulations.
- Focused affiliate: **25 passed**, 193 assertions; 14 new native-SOAP transport cases. The strengthened exact-decimal/assessment polling test also passed separately (8 assertions).
- Pint --test, PHPStan L6 --memory-limit=1G, composer audit, composer validate, git diff --check: PASS. OpenAPI/registry/security coverage is included in full suites.
- Six actual local HTTP captures: create/list/test/sync/disable/disabled409, with actual authentication/password/CSRF and explicitly synthetic SOAP upstream. Capture server and task-owned temporary credentials/fixture DB/helpers removed. No shared Redis operations or live account calls.
- No migrations; apply/rollback N/A. D-070; ext-soap required and installed in CI; default-disabled deployment flag and senior-developer instructions in `integrations/TRADETRACKER.md`. Commit/push/CI next.

B10b closeout (2026-10-26): implementation `50957b4` pushed on main; GitHub CI `40202694616` **SUCCESS** (MySQL, SQLite, lint/static/audit). All code/config/API documentation implementation items are checked with that SHA. Genuine account verification remains assigned to the senior developer and unchecked. Final documentation checkpoint only; no further code changes.

## B14b work checkpoint — 2026-10-26

#25 remaining backend AI/experiments authorized. Base `main` / `5f0823d`; owner explicitly overrides ~5 GiB disk cutoff. Uncommitted AI request/quota/results/review migration, services/job/controller/models and 4 workspace routes. Single attempt, server-built minimal evidence, immutable hash/source snapshots, reviewed private drafts only, original content/terms/assets preserved. No live provider calls. Focused BannerAiTest 9 passed/77 assertions; Pint/PHPStan pass. Full QA/API captures/experiments pending; exact next action in HANDOFF.

### B14b implementation checkpoint — 2026-10-26

- Main code: `BannerAi{,Provider}`, queued `GenerateBannerProposals`, immutable request/source context and append-only result/review models, global quota; BannerExperiments with consented randomized/version-bound A/B/holdout and reused event/order ledgers. Model output has no free-form factual channel; server renders hypotheses from validated choices. Insights use up to1000 verified current paid/partial-refund EUR orders over90 days, minimum10; existing merchant reward1000 + fee300 =1300 cost is calculated server-side. Source draft/offer-version changes invalidate acceptance. Review creates a private draft, never publishes.
- Migrations `2026_10_26_100000` and `110000` applied, rolled back (step2) and reapplied on local MySQL successfully. Nullable assignment links preserve existing orders/events/clicks. No wallet/fee/legal/affiliate timing policy changed.
- Privacy/security: no PII/customer IDs/raw orders/URLs/source copy sent to OpenAI; immutable canonical context hash, fixed HTTPS/no redirects/no retry, one queued attempt, revoked account/permissions rechecked; support sessions denied. Global UTC quota and assignment concurrency tested in real MySQL processes. Consent withdrawal works after commercial suspension; revoked tokens cannot resurrect. Existing editor pairing/use now checks current active verified membership, with actual401 captures.
- Signed generic webhook full HTTP ingestion test refuses forged signatures, accepts the authenticated payment once and links the same-shop assignment. WooCommerce `_shopbonus_assignment_token` meta_data and Shopify `shopbonus_assignment_token` note_attributes preserve validated metadata; malformed values ignored, token never implies payment. Official provider metadata docs checked; real installed propagation remains unverified.
- Executed full local SQLite **500 passed +13 skipped**,7204 assertions,54s; MySQL **511 passed +2 skipped**,7255 assertions,124s (513 total), sequential. Final connector metadata/editor revocation refinements add3 tests and are validated by final targeted checks; exact final full suites belong to CI. Never run full SQLite/MySQL suites concurrently locally (shared fake upload directories).
-35 actual local HTTP captures: queued/completed designs and insights via actual Laravel worker with synthetic Responses fixture, evidence/costs/reviews/errors, launch/assignment/config/events/reports/withdrawal/stop, signed/forged order ingestion and revoked editor tokens; captured real429 Retry-After. Tokens redacted, API coverage passed. Server port8078 stopped; isolated `/tmp/shopbonus-b14b-capture` fixture DB/credentials/helpers removed. No production key/provider calls, Redis flush or binlog purge. Owner disk override D-073 applies only to this task.
- Configuration/frontend handoff: `docs/banners/AI_EXPERIMENTS.md`; D-071–073. No frontend/plugin/raster-image implementation, real account/model/storefront/staging checks NOT RUN. Code checkpoint commit/push/CI follows after final targeted checks.

Final targeted validation: SQLite Banner+Connector suite36 pass+2 skip/357 assertions; latest privacy refinement plus API coverage39 pass+2 skip/3422 assertions. MySQL Banner+Connector suite38 passed/364 assertions, including2 real-process races. Pint/PHPStan L6/composer audit and diff check passed. Full final GitHub CI follows the implementation commit.

B14b final implementation evidence: `d561f8d` pushed on main; GitHub CI `40281914885` **SUCCESS** across all3 jobs. Exact committed full suites: SQLite **503 passed+13 skipped**,7230 assertions/27.69s; MySQL **514 passed+2 skipped**,7276 assertions/81.78s (516 total each). Quality job Pint/PHPStan/composer audit passed. Implementation checklist ticked against this SHA; final documentation-only checkpoint follows. Whole #25 retains frontend/live provider/staging acceptance, not marked fully Done.

## B17a — #26 verified-support evidence foundation, 2026-10-26

User asks to choose the next task and implement it. Selected #26 https://trello.com/c/aWBrCkQK, first small tested boundary per AGENTS rule7. Main clean at b28fd7c; disk5.2GiB. Audited existing Consumer\WalletController::summary, Voucher, FeeCalculator, workspace permission/membership and published multilingual FaqItem. No duplicate business routes/tables or credential dependency planned. Expand checklist before code. Chat/provider/history/realtime/feedback/admin settings remain subsequent boundaries; no claim of full card completion. Tests NOT RUN at start.

B17a implementation: three internal support services plus23 feature cases. Scoped tool retrieval has no ledger/inventory writes or external calls; absent wallets remain absent. Verification rejects forged prose/values/dates/actions/IDs, incompatible scopes, hidden conflicting snapshots, changed/live-revoked/stale evidence and incomplete tool plans. Exact source FAQ version/locale, unknown percentage costs and issuance-not-redemption tested. No new public API or migrations; full chat/card acceptance remains pending. Focused SQLite23 passed/105 assertions, then23/106 with explicit Super Admin bypass negative. Full SQLite539 total:526 passed+13 skipped/7339 assertions/71.60s. Pint/PHPStan L6/composer audit passed. Full MySQL539 total:537 passed+2 skipped/7384 assertions/141.53s. Both full suites ran sequentially; no migration changes. Final explicit admin-negative assertion is additionally covered by focused reruns and exact committed CI. Owner approved continuing #26 below disk cutoff (D-075); no unrelated cleanup.

Final B17a focused MySQL23 passed/106 assertions/11.93s; final Pint/PHPStan L6/composer audit and git diff check passed. No new API operations/migrations/provider dependencies. Implementation checkpoint commit/push/CI next; B17b onward deliberately remains unchecked.

B17a implementation `9caa4fd` pushed on main; GitHub CI `40284066219` **SUCCESS** across all3 jobs. Exact committed SQLite526 passed+13 skipped/7336 assertions/29.11s, MySQL537 passed+2 skipped/7382 assertions/72.25s (539 total each); Pint/PHPStan/composer audit passed. First5 B17a checklist items ticked against this tested SHA; full chat/card criterion intentionally remains unchecked. Documentation closeout records B17b next and no credentials prerequisite.

## B17b–f continuation — complete #26 main backend, 2026-10-26

Owner explicitly asks to complete remaining support backend. Clean main at7c1657d. Read B17a handoff/tools and full#26; reuse source services, versioned settings/CMS and broadcasting auth. D-075 disk override remains; no unrelated cleanup. Official OpenAI function-calling docs fetched (Responses strict internally tagged function tools, tool_choice/parallel_tool_calls); no API-key helper available, keys remain senior configuration. Plan server research and immutable/read-only evidence selection, encrypted owned history, one targeted distinct read, queued bounded transport and human policy/review controls. Tests NOT RUN for new changes at start.

B17b–f implementation checkpoint before final CI: source expansions and readonly quote/status-correct campaign report;14 chat/admin operations; encrypted history and scoped idempotent one-shot queue engine; strict evidence function selector, one distinct targeted read, quotas/circuit; private Reverb, feedback/escalation/human review/reply and approved regressions; support.ai test/publish/rollback with canonical MySQL JSON checksums. Reverb package required -W for supported HTTP dependency ranges; Guzzle8.3→7.16.1, PSR7 3.2→2.13.2, Promises3.1→2.6, Carbon3.15.0→3.15.1, dotenv5.7→5.8; composer audit pending final rerun (install found none).

Checks: support SQLite153total151pass+2skip/1178assertions; prior MySQL151passed/1167assertions includes2real process races (before final3 focused refinements).100 distinct EN/NL submit/job cases pass expected intent and verified/appropriately clarified/escalated status. Migration120000 apply/rollback/reapply local MySQL passed.52 real HTTP captures include202→queue→GETverified, owned pending reward retry, quote1000/1000 split, cost1000+300=1300, campaign/integration source limits, feedback/review/reply/settings rollback,401/403/404/409/419/422/429/500; no fabricated examples. Isolated SQLite synthetic fixtures, real browser password+seeded OTP verified through real endpoint; no live email/model claims. New14 operations OpenAPI/API README coverage3pass/3261assertions.

Actual local Reverb wire test passed: real socket handshake/subscription and broadcaster publish; owner200/other403, Research→Verification→Verified only IDs/status. Sanitized artifact docs/support/REVERB_LOCAL_CAPTURE.json. Local processes8079/8089 stopped. Capture fixtures/credentials currently task-owned /tmp/shopbonus-b17-capture; remove before closeout after docs verification. No shared Redis commands or unrelated cleanup. Full SQLite669total654pass+15skip/8616assertions/68.00s, before final NL-only copy/test addition. Full MySQL running sequentially after SQLite. Final focused/quality results pending.

Final #26 gap review: added executed human-approved regressions (up to500) with corpus fingerprint invalidation and failing-case publication gate. Added immutable FAQ snapshots/expected-revision published rollback through existing FAQ mutation service; canonical checksums and real publication provenance, legacy approved_at unknown/null. New migration130000 apply/rollback/reapply local MySQL passed. Two extra admin operations bring total16;15 additional real HTTP captures bring total67. Real HTTP report101 cases/1 deliberate approved-case mismatch verifies no automatic production correction/training. Snapshot rollback409/currentrevision/OTP423 and knowledge approved_at captured. New endpoint/schema coverage is included in focused169 tests:167pass+2MySQL race skips/4649assertions.

Previous final full MySQL669total667pass+2skip/8668assertions/259.08s and final focused157allpassed/4451assertions predate these final knowledge/corpus changes. Final full suites rerun now, sequentially. Final translation/channel/pending/campaign guards have targeted passing checks; PHPStan passes after final changes. Earlier captures and final knowledge fixture DB/environments/credentials/harness/logs removed; no raw credentials in documentation, no lingering8079/8089 listeners, no unrelated cleanup or Redis/binlog commands. D-075 disk override persists.

Final complete local validation: SQLite672total657pass+15skip/8690assertions/60.13s; sequential MySQL672total670pass+2skip/8752assertions/149.91s. Both include immutable FAQ rollback and executed human-regression gates. Eight more real HTTP requests captured route-specific409busy/feedback/review/policy/expired-reply/oversized-source errors; total75 actual HTTP captures for16 new operations plus changed legacy APIs, no fabricated examples. OpenAPI coverage rechecked after adding these errors. Final provider projection now keeps FAQ questions/content/contact prose inside Laravel (only version/scope metadata egress), with a13-test provider suite39assertions PASS; final CI will execute the exact673-test commit including that added privacy case. All temporary capture dirs/credentials/databases/harnesses/logs removed; no8079/8089 listeners. Existing test result logs remain /tmp/shopbonus-b17-*.log. Final Pint/PHPStan/audit/commit/CI checkpoint follows; no more feature scope remains.

B17 implementation `d63f616` pushed; GitHub CI40387311496 **SUCCESS**, all3jobs. Exact committed673total: SQLite658pass+15skip/8706assertions/19.41s; MySQL671pass+2skip/8753assertions/77.80s. Quality passed. Final small follow-up binds a policy test to a stable before/after corpus fingerprint; a real query-listener interleaving inserts an approved case mid-read and proves rejection. Focused admin suite5pass/68assertions on both SQLite and MySQL; Pint/PHPStan/audit/diff PASS. Follow-up commit/push/CI then final SHA closeout.

B17 final code `7616e88` pushed; GitHub CI `40388179684` **SUCCESS** all3jobs. Exact673total/database: SQLite658passed+15skipped/8704assertions/31.67s; MySQL671passed+2skipped/8755assertions/94.99s. Quality Pint/PHPStan/composer audit passed. Stable corpus before/after guard and real mid-read approved-case insertion pass on each DB; no stale test certification. Main #26 backend is DONE and ready for Hassan integration. Checklist main-code aggregate/B17b–f ticked against tested SHAs. Final docs-only closeout records setup/source limits and remaining live/frontend/deployment acceptance; no credentials asked or production success claimed.


## 2026-10-26 — B20a / Trello #29 central inbox foundation

Card https://trello.com/c/WcPlsK8w. Base main/d2fb5ed, clean start; no pre-existing edits overwritten. Owner explicitly authorizes below-cutoff disk continuation for #29 (D-081), no unrelated cleanup or provider credentials. Full downloaded card preserved in requirements; granular B20a–f checklist expanded before coding. Existing merchant notification store uses shared workspace read state and cannot substitute for per-recipient central history; compatibility bridge remains explicit B20b.

Implemented standard Laravel notifications table/custom encrypted model and shared User relationship, per-user preference storage/account locale reuse, NotificationInbox/Preferences services,5 verified owner APIs, support-token denial and private Reverb subscription. SupportReviews atomically creates generic NL/EN support_reply/workspace_support_reply notifications; no transcript/code/financial data escapes. Stable source-reply dedupe and per-user row lock; current membership/shop/admin role filters all list/count/read-all operations. Read timestamps immutable on replay; optional email switches do not override mandatory in-app or grant consent. Email explicitly unavailable/not_implemented. Minimal queued after-commit invalidations; queue failures reported without losing committed reply/read state.

Tests in tests/Feature/Notifications cover ownership/admin no bypass, revoked membership/roles, suspended shops/accounts, validation, privacy/encryption, locale/consent, CSRF/throttle/500, source rollback/dedupe, real after-commit callbacks, queue outage and4-worker MySQL unique publication. Full SQLite688total672pass+16skip/8873assertions/71.284s; final MySQL running. Earlier focused MySQL11pass/98assertions and SQLite10pass+1skip/96assertions predate4 new negative cases. Pint/PHPStan/composer audit pass; OpenAPI3pass/3358assertions. New migration140000 apply/rollback/reapply PASS local MySQL.24 real HTTP captures in README/OpenAPI plus sanitized JSON, owner200/other403/support403 private subscription auth;500 labelled temporary router failure. No real email/model/deployed Reverb/customer sends. Task capture environment/credentials/databases/router logs removed on completion. Final commit/CI evidence to follow; whole #29 remains PARTIAL, B20b–f not implemented.

Initial commit-callback tests used DatabaseMigrations cleanup, which exposed an existing SQLite down-migration error in2026_10_25_100000 (dropping a uniquely indexed public_id). The new tests now explicitly migrate:fresh to exercise real commits and reset RefreshDatabaseState afterward; no unrelated historical migration changed. Final full SQLite passes with this harness. New140000 rollback was independently executed on MySQL.

B20a final local boundary PASS: SQLite688total672pass+16skip/8873assertions/71.284s; sequential MySQL688total686pass+2skip/8927assertions/273.128s. All15 new tests included,4-worker publication race passed, full app compatibility green. Full Pint, PHPStan L6, composer audit, API coverage and diff check passed. B20a checklist implementation/tests ticked in the implementation commit only after executed results. Push/CI/SHA closeout pending; whole #29 remains PARTIAL. Lowest observed disk~430MiB then688MiB at completion; no ENOSPC, cleanup of unrelated data, Redis flush or binlog purge.

B20a `5c1d8cc` pushed to main. CI `40399876856` SUCCESS all3jobs: SQLite688total672passed+16skipped/8877assertions/39.76s; MySQL688total686passed+2skipped/8927assertions/126.47s; Pint/PHPStan/composer audit passed. Same code tested locally as recorded above. Docs-only closeout updates exact SHA/checklist and handoff. Real channel authorization was HTTP-tested; dispatch-failure recovery test injects a throwing event listener at the dispatch boundary, not a deployed queue failure; actual deployed queue/Reverb/email outage verification remains NOT RUN. No task capture fixtures/credentials/router/server remain; generic task verification logs remain /tmp/shopbonus-b20-*. Lowest subsequently observed free disk~168MiB with no ENOSPC; D-081 persists for #29, no unrelated cleanup. Next B20b explicitly unfinished.


## 2026-10-26 — B20b–f #29 continuation, verification in progress

Owner “do it” authorizes remaining main backend; base deea998 clean, D-081 disk exception persists. Added detailed checklist scopes before source work and before template/remaining acceptance. Durable encrypted NotificationEvent intents keep recipient fanout/network outside wallet/audit transactions. Source hooks cover posted affiliate credits/corrections, voucher lifecycle, workspace decisions/review, offer/favorite events, global reward-rule changes, connection/affiliate errors and all actual SupportReview creations. Legacy merchant rows are compatibility sources into central personal history; legacy endpoints retain shared-read semantics and mark only acting user's personal projection.

Added same-transaction NotificationDelivery records, claimed single-worker mail with stable message IDs and actual HTML/plain-text rendering; optional consent/preferences rechecked, mandatory mail cannot be disabled by a user. Pre-send render failures retry3times; ambiguous transport/stale worker outcomes require reviewed retry/resolve, never an automatic duplicate-risk resend. Scheduler recovers outbox and pending jobs,30/7/1 voucher reminders, old undelivered mail, retention with unresolved claims preserved and recipient-redacted event dedupe tombstones. Owned deletion uses409 for unresolved/mandatory active delivery.

Reused immutable PlatformSettings for private policy and46 event/locale template keys. Strict text/placeholders/limits/no literal amounts/deadlines/URLs/provider names; synthetic render-test report checksum gates independent publication and rollback drafts. NL published fallback metered. OpenAI generates only private NL/EN drafts with strict Responses schema, fixedhost/no redirects/retry, storefalse, no recipient/source data, per-admin UUID idempotency and serialized global daily cap. Senior-owned model/key/config; no live calls. Official structured-output documentation fetched via OpenAI Docs; no key setup skill/tool available.

Focused37tests34pass+3MySQL-only skips/248assertions before final support-review/global-rule hook refinements. Earlier failure found missing mail observer registration (fixed; actual multipart mail/replay/uncertain paths now pass); one stale test model was refreshed before resolution after retry. Three new migrations150000/160000/170000 applied/rolled back/reapplied PASS local MySQL. Initial170000 fallback index exceeded64chars; shortened name, removed only the2 empty new task tables from the failed attempt, then completed checks.74 actual HTTP captures in notifications/B29_HTTP_CAPTURE.json include true login/password/seededOTP, full endpoint success and guards, actual database queue worker with array mail, labelled temporary synthetic OpenAI/storage faults. No actual external messages/model/deployed WebSocket claim. All capture fixtures/keys/cookies/router/logs deleted; only helper and generic task logs remain. API docs/full suites/CI still pending, all B20b–f boxes remain unchecked.


#29 final local implementation checkpoint (before push/CI): full SQLite718total700pass+18skip/9262assertions/75.523s. One final realistic outbox failure/exhaustion/admin-retry regression was then added and passed in9 source tests/45assertions; current code has719tests expected in CI. Final API/incident-diagnostics contract suite13pass/3669assertions, all104 HTTP captures reflected in README/OpenAPI. Includes actual database queue worker acceptance via array mail after reviewed synthetic uncertain source (sent,attempts2; prior attempt is explicitly synthetic), not customer inbox delivery. Full Pint, PHPStan L6, composer audit and diff check PASS.

Final3-migration MySQL rollback/reapply PASS after ensuring only empty task tables and preparing own new visibility column from the earlier local WIP schema. SQLite down/up regression also PASS. Initial128MiB full-suite memory failure fixed by512MiB test-only ini; subsequent full pass above. YAML regeneration's empty-map artifact caused diagnostics/API failures, was fixed and checked through actual registry-dependent tests. No guessed passes. Full local MySQL/races NOT RUN with~300MiB available; CI exact code must finish before final backend Done. Source/budget/mail worker races are included for actual MySQL CI execution.

Final guards: actual automatic SupportReview events, commission deactivation, canonical deduplicated integration-error merchant source; read-before-projection receipts; lock delivery rows before deletion/pruning; email-only promotions hidden from unread inbox, current favorite/public availability/consent rechecked; exact reminder stage/expiry at mail, configurable reviewed windows and fail-closed policy checksum. D-083–085. No unsupported financial cancellation path/economic policy/provider behavior invented. User-facing whole-card/frontend/deployed provider/staging acceptance remains senior/Hassan-owned. Temporary servers8079/8089 stopped; all fixture DBs/credentials/cookies/router/logs removed. Helpers/generic verification logs in /tmp remain until final closeout.


B20 implementation `911e053` pushed. CI `40412989638` **SUCCESS all3jobs**, exact719tests/database: SQLite701passed+18skipped/9278assertions/40.77s; MySQL717passed+2skipped/9340assertions/144.63s; full Pint/PHPStan/composer audit green. MySQL explicitly executed and passed4-process reply publication,2-process global generation quota and4-process mail claim races. New migrations down/up also pass both CI databases; local exact MySQL migrations as recorded earlier.

Final acceptance strengthening adds4 assertions to the existing actual password/OTP workspace approval E2E: owner receives workspace_approved with correct public workspace ref; admin does not; review-required is admin-only. Complete AgreementAndReviewTest14passed/375assertions locally; this follow-up changes tests/docs only, application code stays911e053. All ten card backend acceptance groups mapped in notification handoff. Final B20b–f/CI checkboxes ticked only after executed local/CI evidence. Main #29 backend DONE and ready for Hassan frontend integration; no live model/mail/customer inbox/deployed WebSocket/staging proof invented. All capture/helper scripts and fixture directories/credentials/router/cookies removed; no8079/8089 listeners. Generic verification/CI logs remain /tmp/shopbonus-b29-*. Closing normal commit/push plus final CI check follows.

Final #29 follow-up5a8c36d pushed; CI40414186822 SUCCESS all3jobs.719tests/database: SQLite701pass+18skip/9281assertions/43.05s; MySQL717pass+2skip/9341assertions/101.94s. Actual stepped-up approval→owner workspace reference/admin-only review assertions pass on both databases, quality green. No application-code change from911e053. Task main backend DONE, all B20 scopes/checklist complete against executed evidence. Final docs-only closeout updates handoff; no further main-code action. Senior/Hassan live/frontend acceptance remains explicitly separate.


## B21 integration corrections — 2026-10-27, implementation checkpoint

Owner supplied `~/Downloads/BACKEND_API_ISSUES.md` and explicitly approved opt-in local fixtures. Code audit confirmed no funding request key, no public config-fallback legal discovery and no consumer offer-slug promotional claim. Empty catalog/CMS data and log-only mail are setup conditions; XLSX415 remains supported-contract behavior. Real legal publication/provider activation are not authorized by document recommendations.

Implemented durable optional funding UUID identity (workspace+actor scope, immutable amount, distinct provider UUID, at most one POST, honest creating/created/uncertain state); public LegalConsents metadata with only matching published links; promotional-only one-consumer/offer inventory claim without secret response; guarded local setup command for labelled sample content, verified accounts/private generated credentials and canonical audited merchant onboarding. Default seeding creates no privileged or broken stub identity. D-086 records funding compatibility/provider-window decision.

Executed focused SQLite:24tests,22passed+2MySQL-only skips,354assertions. Earlier focused MySQL:16passed,133assertions, including two actual6-process races (one provider call/one voucher allocation). Subsequent audit/preservation/scope tests require the final full runs. Real Laravel HTTP using isolated SQLite/file sessions/array mail and a local synthetic upstream verified signup201/login200, legal/catalog/FAQ200, claim/replay201, top-up replay201/conflict409/invalid422/uncertain502+stable201replay. Capture artifacts contain no credentials or codes. Full quality/suites, migration reversibility, local provisioning and commit/CI pending; B21 remains IN_PROGRESS.


### B21 verified implementation checkpoint (this commit)

**Main backend VERIFIED_WORKING; remote CI pending.** Final local suites: SQLite734total,714passed+20database-specific skips,9418assertions/45.330s; MySQL734total,732passed+2SQLite-only skips,9483assertions/224.681s. MySQL includes six-process same-key funding (one provider POST) and promotional claim (one stock allocation) races. The final additional legal test binds translated URLs to the exact canonical page, refusing unrelated public text even when its version label matches a newer private canonical page. Final API coverage after small response-reference documentation additions:3passed/3597assertions. Pint whole-repository, PHPStan L6, composer audit (no advisories), OpenAPI response-reference validation and git diff checks pass.

Migration applied/rolled back/reapplied on isolated SQLite. First MySQL rollback exposed InnoDB replacing its implicit foreign-key index with the new composite index; down now conditionally restores the original named supporting index. Two consecutive MySQL rollback/reapply cycles passed after this fix. New migration applied successfully to local `shopbonus`. No wallets are credited by migration or fixtures.

24 real running-server captures in docs/integration/B21_HTTP_CAPTURE.json (isolated SQLite, file cache/sessions, array mail, synthetic local upstream): public NL/EN legal200/invalid422; catalog/pages/FAQ200; claim201+same replay/401/403/404/409/419/429; funding201+same replay/409/422/502+stable uncertain replay/GET200; actual browser login200/signup201. Two provider create attempts correspond to two distinct intents; no real provider requests or inbox delivery occurred. All capture temporary DBs/credentials/server processes were removed.

Owner-approved local setup actually executed on local `shopbonus`:4verified sample users (consumer/merchant/2admins), labelled NL/EN legal/CMS/FAQ and approved merchant public profile/promotional offer+10codes. Private generated credentials file is ignored by git and0600; dedicated directory0700. Second execution succeeded preserving existing state. Actual local metadata check confirms registration versions and matching public documents available. Admin MFA remains enforced; no live integrations/outbound mail enabled, no synthetic balances/paid orders. Existing real content is not overwritten. Documentation: docs/integration/LOCAL_INTEGRATION.md; D-086/087. Senior still owns real legal approval, email/provider/model configuration and actual live/staging acceptance; these are not claimed passed.


### B21 clean-environment CI correction

Implementation `7991841` pushed. First CI `40558908494`: lint/static analysis SUCCESS; SQLite found one test fixture dependency (LegalDiscoveryTest's current-version case relied on local LEGAL_PRIVACY_VERSION). Reproduced locally with both legal env versions empty:2pass+1error, exactly the correct fail-closed application behavior. The test now explicitly configures its separate Privacy fallback while asserting the CMS Terms version. No application/config/provider changes. Focused clean-environment regression after fix:3pass/21assertions on SQLite and3pass/21assertions on MySQL; Pint/diff checks PASS. Follow-up commit/rerun pending; do not mark remote verification done yet. Local fixtures and full application results above remain unchanged.


### B21 final verified closeout — 2026-10-27

**DONE, main backend.** Application commit `7991841c32307fa96b5011c3ac719a1f13fb6368` plus test-only clean-environment follow-up `a7ebb0e9c1ecd286540f9ab1c6057c45f7df2a95`, both pushed to main. [CI40559448895](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40559448895) **SUCCESS in all three jobs** for exact follow-up SHA: quality (Pint/PHPStan/composer audit); SQLite 714 passed + 20 database-specific skips, 9417 assertions / 44.00s; MySQL 732 passed + 2 SQLite-only skips, 9484 assertions / 115.88s. 734 total tests per database. Both real six-process races passed. Earlier CI40558908494 failed the same environment-dependent legal test on both databases; the explicit Privacy test fixture correction is verified, not a production guard bypass.

All B21 checklist implementation items now name the verified SHAs; final CI box ticked with executed evidence. Main local migration/setup and preservation rerun succeeded; generated credentials remain private/gitignored.24 sanitized real HTTP captures and the integration guide are committed. Temporary capture/document harnesses, synthetic upstream, HTTP server and ephemeral databases/credentials removed; generic `/tmp/shopbonus-b21-*` result logs retained. This closeout changes tracking docs only. No B21 main backend work remains. Hassan integrates the new contracts; senior developer supplies approved real legal content and actual mail/provider/model/deployment configuration. No real financial/inbox/staging success is claimed.


## B22 / #30 start — 2026-10-27

Owner authorizes #30. Clean main at587a555; approximately48GiB free. Imported full card from owner export into requirements snapshot. Existing wallet reconciliation/flags, immutable audit/incident stores, readiness/dependency checks, failed-job recorder, scheduler and permissions are reused. No Pulse/Horizon/Telescope dependency is currently installed; no extra package or duplicate auth/incident infrastructure planned. First tested boundary B22a adds privacy-safe observed HTTP/query metrics and threshold incidents; later card boundaries remain unchecked. Card's immediate affiliate-credit wording conflicts with existing owner-open timing policy, so this slice changes no financial behavior. No staging/production hosting access exists; local evidence will be labelled.


### B22a verified local monitoring checkpoint — 2026-10-27

Implementation is this commit on main (base587a555); #30 remains IN_PROGRESS. Metadata-only scoped HTTP termination observations, DB timing counters, bounded exact SQL percentile report, opt-in config, retention scheduler and grouped threshold incidents with immutable evidence/cooldown are implemented. No extra package/auth infrastructure; no financial policy/ledger change. New GET /api/v1/admin/monitoring and additive incident occurrence monitoring_evidence use existing Super Admin/MFA/support restrictions. Data absence/partial samples are UNKNOWN; rendered408/504 are explicitly not an external availability monitor. Default collection stays false.

Executed final full suites sequentially: SQLite743total,722passed+21database-specific skips,9480assertions/47.411s; MySQL743total,741passed+2SQLite-only skips,9551assertions/226.179s. Includes four-process real alert-cooldown race (one incident/occurrence), exact percentile/error/timeout tests, privacy/counter reset/storage-failure/retention/truncation/admin/MFA tests and support impersonation regression. Final API coverage3pass/3609assertions. Whole-repository Pint, PHPStan L6, composer audit(no advisories) and diff checks PASS.

Ten real local HTTP captures (isolated SQLite/file cache+sessions/array mail; explicitly synthetic10000-row metric dataset; admin MFA disabled only for the capture):401/403/422/429/500, UNKNOWN/observed reports, immutable incident evidence and original health200 even when observation storage fails. SQLite report benchmark ten runs~44–53ms; actual index plan recorded. MySQL identical synthetic10000-row rolled-back workload ten report runs before/after covering-index bound count/shared ordered percentile windows: median~704ms before/~261ms after. Host/load variance applies; this is not production acceptance. Artifacts docs/reliability/{B22_HTTP_CAPTURE.json,B22_MYSQL_BENCHMARK.json}; operational/privacy/cost/remaining work guide RELIABILITY.md.

Migration2026_10_27_110000_create_api_observations applied/rolled back/reapplied on isolated SQLite and local MySQL testing; applied successfully to local shopbonus. It creates only operational metadata and nullable immutable incident evidence, never financial entries. Local monitoring flag not enabled; no real provider/mail or customer requests generated. #30 full source imported from owner export into requirements/TRELLO_CARDS.md; D-088 defines this boundary. Next: commit/push/check exact-code CI, then source-backed historical financial reconciliation. Production/offsite/backup/restore/RPO/RTO and full ten-card scenarios remain unverified, not implied by this slice.


### B22a exact-code CI closeout; B22b starts

Monitoring implementation c7e878905e6ab7ff80bff27b2b4bdbb3f3692ebe pushed to main. CI40580008502 SUCCESS across quality, SQLite722pass+21skip/9481assertions/30.19s and MySQL741pass+2skip/9550assertions/119.90s (743total each). The actual four-process alert race passed. B22a DONE as its documented backend boundary; whole #30 stays IN_PROGRESS. Capture/document/benchmark harnesses removed; only result logs remain, no servers/ephemeral private databases.

B22b checklist expanded before implementation. Source audit finds WalletService::reconcile currently creates a new balance_mismatch flag for every unchanged discrepancy and does not lock the wallet while checking its cached/ledger/lot/reservation state. Affiliate reward rules have version references; merchant sponsorship stores actual reward/fee/total but lacks the original fee-rate field. Points cannot be equated to EUR to manufacture revenue. Reconciliation will preserve separate source semantics and missing legacy evidence explicitly. Owner asked via async question to resolve affiliate timing conflict as AGENTS§6 requires before activation; existing credit behavior stays unchanged while work continues independently. No credentials requested.


### B22b prerequisite hardening — implementation checkpoint

Owner explicitly confirms after-network-approval for new affiliate rewards; D-089 resolves that choice and preserves the existing default. Local configured timing was checked as on_network_approval; no live balances or provider settings changed. New nullable migration120000 retains reward timing/rule/credit evidence and sponsorship original fee rate without legacy backfill. Rule terms, reward evidence and sponsorship economics are immutable at the application boundary; deactivation and lifecycle statuses remain supported. Wallet checks now lock before reading, cap the scan by initial max ID, preserve one open flag and retain original finding evidence. Four-worker MySQL duplicate-flag test passed.

Two high-severity control gaps were reproduced with initially failing regressions: increased approved commissions bypassed the original review threshold, and foreign currency could enter the EUR reward path without an FX policy. Re-check threshold before credit, retain original terms even during review, and force unsupported currency to dispute/no credit. Existing authenticated acceptance can release a reviewed amount, but unsupported currency acceptance now409 even for Super Admin. docs/reliability/FINANCIAL_FINDINGS.md records severity/evidence/fixes/tests. No claim of compromise.

Eight real isolated HTTP captures plus canonical synthetic source evidence in B22_FINANCIAL_EVIDENCE.json: old/new rates700/900, pending700/nonspendable before approval; independent injected400 cache discrepancy keeps one flag/one unchanged ledger row; actual login200/password-confirm201, foreign acceptance409 and authorized increased-commission acceptance200. No real network/cash/revenue posting; main MFA unchanged. Nullable-field migration apply/rollback/reapply PASS on isolated SQLite and MySQL testing, applied to shopbonus. Snapshot rollback drops evidence and requires preservation before production rollback. Full final suites now running after the control fixes; do not infer whole #30 or source-wide reconciliation completion. All current code/docs/test changes are task-owned/uncommitted.


### B22b prerequisite implementation verified locally (this commit)

Final full suites after review/currency fixes: SQLite751total,729pass+22database-specific skips,9528assertions/50.688s; MySQL751total,749pass+2SQLite-only skips,9605assertions/226.526s. Pint/PHPStan L6, composer audit(no advisories), diff checks PASS. Before the two control-fix regressions, the snapshot/wallet prerequisite full suites749total also passed; final751 results supersede them. New eight-request capture preserves actual session/CSRF/password-confirmed routes and sanitized private credentials; the deliberately corrupt wallet is independent from the affiliate-credit wallet.

Snapshot120000 migration apply/rollback/reapply passed on isolated SQLite and MySQL testing, applied to local shopbonus; local actual timing checked on_network_approval. No existing financial history backfilled/rewritten, no main rule percentage, mail/provider or monitoring flag changed. D-089 records owner's explicit after-approval choice. Snapshot/fee history protects original calculations; repeated wallet checks retain one original finding. High-severity threshold/currency gaps are reproduced/fixed/regression-tested, not hypothetical compromise claims (FINANCIAL_FINDINGS.md).

Prerequisite boundary VERIFIED_WORKING, remote CI pending. The whole financial reconciliation phase is PARTIAL: no durable whole-platform run/finding/source-check pipeline exists yet, no source-wide totals/revenue recognition/legacy verification claim. Full #30 remains IN_PROGRESS; queue/dependency/schema/security/backup/restore and final E2Es still unfinished. Next normal commit/push/exact-code CI and clean checkpoint; continue source-wide reconciliation from the expanded checklist. No user credential request is needed.


### B22b prerequisite exact-code CI closeout — 2026-10-27

Financial hardening `5aa4177f4160ba41d19e8b54046765e1693cabc5` committed/pushed. [CI40587710798](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40587710798) SUCCESS all three jobs: quality; SQLite729passed+22database-specific skips/9528assertions/38.49s; MySQL749passed+2SQLite-only skips/9603assertions/176.09s.751total per database; both four-process races passed. Local final suites, source fixtures, eight actual HTTP calls, migration rollback/reapply and applied local schema are recorded above. Owner policy D-089 is resolved; no credentials/live policy activation requested or performed.

B22a monitoring is DONE at c7e8789 (CI40580008502 SUCCESS). B22b prerequisites are verified at5aa4177; **B22b remains PARTIAL and whole #30 IN_PROGRESS**. Durable platform-wide run/finding/source checks, allocation/reversal/orphan and legacy-coverage reporting, queue/dependency operational overview, broader database/security evidence, encrypted backup/restore and full ten-card acceptance are not implemented/verified by these checkpoints. The next action is the durable reconciliation runner using existing canonical/authorized services, not a fresh rewrite or silent balancing transaction. Keep separate source semantics and native units. This closeout changes tracking docs only; all application/test changes already committed. Temporary financial harness/server/private DB/cookies removed; only generic result logs retained. B21 local credential file intentionally remains private/gitignored.

### B22b durable wallet-run foundation — 2026-10-28

Owner continues #30 on clean main1d6dd80. Added unique stable reconciliation identities, initial wallet watermark, bounded100-wallet command/scheduler, per-wallet locking reads before aggregates, atomic immutable check/cursor commits, replay/restart safety and explicit operator resume after recorded failures. SQLite/MySQL UPDATE/DELETE triggers protect retained checks. Existing WalletService flags/reporting and authorized correction paths reused; no balancing entries, source policy/rate changes or new HTTP operation. Command reports overall UNKNOWN even for matched/empty wallet runs because affiliate/checkout/funding/sponsorship coverage remains unfinished. This is the wallet-run foundation, not full B22b completion.

Initial full SQLite757tests:734pass+23skip/9570assertions/51.354s. Focused MySQL8tests56assertions including all three four-worker monitoring/wallet/run races PASS. Isolated SQLite/MySQL migration apply/down/up and command after reapply PASS; temporary databases removed. Final projection-evidence addition is under full MySQL verification and targeted SQLite recheck; no pass inferred before execution. PHPStan L6/Pint/composer audit/diff checks pass after formatting correction. No credentials requested, no external providers/mail/Redis flush; approximately49GiB available. Main migration apply and final full MySQL/CI evidence follow before foundation Done.


Final wallet-foundation verification: SQLite757total734pass+23skip/9570assertions/51.354s; final five-test41assertion SQLite recheck after projection-evidence addition PASS. Full MySQL757total755pass+2skip/9652assertions/240.109s including three four-worker races PASS. Pint/PHPStan L6/composer audit/diff PASS. Migration100000 applied to local shopbonus after isolated SQLite/MySQL apply/down/up PASS. Actual local command + same-key replay observed4wallets/0differences, completed execution but overall UNKNOWN; raw command capture B22_WALLET_RUN_CAPTURE.json. No financial changes/extra flags from that run. Temporary migration helper/schemas removed; generic result logs remain. Foundation checklist ticked in this implementation commit; exact SHA/remote CI closeout follows. Full B22b/#30 still unfinished.


### B22b wallet-run exact-code closeout — 2026-10-28

Implementation cfbd770ff4d3d5af75666cebd0545ad7d04edda6 committed/pushed on main. [CI40657966315](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40657966315) SUCCESS all three jobs: quality, SQLite734pass+23skip/9570assertions/40.03s and MySQL755pass+2skip/9650assertions/182.80s (757each); all three real four-worker races passed. Local full suites, migration apply/down/up, local command/replay and quality evidence recorded above. Foundation checklist SHA closed out; this is a docs-only tracking commit, no new app changes. Whole B22b/#30 remains IN_PROGRESS, not ready to mark the whole card Done. Next source-specific reconciliation checks/admin APIs, followed by remaining operations/security/query/backup/recovery work. No new main-code blocker or credential request; external acceptance stays NOT RUN.

### #30 owner “do all” — completion implementation (2026-10-28)

Clean main dc8fc07 start; owner authorizes all remaining main code without credentials. Implemented versioned cross-source reconciliation watermarks/checks and admin trigger/list/detail/resume, operations overview/dependency/default-queue/scheduler/failure observations, encrypted authenticated database/media backup/retention/isolated restore commands and schedules. Reused existing ledger/flags/audit/incidents/permissions, no automatic balancing/cash-recognition/points conversion or source policy activation. Five new operations have19actual HTTPcaptures including401/403/404/409/419/422/423/429/500. Captures use isolated synthetic SQLite/cache/session, array mail, seeded OTP verified via actual endpoint and MFA disabled only fixture. Local captures/temporary source databases removed.

Actual two-server local MySQL encrypted restore verified native wallet/voucher ownership hashes and media, proof artifact retained. Backup917ms/restore3660ms on tiny synthetic source is not RPO/RTO or production-size acceptance. Test server did not fully exit normal shutdown before generated-directory cleanup; subsequently stopped exact task-owned process, removed its task logs/files, and confirmed main MySQL left running. No production schema/data affected. Final backup code also enforces transactional engines/TLS CA, stored encrypted readback digest and private no-credential-export behavior. ext-sodium requirement only; no new package/dependency upgrades.

B22-F005 reproduced expectedcancelled/actualpending when positive pending approval fell tozero; fixed canonical approval state, no credit. Historical immutable credit snapshot now records exact EUR700/300 allocation with cash_settlement_verified=false, not a second revenue ledger. Unsupported/untyped/legacy proof stays UNKNOWN; postback is never trusted credit evidence. Financial source tests include duplicate injection, original rules, compensated/unrecovered reversals, checkout capture and sponsorship/top-up links.

Focused61total58pass+3skip/460assertions PASS; first full SQLite766total743pass+23skip/9705assertions/155.151s passed while temporary test-server shutdown consumed CPU (not production performance). Final reviewed SQLite rerun underway; MySQL full/CI still NOT RUN for final completion boundary. Pint/PHPStan L6 passed; final quality/audit/contract and new migration apply/down/up evidence follow. Query-plan10000checks/20runs shows filtered index removes filesort; unnecessary second index removed and list refresh N+1 avoided. Exact timings/plans in B22_RECONCILIATION_QUERY_PLANS.json. Main migrations110000–140000 not applied yet. Completion/limitations/security and ten-backend-scenario mapping in docs/reliability/COMPLETION.md. Full card remains pending live/offsite/host/approved objectives/accounting acceptance; no claim all production work is Done.


### #30 completion locally verified — application commit follows

Final source/full SQLite767total744pass+23skip/9711assertions/91.268s; full MySQL767total765pass+2skip/9794assertions/264.035s PASS (all three four-worker races). Final orphan-purchase negative source check:28tests252assertions SQLite PASS. Provider HTTP metadata/timestamps follow-up: final Reliability+API29total26pass+3skip/3820assertions SQLite,29pass/3835assertions/52.244s MySQL PASS; exact-code CI runs full expanded768-test suite. Opt-in numeric allowlisted provider response/connection-failure metadata records no URL/body/header; false provider uptime remains UNKNOWN. Final API captures refreshed after interface additions, still19actualHTTPcalls and all security/error statuses; artifact secret-header/password/OTP redaction checks PASS.

Pint/PHPStan L6/composer validate/audit/OpenAPI coverage/diff checks PASS. All four new migrations SQLite/MySQL isolated down/up PASS and applied to local shopbonus batch40; existing foundation batch39 retained. Latest guarded MySQL backup smoke confirms InnoDB check, authentication and stored encrypted readback digest; original actual two-server wallet/voucher/media restore proof retained with final smoke appendix. Synthetic10000check query benchmark filtered median2.043ms→0.550ms and filesort removed; default run page2.074→1.898ms with no meaningful plan change/no speed claim, redundant index omitted.

Implementation checklist ticked only for executed main-code/local evidence in this commit; SHA follows in verified CI closeout. Main backend boundaries implemented; **whole #30 still needs external rollout/offsite/production recovery/provider/accounting acceptance**, explicitly separate unchecked checklist. No main-code credential blocker. Main backups and HTTP/provider collection remain disabled, no real credentials/provider/mail activation. Temporary HTTP/DB/server/helper artifacts removed; generic result logs remain. Source-controlled proof contains only synthetic/public references and numeric/hash metadata. Private B21 local fixture credentials preserved. Approximately46GiB free; no unrelated cleanup/Redis flush. Normal main commit/push and full exact-code CI are next.

### Final traversal-bound follow-up

Application b2ad1561d0a26c6c75553dac32e71afe59e1f172 committed/pushed; CI40665978721 quality and full expanded SQLite745pass+23skip/9720assertions/31.90s passed, MySQL still running at this checkpoint. Final review tightened media enumeration to stop immediately at BACKUP_MAX_FILES, before accumulating an oversized directory inventory. Existing encrypted recovery4tests13assertions, Pint and PHPStan L6 passed again; no archive/API/policy format change. This small tested follow-up is committed separately without rewriting published history; final exact-code CI follows. Whole card external acceptance remains unchecked.

### Dedicated restore-server configuration closeout

Main application b2ad156 CI40665978721 SUCCESS all three jobs: SQLite745pass+23skip/9720assertions/31.90s, MySQL766pass+2skip/9802assertions/178.12s (768each). Guard follow-up64b1848 pushed; its quality/SQLite passed, MySQL still running when this note was written. Added a dedicated mysql_restore connection and blank BACKUP_RESTORE_DB_* / SSL_CA environment template so the senior configures an isolated server without editing application code or inheriting production credentials. Connection remains unselected/disabled until explicitly configured. Recovery4tests13assertions/PHPStan/Pint/diff PASS after formatting. No main-source/API/archive/policy or credential change. Final configuration SHA and exact-code CI follow before verified handoff.


### #30 final exact-code closeout — 2026-10-28

Main implementation b2ad1561d0a26c6c75553dac32e71afe59e1f172, traversal guard64b1848aa0fc3aad61098b8ecc6dd9be550d49fa and isolated env configuratione832ccabb05586f5abdb895391f4c3e440eab316 all committed/pushed on main. [Final CI40666899671](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40666899671) SUCCESS all three jobs for exact e832cca: quality, SQLite745pass+23skip/9723assertions/32.61s and MySQL766pass+2skip/9800assertions/142.10s (768tests/database). All three real four-worker monitoring/wallet/run races passed. Earlier main CI40665978721 and guard CI40666345157 also SUCCESS all three jobs; final evidence supersedes pending notes above.

Main-code checklist items now verified with SHAs; **B22/#30 MAIN BACKEND DONE, whole card external acceptance PENDING**. No authorized main-code implementation remains. Five admin APIs/documentation, bounded source reconciliation, queue/provider/dependency/scheduler observations, immutable finance/audit/incident proof, encrypted backup/readback/retention/isolated restore/schedules and security/query/E2E backend mappings delivered. New source500/authorization/CSRF/OTP/rate/mismatch/resume paths actually captured, no invented examples. No balancing entries/cash recognition/points conversion/affiliate policy activation or live credential/provider/mail changes.

This is a docs-only closeout; no application/test changes. Main migrations110000–140000 remain applied; backups and HTTP/provider collection default disabled. All task capture/schema/server/helper files stopped/removed, generic logs retained; main MySQL/B21 private credentials preserved. Normal docs commit/push/check clean follows. Hassan integrates with docs/api/README.md and docs/reliability/COMPLETION.md. Senior still must configure actual keys/host/offsite/queues/TLS/providers, independently verify production-version/size/offsite/full disaster recovery and feasible approved RPO/RTO/cost/accounting/legal/unit policies. These external items remain NOT RUN and the full Trello card/release is not falsely marked Done.

### B23a / #31 start and native WooCommerce implementation — 2026-10-28

Clean main fb81279 start, approximately47GiB free. Owner “work on it” refers #31. Imported full card into requirements snapshot; audited actual registry, webhook-only Shopify/Woo/native module bridge, connection manager, canonical order ingestion and SSRF guard. #32 overlaps; no separate implementation/card completion claimed. Read current official WooCommerce REST/auth/order/refund/webhook documentation and Shopify2026-10 reference. No invented native topics/scopes, no live verification dates. No credentials requested, frontend/module code or new package/table.

Implemented WooCommerce authenticated GET order/refund/catalog and bounded UTC modified-order polling on the existing Connector interface; optional encrypted read credentials and reviewed profile metadata; managed/scoped3/minute API test with no PII/status promotion/order/wallet/outbox effects. Reused ownership normalization/www convention, public-address resolver/guard and pinned TLS; disabled redirects/proxy, capped response/time/pages, sanitized provider failures, credential-specific rate/server/network circuit. Existing signed webhook behavior remains compatible. Failed/successful API observations use existing chained audit; runtime credential changes discard stale success.

Focused adapter/webhook tests passed34tests265assertions before additional negatives; final native API15tests141assertions passed before final malformed-ID/failure-audit assertions. Initial full suites exposed the expected temporarily undocumented new route/registry contract (no other implementation failures); actual20HTTPcaptures and current OpenAPI/README now added, contract/registry5tests3713assertions PASS. Final full SQLite run underway after docs/schema guard completion; MySQL/quality/CI still pending for this boundary. Do not infer Done from intent. B23a first boundary only: full #31 remains IN_PROGRESS; Shopify/API quality gates, other providers/durable sync and actual native/storefront acceptance remain. Guide docs/integrations/WOO_READ_API.md records limits and exact next phases. All current changes task-owned/uncommitted.


B23a verified local checkpoint: full SQLite783total760pass+23skip/9880assertions/68.363s and MySQL783total781pass+2skip/9961assertions/239.303s PASS. Final native read suite15tests144assertions passed on SQLite and MySQL after reproducing/fixing a numeric-string Redis pause bypass (expectedcircuit_open/actualrate_limited extra provider call). Uses FILTER_VALIDATE_INT rather than int-only guard; regression emulates RedisStore numeric-string behavior without touching shared Redis. Final Pint/PHPStan L6/composer audit/diff and API/registry contract PASS.20actual running-server captures are synthetic upstream/DNS only; source-controlled credential/webhook redaction validation passed. No migration/package/main credential/provider configuration change. Capture helpers/servers/temp schemas removed; generic /tmp logs retained. B23a locally complete; whole #31 IN_PROGRESS. Commit/push/check exact-code CI, then continue Shopify native reads/credential-bound quality gates as owner explicitly “continue” during work. No frontend or fabricated live readiness.


### B23a exact-code CI closeout and B23b local checkpoint — 2026-10-28

WooCommerce implementation `161051edd7952afeaa91e14baecb0b03f33177a3` committed/pushed main; [CI40673100233](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40673100233) SUCCESS all three jobs. SQLite760pass+23skip/9881assertions/49.50s, MySQL781pass+2skip/9961assertions/169.88s (783each). This supersedes pending B23a notes.

B23b extends existing ShopifyConnector with optional encrypted access token, reviewed2026-10 fixed GraphQL reads, actual shop/domain/scope/version checks and bounded orders/basic product metadata. Shared PinnedJsonReader preserves Woo transport security/circuit behavior; ext-curl added as a platform requirement only, no dependency upgrade. Financial mapping uses gross before returns and separate native refund total; actual successful transaction times, no creation-as-payment claim. Protected field denial scrubs partial data; financial/schema errors, throttle and version fallback fail explicitly. Polled order identity/currency/excess-refund regression added during final review. No migration/OAuth/write/ingestion/status promotion/credential activation.

Initial complete SQLite792tests769pass+23skip/9941assertions/57.989s PASS before the final additional polled identity regression. Focused integrations68tests405assertions PASS; final Shopify+Woo25tests214assertions PASS after that regression.11actual running-PHP-server captures with isolated SQLite/database cache/file sessions/normal login+CSRF are in B23_SHOPIFY_HTTP_CAPTURE.json; upstream HTTP/DNS synthetic only. OpenAPI/README five existing path blocks updated from actual captures, contract3tests3692assertions PASS. Initial Pint/PHPStan/composer validate/audit/diff PASS; final full runs/quality/CI still pending at this note. Whole #31 remains IN_PROGRESS. D-094 and SHOPIFY_READ_API.md record exact scope/limits. Temporary capture DB/server/session/helper contents removed automatically; generic /tmp logs/helpers retained until checkpoint closeout. No shared Redis flush, unrelated cleanup or provider/live acceptance claim.


Final review: polled GraphQL records now require matching GID/legacy IDs and valid same-currency refunds. Official Shopify Order reference confirms `transactions(first:)`; request101 as lookahead and reject histories above100 instead of silently accepting a truncated list. Restored the existing Woo consecutive-failure reset after successful bounded JSON reads in shared transport, with a success-between-failures regression. Final native suites27tests229assertions PASS. The first final SQLite run794tests771pass+23skip/9955assertions/54.694s passed before that last reset regression; final driver runs follow and supersede earlier counts.


B23b final local verification PASS: SQLite795total772pass+23skip/9965assertions/53.934s and MySQL795total793pass+2skip/10049assertions/290.521s, sequential full suites on final code. Native27tests229assertions, contract3tests3692assertions; final Pint/PHPStan L6/composer validate/audit/platform requirements/diff PASS.11real HTTP application captures redaction and no PII/status/production-promotion checks PASS. All local implementation checklist items ticked only after executed tests; remote CI remains unchecked. No migrations, dependency upgrades, credentials/provider/financial/mail activation or main database changes. Task capture/docs helpers removed; generic logs retained and private B21 credentials/main MySQL preserved, approximately47GiB free. This implementation commit on main follows, then exact-code CI/SHA closeout. Whole #31 remains IN_PROGRESS; no frontend or genuine provider acceptance claimed.


B23b application `295e8b5d8a787d60c69150c20ef83982a30c69da` committed/pushed; CI40677077084 running. Post-push fixture review found Laravel Http::fake callbacks accumulate, so a loop re-registering callbacks exercised only the first invalid-order variant. Replaced it with one callback referencing the active case; mismatched identity, refund currency and excess refund now independently execute. Corrected native27tests229assertions PASS on SQLite/2.049s and MySQL/14.226s. Application unchanged; tested fixture follow-up gets its own normal commit and exact-code full CI. No fabricated earlier evidence: prior suites passed but did not independently cover all three variants.


### B23b verified closeout — 2026-10-28

Application `295e8b5d8a787d60c69150c20ef83982a30c69da` and corrected test-fixture `01666e100a9e9d7f2da13d394dbc7ed179aa40a8` committed/pushed main. [Exact final CI40677278061](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40677278061) SUCCESS all three jobs: quality, SQLite772pass+23skip/9965assertions/42.25s and MySQL793pass+2skip/10046assertions/203.38s (795tests each). Earlier application CI40677077084 also SUCCESS all3jobs. Corrected native27tests229assertions independently passed both local drivers. This supersedes pending notes above. **B23b DONE in its documented native read-only scope; whole #31 IN_PROGRESS**, no move/full-card completion claim.

This closeout changes docs only. Application/test tree was clean after normal pushes; no schema/package upgrade/provider/credential/financial/mail change. ext-curl is now an explicit existing-runtime requirement. No further suite rerun for these status-only docs.11actual HTTP examples/current OpenAPI/README and SHOPIFY_READ_API.md capture scope/limits and synthetic-vs-live distinction. Task temp servers/DB/sessions/helpers removed; generic /tmp result logs retained; private B21 fixture credentials/main MySQL preserved, approximately47GiB free.

Next main-code boundary: credential-generation-bound durable quality evidence, invalidation on credential rotation/disconnect and safe seven-gate connection status promotion; reuse existing webhook/event/audit/ingestion machinery and handle stale concurrent jobs. Then OAuth lifecycle, durable native missed-order/refund recovery, detailed product/line-item mapping and remaining native providers. Real provider/install/protected-data/banner/coupon acceptance stays NOT RUN until actually observed. No credentials need be passed to the owner/chat for coding.


### B23c credential-bound lifecycle foundation — 2026-10-28

Clean main bc2f739 start; owner explicitly “do it” for next #31 boundary. Audited ConnectionManager, webhook receiver/processor, merchant and Super Admin retries, canonical synchronous order/refund/contact listeners and existing tests before adding schema. Expanded checklist first. Added internal connection/event credential generation; existing events remain null/unbound without invented backfill. Connection-first row locks serialize lifecycle/intake/processing/retries, stale snapshot intake409 and disconnected404; old/unbound queued events ignored/audited before canonical order/refund/contact effects. Rotation clears readiness, disconnect blocks stale credential writers, verification locks/reloads current generation and filters accepted/processed proof. Provider dedupe/history/encrypted payloads preserved. Admin retry retains existing MFA/password/OTP/CSRF/auth controls. No new route/package/financial/provider setting.

Focused69tests556assertions PASS SQLite/3.597s and MySQL/14.073s, including6new lifecycle cases and existing order/admin/native regressions. Final full SQLite801total778pass+23skip/10029assertions/53.878s; MySQL801total799pass+2skip/10112assertions/244.766s PASS sequentially. Pint/PHPStan L6/composer audit/diff PASS; current API contract3tests3693assertions PASS.16actual running-PHP-server captures include normal login/CSRF/password/seeded OTP, rotation/reset verification, merchant/admin stale409, actual HMAC race409 and disconnected404. Synthetic capture-only race hook and disabled MFA fixture clearly labelled, normal configuration unchanged; webhook secrets/tokens redacted. OpenAPI/README8existing path blocks updated from those responses; no invented envelopes. Initial docs helper stopped on quoted YAML path before writing anything; corrected matcher preserves all operations, final coverage PASS.

Migration150000 full-schema isolated SQLite/MySQL exact target down/up proof PASS, retaining encrypted payload and explicit null legacy binding; schemas removed. Applied local main migration successfully; no main rollback or production deploy. Rollout/rollback must quiesce old intake/workers; dropped binding evidence and old unsafe processors require explicit preservation/recovery. Guide CREDENTIAL_LIFECYCLE.md and D-095 record limits. Main code/current tests/docs task-owned uncommitted for implementation commit; no unrelated edits. Whole #31 remains IN_PROGRESS; B23c foundation only, durable seven-gate promotion/OAuth/native reconciliation/providers still main-code work, real-provider acceptance NOT RUN. Temporary capture server/DB/sessions removed, helper cleanup follows; no shared Redis flush/credential/provider/mail/financial change, private B21 fixtures and main MySQL preserved.


### B23c verified closeout — 2026-10-28

Application `aa316ec741c94fa9dddf967c37bad3ea3990db12` committed/pushed main. [Exact-code CI40679706982](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40679706982) SUCCESS all three jobs: quality, SQLite778pass+23skip/10030assertions/49.40s, MySQL799pass+2skip/10113assertions/197.56s (801tests/database). Local full/focused driver suites, current API coverage,16actual HTTP captures/redaction checks and isolated full-schema migration down/up PASS. **B23c lifecycle foundation DONE; whole #31 IN_PROGRESS.** This status-only docs checkpoint supersedes pending notes above; no new application/test changes or guessed test passes.

Migration150000 applied local main; no production deploy/rollback. Capture servers/temp DB/sessions, isolated proof schemas and task helpers removed; generic /tmp result logs retained. Main MySQL/private B21 fixtures preserved; no unrelated cleanup/shared Redis flush/credential/provider/mail/financial setting change. Approximately47GiB free. Exact next main-code boundary remains durable credential-bound seven-gate evidence/safe promotion and native authenticated reconciliation, then OAuth, detailed line-item mappings and other providers. Legacy current-complete-webhook Connected behavior is still compatibility, not seven-gate proof. Real-provider/install/storefront acceptance remains NOT RUN; senior configures credentials without passing them in chat.


### B23d seven-gate readiness and durable targeted recovery — 2026-10-28

Owner “do it” and later “continue” preserve #31. Clean main63d963c start. Expanded checklist before implementation; reused connector native readers, existing encrypted event table and canonical synchronous order/refund/contact listeners, not another order/ledger/auth stack. Added explicit native/webhook source and durable UUID-scoped recovery operations with current generation, verified domain and exact provider-store reference, bounded60second lease/3claimed attempts/55second worker timeout, fixed reads outside locks and generation/credential/domain/store/lease rechecks. Stable canonical native payload hash dedupes repeated observations; staged ingestion drains without another read and processor final failure closes requests. Operator command/enqueued jobs and every-minute bounded drain handle only existing requested known IDs; no new public mutation or automatic store discovery. Same-store key rotation remains supported; different store references cannot merge existing order namespaces, even through disconnect/new connection. Missing historical refs remain unbound, never backfilled.

ProcessWebhookEvent now promotes only after all seven native gates. Complete webhook-only observations remain Limited; authenticated native ingestion/payment proof, actual dedupe and a recovered later state/refund are required. Verification exposes quality_gates/missing_gates; passive test-api still has no ingestion/readiness/status effects. Generic install instructions no longer invent a shipped module. Source-store review guard has actual409 capture. Migrations160000/170000 downgrade historical Connected and invalidate qualification before rollback removes evidence, preserving other limitations/encrypted event payloads; full-schema isolated SQLite/MySQL down/up twice PASS. A stale Eloquent instance in the enhanced rollback fixture was refreshed before reconstructing legacy state; final proof validates actual database state. Both migrations applied local main; no main/production rollback/deploy.

Early focused4failures were obsolete Connected-from-webhook expectations; tests now assert the stronger #31 requirement. Initial recovery helper name collided with PHPUnit's final run method and was renamed before executed recovery verification. Final pre-dependency-patch full SQLite814total791pass+23skip/10118assertions/55.401s, MySQL814total812pass+2skip/10199assertions/281.844s PASS sequentially; final recovery13tests87assertions PASS both drivers. Prior focused80tests624assertions cover integration/order/admin compatibility. Current API contract3tests3694assertions, Pint/PHPStan L6/diff PASS.20actual HTTP captures and operator outcomes cover Limited→native proof→duplicate→change/refund→all7 Connected, unchanged passive probe, failed auth downgrade, rotation, exact-store409,401/500 and honest generic/Shopify setup. Synthetic provider HTTP/DNS only, real PHP server/isolated SQLite/database cache/file sessions/login/CSRF/actual operator command+canonical worker; no live/provider production proof. OpenAPI/README7existing path blocks updated from captures; credentials/webhook tokens redacted.

Final audit newly flagged locked symfony/mime8.1.7. Applied only compatible8.1.10 after targeted dry-run and official release review; composer.lock only, no constraint change. Current audit clean, full post-patch suites running before commit/CI. D-096/097 and READINESS_AND_RECOVERY.md document scope and rollout. Whole #31 remains IN_PROGRESS: native known-order recovery is not bulk missed-new-order discovery or OAuth, other provider adapters/live acceptance remain. Senior configures credentials locally; no owner credential question. Temporary capture servers/DB/sessions/proof schemas removed; helpers cleanup after final verification, generic /tmp logs retained. Main MySQL/private B21 fixtures preserved, approximately39GiB free; no Redis flush/unrelated cleanup/financial/provider/mail activation.


B23d final post-security-patch local checkpoint: SQLite814total791pass+23skip/10118assertions/122.057s, MySQL814total812pass+2skip/10201assertions/369.908s PASS sequentially, including existing real process races. Final targeted native recovery13tests87assertions both drivers; current API contract3tests3694assertions, Pint/PHPStan L6/composer validate/audit/diff PASS. Current audit clean after only symfony/mime8.1.7→8.1.10; no new package/constraint.20actual HTTP captures and source-token redaction/unchanged probe/all7 promotion/rotation reset validated. Migrations160000/170000 full-schema isolated down/up twice PASS with exact-provider unknown/reference rollback and qualified-state invalidation; both applied local main. Source namespace store-switch rejection is actual409, not an assumed error. Local implementation checklist ticked after executed evidence; remote CI remains unchecked. Main implementation commit/push/CI follows; whole #31 IN_PROGRESS, no live/provider production verification or bulk discovery/OAuth claim.

Capture servers/temp DB/sessions/isolated schemas and task helper scripts removed; generic /tmp result logs remain. Main MySQL/private B21 fixtures preserved, approximately38GiB free. No shared Redis flush/unrelated cleanup/credential/provider/mail/affiliate setting activation. This checkpoint's exact next code boundary after verified CI is Shopify OAuth installation/token lifecycle, then bulk durable missed-new-order sync/cursors, detailed item mappings and remaining native providers.


B23d application27faa2f73b7ea97cfdb31a86ae2d73a56bdef386 committed/pushed main; CI40767003950 SUCCESS all3jobs: SQLite791pass+23skip/10118assertions/50.09s and MySQL812pass+2skip/10199assertions/202.28s (814each), audit clean with MIME8.1.10. Post-commit final review found default MySQL case-insensitive URI comparison could conflate case-distinct Woo installation paths across replacement connections. Follow-up uses explicit binary comparison on MySQL/MariaDB and COLLATE BINARY on SQLite, scoped historical connections plus canonical-order existence. No schema/API shape/provider effect change. Recovery14tests92assertions SQLite/1.720s and14tests94assertions MySQL/16.897s PASS; final controlled MySQL collation reproduction1test7assertions/14.922s PASS independent of deployment collation overrides. PHPStan/Pint/diff PASS. Follow-up gets its own normal commit and full exact-code CI; whole #31 still IN_PROGRESS.


### B23d final exact-code closeout — 2026-10-28

Application `27faa2f73b7ea97cfdb31a86ae2d73a56bdef386` and byte-exact store-namespace follow-up `03e69a9c41b384f7b2b34de4401ebfe63a02445a` committed/pushed main. [Final CI40768013858](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40768013858) SUCCESS all3jobs for exact03e69a9: quality/audit, SQLite792pass+23skip/10127assertions/40.09s and MySQL813pass+2skip/10206assertions/166.45s (815tests/database). Main CI40767003950 also all3SUCCESS/814each.14targeted recovery tests passed both local drivers, with an additional controlled MySQL case-insensitive-vs-binary proof; final CI includes all815tests. Local post-MIME-patch full814driver suites, current API contract,20actual HTTP captures/redaction checks and full-schema dual-migration down/up proof PASS. **B23d DONE within seven-gate and targeted known-order recovery scope; whole #31 IN_PROGRESS.** This supersedes earlier pending notes, not a bulk discovery/OAuth/provider-live completion claim.

This is a status-only docs closeout; application/test tree clean after normal pushes, no further suite rerun needed for these status changes. Migrations160000/170000 remain applied local main; their rollback invalidates qualification before deleting proof and does not restore unproved Connected. MIME8.1.10 is the only dependency patch and current audit is clean. No frontend/credential/provider/mail/financial/spendability activation; private B21 fixtures/main MySQL preserved, task temp servers/DB/sessions/schemas/helpers removed, generic logs retained. Approximately38GiB free, no shared Redis flush or unrelated cleanup. Exact next backend boundary: Shopify OAuth install/refresh with senior-configured credentials and verified state/scope/store binding; bulk durable new-order sync/cursors, item mappings and remaining provider adapters follow. Genuine installation/protected-data/storefront/banner/coupon acceptance stays NOT RUN.
