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
- `docs/release/API_INVENTORY.md` (334 routes, from `shopbonus:api-routes`) and `docs/release/LAUNCH_READINESS.md` (evidence map, deploy/rollback runbook, open items).
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

# Active handoff — #31 B23d verified, whole card IN_PROGRESS (2026-10-28)

Branch main. Application `27faa2f73b7ea97cfdb31a86ae2d73a56bdef386` and byte-exact follow-up `03e69a9c41b384f7b2b34de4401ebfe63a02445a` committed/pushed. [Final CI40768013858](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40768013858) SUCCESS all3jobs for exact03e69a9: quality/audit, SQLite792pass+23skip/10127assertions/40.09s and MySQL813pass+2skip/10206assertions/166.45s (815tests/database). Main CI40767003950 also all3SUCCESS/814each. This is a docs-only closeout; no uncommitted application/test changes. **B23d DONE within seven-gate/targeted known-ID recovery scope; whole #31 IN_PROGRESS.** D-089/D-093–097 apply.

Delivered all-seven-gate Connected policy replacing complete-webhook promotion. Complete webhooks remain Limited; generation/domain/exact-store-bound authenticated native ingestion, mapping/payment proof, observed native dedupe and actual recovered later state/refund are required. Existing encrypted event and canonical order/refund/contact pipeline reused. Durable UUID requests, lease60s/worker55s/3claims, network outside locks, stale result checks, canonical payload dedupe, staged resumption and explicit failed processing. Operator-only shopbonus:recover-integration-order command and bounded existing-request scheduler drain; no new public recovery mutation or automatic store discovery. Passive test-api still never ingests/persists readiness/promotes. Verify adds quality_gates/missing_gates. Store switches cannot silently merge canonical namespaces, including case-distinct paths under MySQL collation; normal same-store rotation remains supported. Generic setup no longer implies a shipped module. Guide docs/integrations/READINESS_AND_RECOVERY.md.

Local post-MIME-patch full814tests/database PASS: SQLite791pass+23skip/10118assertions/122.057s, MySQL812pass+2skip/10201assertions/369.908s, sequential. Final byte-exact recovery14tests92assertions SQLite and14tests94assertions MySQL PASS, plus controlled MySQL case-folding proof1test7assertions; exact CI runs all815. API contract3tests3694assertions, Pint/PHPStan L6/composer validate/audit/diff PASS.20actual HTTP captures use real PHP server/isolated SQLite/database cache/file sessions/normal login+CSRF/actual operator+canonical worker, with synthetic provider HTTP/DNS; include all7 progression, passive probe, failure downgrade, rotation, source-switch409 and401/500. Seven current OpenAPI/README path blocks updated, keys/tokens redacted. Registry live verification stays unverified; no real-provider acceptance claim.

Migrations160000/170000 applied local main. Full-schema isolated SQLite/MySQL exact targets down/up twice PASS: legacy Connected downgraded, other limitations/encrypted payload retained, unknown provenance/store refs remain null, qualified status invalidated before proof removal. No main/production rollback/deploy. Quiesce old workers/intake and preserve staged evidence for rollout/rollback. Reapply never restores fictitious qualification. Only dependency change is symfony/mime8.1.7→8.1.10 for the current security advisory; official compatible patch, audit clean. Native recovery can update canonical orders/voucher matching/lawful contact plans under existing rules; it is an operator action, not a passive probe. No affiliate timing/financial/provider/mail setting change.

Exact next: normal docs closeout commit/push/check clean, then expand Shopify OAuth install/refresh checklist and review current official docs before code. Use senior-configured app credentials, existing encrypted connection lifecycle, validated OAuth state/scopes/store ownership and current-generation guards; no credentials needed in chat. Bulk durable missed-new-order discovery/cursors, detailed line-item mapping and remaining native provider adapters still require backend code. Genuine platform install/protected-data/storefront/banner/coupon acceptance NOT RUN. Full #31 not ready to mark Done/move remaining backend to Hassan.

Task capture servers/temp DB/sessions/proof schemas/helpers removed; generic /tmp logs retained. Main MySQL/private B21 fixtures preserved, approximately38GiB free. No shared Redis flush/unrelated cleanup/production deploy. Older pending B23d notes below are historical and superseded; B23c aa316ec CI40679706982 all3SUCCESS remains verified history.

---

# Active handoff — #31 B23c verified, whole card IN_PROGRESS (2026-10-28)

Branch main. Application `aa316ec741c94fa9dddf967c37bad3ea3990db12` committed/pushed; [CI40679706982](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40679706982) SUCCESS all3jobs for exact SHA: quality, SQLite778pass+23skip/10030assertions/49.40s and MySQL799pass+2skip/10113assertions/197.56s (801tests/database). This is a docs-only closeout, no uncommitted application/test changes. **B23c credential-lifecycle foundation DONE; whole #31 IN_PROGRESS.** D-089/D-093/D-094/D-095 apply.

Delivered internal credential generations on connections/accepted events, with explicit null legacy binding. Connection-first row locks serialize lifecycle/intake/processing and merchant/admin retries. Rotation clears readiness; disconnect removes credentials and prevents stale credential writers; queued stale/unbound proof ignored/audited before order/refund/contact effects. Intake rechecks credential snapshot/generation; rejection metadata generation-bound. Verification reloads/locks and filters current accepted/processed proof; probes reject generation changes. Provider dedupe and historical encrypted events/orders retained. No new routes/packages/financial/provider activation. Legacy complete-current-webhook Connected promotion remains compatibility until separate seven-gate replacement, not full-readiness proof.

Local focused69tests556assertions PASS SQLite/3.597s and MySQL/14.073s; full SQLite801total778pass+23skip/10029assertions/53.878s and MySQL801total799pass+2skip/10112assertions/244.766s PASS sequentially. Pint/PHPStan L6/composer audit/diff PASS; API contract3tests3693assertions PASS.16actual HTTP application captures use isolated SQLite/database cache/file sessions/normal login+CSRF+password+seeded synthetic OTP. MFA disabled only in capture fixture; deterministic intake-race hook explicitly synthetic, not claimed multi-process proof. Secrets/tokens redacted and readiness/stale/privacy checks verified.8existing OpenAPI/README paths updated. Guide docs/integrations/CREDENTIAL_LIFECYCLE.md.

Migration150000 isolated full-schema SQLite/MySQL exact target down/up PASS, legacy event binding stays null and encrypted payload preserved; applied local main successfully. No production/main rollback. Quiesce old intake/workers for deployment/rollback and preserve binding evidence; mixed old receivers omit generation and old workers lack guards. Never rebind old proof to new credentials. Recovery requires fresh authenticated provider data/new signed delivery, not replaying an old dedupe ID.

Exact next action: normal closeout docs commit/push/check clean, then expand next checklist for durable generation-bound seven-gate evidence and safe status promotion through existing webhook/audit/ingestion machinery, native missed-order/refund reconciliation, OAuth lifecycle, detailed line-item mapping and remaining providers. Existing ProcessWebhookEvent promotion/ConnectionManager checklist are not all seven #31 gates. No credentials needed from owner for coding; senior configures locally. Genuine provider/install/protected-data/storefront/banner/coupon acceptance NOT RUN; full card not ready to mark Done/move remaining backend to Hassan.

Task capture server/temp DB/sessions/proof schemas/helpers removed; generic /tmp result logs retained. Main MySQL/private B21 fixtures preserved, approximately47GiB free. No shared Redis flush/unrelated cleanup/credential/provider/mail/financial timing changes. Previous B23b295e8b5/test01666e1 and CI40677278061 remain verified all3SUCCESS (795tests/database).

---

# Active handoff — #31 B23b verified, whole card IN_PROGRESS (2026-10-28)

Branch main. Application `295e8b5d8a787d60c69150c20ef83982a30c69da` and test-fixture correction `01666e100a9e9d7f2da13d394dbc7ed179aa40a8` committed/pushed. [Final CI40677278061](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40677278061) SUCCESS all3jobs for exact01666e1: quality, SQLite772pass+23skip/9965assertions/42.25s and MySQL793pass+2skip/10046assertions/203.38s (795tests/database). Application CI40677077084 also SUCCESS all3jobs. This is a docs-only closeout; no uncommitted application/test changes. **B23b DONE within read-only scope; whole #31 IN_PROGRESS**, not ready for full-card completion/handoff.

Delivered optional encrypted Shopify approved app token, fixed2026-10 identity/scopes/order/transaction/refund/basic product reads, domain ownership/version checks and bounded cursor/transaction lookahead. Shared pinned JSON transport preserves Woo safety, Redis numeric-string cooldown and consecutive-failure resets. Gross total before returns + separate native refunds; successful transaction times only; protected fields scrubbed/missing, GraphQL schema/financial/version/throttle errors fail explicitly. Existing scoped/throttled/audited probe returns only PII-free summaries and never promotes status, ingests orders or posts financial/contact effects. Manual webhook compatibility retained. D-089/D-093/D-094 authoritative. No migrations/dependency upgrade/provider or credential activation; ext-curl now explicit Composer platform requirement.

Local final full SQLite795total772pass+23skip/9965assertions/53.934s and MySQL795total793pass+2skip/10049assertions/290.521s PASS sequentially. Corrected native27tests229assertions PASS SQLite/2.049s and MySQL/14.226s; one callback now isolates all three invalid-order cases after detecting Laravel accumulated fakes. Pint/PHPStan L6/composer validate/audit/platform/diff PASS.11actual HTTP captures use isolated SQLite/database cache/file sessions/normal login+CSRF and synthetic upstream/DNS. OpenAPI/README five existing paths updated; redaction/no PII/status/live-promotion checks PASS. Guide docs/integrations/SHOPIFY_READ_API.md. No further full rerun for this docs-only status closeout.

Exact next action: commit/push this closeout and confirm clean; start credential-generation-bound durable quality evidence and invalidation on rotation/disconnect, then safe seven-gate promotion using existing webhook/audit/ingestion architecture with stale/concurrent-job tests. Legacy ProcessWebhookEvent complete-order Connected logic and ConnectionManager checklist are compatibility behavior requiring that next boundary. OAuth install/refresh, durable missed-event/order/refund recovery, detailed line-item mapping and remaining native providers still require main code. Expand next checklist before implementation. No credentials needed from owner for coding; senior configures locally. Real provider/protected-data/OAuth/install/storefront/banner/coupon acceptance NOT RUN.

No schema apply/rollback needed. Task capture server/temp DB/sessions/helpers removed; generic /tmp logs retained. Private B21 fixtures and main MySQL preserved, approximately47GiB free. No shared Redis flush/unrelated cleanup/financial timing/provider/mail changes. B23a `161051e` CI40673100233 already SUCCESS all3jobs; older pending notes below are historical and superseded.

---

# Active work — #31 B23a WooCommerce native reads (2026-10-28)

Branch main, base fb81279. B23a task-owned application/tests/docs locally verified and ready for this implementation commit; no pre-existing edits overwritten. Owner asks to work on #31, not to move more backend to Hassan. Full #31 imported, #32 overlap noted. B23a extends existing WooCommerceConnector/registry/connection API, not a new order/ledger architecture. Native read-only order/refund/catalog and bounded modified-order pagination, optional encrypted credentials, ownership/public-DNS pin/TLS/no redirects or proxy, capped time/body/pages/circuit, scoped managed audit-only test endpoint implemented. No provider credentials, frontend/module/storefront implementation, migration/package or financial/spendability change. D-089/D-093 apply.

Evidence:15native API tests passed before final malformed-ID/audit assertions; existing adapters/webhook tests passed.20actual HTTP server captures in docs/integrations/B23_WOO_HTTP_CAPTURE.json include200/201/401/403/404/409/419/422/429/500/502/503, changed catalogue/create/show/credential and new test-api. Synthetic upstream/DNS only, array mail, actual normal browser auth/CSRF; credential/generated-webhook values redacted. OpenAPI/registry5tests3713assertions PASS after documentation. Initial pre-doc full run only failed missing-new-route/registry coverage; final full SQLite now running. MySQL/full CI final quality pending; no guessed passes.

Final local evidence: SQLite783total760pass+23skip/9880assertions/68.363s; MySQL783total781pass+2skip/9961assertions/239.303s PASS. Final Woo15tests144assertions both drivers after Redis numeric-string bypass reproduced/fixed; quality/contract/audit/redaction PASS. Exact next: commit/push/check B23a exact-code CI, then continue Shopify native read API and credential-bound quality gates per owner “continue”. No schema apply/rollback needed. Guide docs/integrations/WOO_READ_API.md documents current native read scope/official sources and remaining work. Whole #31 remains IN_PROGRESS, not done or ready to move backend to Hassan. Next main-code phase Shopify/native authenticated transport + runtime credential-bound seven-gate readiness/durable recovery, then other providers. Genuine store/module/banner tests remain NOT RUN, credentials senior-owned.

Temporary capture/docs helpers removed; temporary capture DB/server/private credentials already removed by harness. Generic /tmp/shopbonus-b23* logs retained. Main MySQL and private B21 fixture credentials preserved; no Redis flush/unrelated deletion, approximately47GiB free. #30 main code remains CI-verified and unchanged except shared existing registry/provider infrastructure extension for this new ticket.

---

# Active handoff — #30 main backend DONE, external acceptance pending (2026-10-28)

Branch main. Main implementation b2ad1561d0a26c6c75553dac32e71afe59e1f172, traversal guard64b1848aa0fc3aad61098b8ecc6dd9be550d49fa and isolated configuratione832ccabb05586f5abdb895391f4c3e440eab316 committed/pushed. This is a docs-only verified closeout; no uncommitted application/test changes remain. **B22/#30 MAIN BACKEND DONE; whole Trello card/deployed release still pending external acceptance.**

[Final CI40666899671](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40666899671) SUCCESS all three jobs for exact e832cca: quality, SQLite745pass+23skip/9723assertions/32.61s and MySQL766pass+2skip/9800assertions/142.10s (768tests each), all three real four-worker races PASS. Main/guard CI40665978721/40666345157 also SUCCESS. Local full767test suites plus final source/provider/recovery/API focused checks, Pint/PHPStan L6/composer validate/audit/diff/API coverage all PASS. Exact final CI includes the expanded768test suite and supersedes earlier pending notes below.

Delivered version2 durable source scans/watermarks/immutable findings while legacyv1 stays scoped; wallet/affiliate/historical rule/duplicate/orphan/reversal/purchase/top-up/sponsorship checks and existing authorized correction reuse. Five protected admin trigger/list/detail/resume/operations endpoints, fresh MFA/password/OTP/CSRF/rate/audit controls, timestamps/scheduling-inclusive elapsed time, bounded pagination/no list N+1. Default-queue ready wait, scheduler/dependency and opt-in allowlisted numeric HTTP provider observations plus existing incidents. Disabled authenticated encrypted DB/media backup/readback/retention/hourly and weekly isolated verification; separate MySQL connection/server/UUID guard and env-only mysql_restore configuration. Reproduced/fixed zero-approved-commission false pending state; no credit. Original immutable credit evidence now records exact unrecognized EUR split, never fabricated cash/revenue or points conversion.

D-089/D-091/D-092 apply: new rewards spendable after network approval, no policy/rate/provider/credential activation. No frontend, arbitrary balancing entry or customer communication. Source missing/legacy/independent settlement/offsite proof remains UNKNOWN rather than false healthy. Backups and HTTP/provider monitoring default disabled; normal main admin MFA unchanged. Senior configures independent backup key/destination and isolated restore DB/TLS environment values without sending credentials to owner/chat.

Evidence: docs/reliability/COMPLETION.md has configuration/commands/security matrix/ten local-backend-scenario mappings and external limitations; B22_COMPLETION_HTTP_CAPTURE.json19actual HTTPcalls including401/403/404/409/419/422/423/429/500 and current OpenAPI/README examples, isolated synthetic SQLite/array mail/seeded OTP and capture-only MFA disablement. Secret redaction verified. B22_MYSQL_RECOVERY_PROOF.json real two-server local MySQL encrypted restore matched wallet/voucher ownership hashes/media plus final transactional-engine/authentication/readback smoke. B22_RECONCILIATION_QUERY_PLANS.json10000checks/20runs/actual plans and filtered filesort removal; not production SLA. No actual offsite/production-size or RPO/RTO claim.

Migrations2026_10_28_110000/120000/130000/140000 applied local shopbonus batch40, foundation100000 batch39. Isolated SQLite/MySQL down/up and reapply scope2 run PASS. Rollback removes original source/recovery/operational evidence: preserve and quiesce commands first. Task servers/isolated schemas/private capture/helper files removed; generic /tmp/shopbonus-b22* logs remain. Main MySQL and gitignored private B21 local-integration credentials preserved. Approximately46GiB free, no unrelated cleanup/Redis flush or resource stop.

Exact next: normal docs-only closeout commit/push/check clean. No further authorized main-code boundary remains for #30. Hassan integrates the five documented APIs using docs/api/README.md. Senior configures deployed host/media/infrastructure inventory, queue/scheduler/TLS/admin MFA/provider/alert settings, private independent offsite storage/retention/credential escrow, actual production-version/volume isolated and full recovery drill, feasible approved RPO/RTO/cost and cash/accounting/legal/unit acceptance. These external checklist items remain NOT RUN; do not mark the full Trello card or production release Done from backend/local evidence.

---

# Active handoff — #30 wallet-run foundation verified (2026-10-28)

Branch main. Application cfbd770ff4d3d5af75666cebd0545ad7d04edda6 committed/pushed. This is a docs-only closeout; no uncommitted application/test changes remain. **Durable wallet-run foundation DONE; B22b PARTIAL; whole #30 IN_PROGRESS.**

[CI40657966315](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40657966315) SUCCESS all three jobs: quality, SQLite734pass+23skip/9570assertions/40.03s, MySQL755pass+2skip/9650assertions/182.80s (757tests each). All three real four-worker monitoring/wallet/run races passed. Local full SQLite734pass+23skip/9570assertions/51.354s, MySQL755pass+2skip/9652assertions/240.109s plus final focused SQLite5tests41assertions PASS. Pint/PHPStan L6/composer audit/diff PASS. Existing HTTP contract coverage passed; no new HTTP operation.

Delivered stable run identity/fixed wallet watermark, atomic per-wallet check/cursor,100-wallet bounded command/every-minute scheduler, interrupted-process continuation and explicit failed-run resume, original mismatch proof with native units/projections and SQLite/MySQL append-only triggers. Reuses canonical WalletService flags/reporting/authorized corrections; no balance repair, financial ledger mutation or new correction path. A completed wallet scan remains overall UNKNOWN without source-wide proof; observed mismatch is CRITICAL. Individual locked observations are not a platform-wide point-in-time snapshot. D-089/D-090 authoritative; no affiliate timing/rate/provider/mail setting change.

Migration2026_10_28_100000 applied to local shopbonus; isolated SQLite/MySQL apply/down/up + command after reapply PASS. Rollback drops run/check evidence, preserve it and quiesce the command first. Actual local command/replay observed4wallets/0differences and correctly reported UNKNOWN; raw artifact docs/reliability/B22_WALLET_RUN_CAPTURE.json. Temporary helper/schemas removed; generic /tmp/shopbonus-b22-run* logs retained. Private B21 fixture credentials remain gitignored/preserved. Main monitoring still defaults disabled; no Redis flush/unrelated cleanup. Approximately49GiB free.

Exact next main-code action: add source inspectors for affiliate/checkout/top-up/sponsorship/ledger evidence (rates, ownership, attribution, duplicate credits, compensating reversals and allocations), extend durable run coverage without relabelling old wallet-only runs, then admin run/report APIs with existing Super Admin/MFA/password/step-up controls and actual HTTP captures. Expand checklist before each tested boundary. No arbitrary balancing entries, fake recognized revenue or points-to-EUR conversion; missing legacy snapshots remain UNKNOWN. Continue operations/dependency/security/query/backup/recovery work afterward. No staging/production/offsite access or actual RPO/RTO/live acceptance evidence. Do not mark #30 complete or ready to move wholly to Hassan. No credential request is needed for the next main-code boundary.

---

# Active handoff — Trello #30 IN_PROGRESS, verified checkpoints (2026-10-27)

Branch main. Monitoring application c7e878905e6ab7ff80bff27b2b4bdbb3f3692ebe and financial prerequisite application5aa4177f4160ba41d19e8b54046765e1693cabc5 committed/pushed. This is a docs-only closeout; no uncommitted application/test changes remain. **B22a DONE; B22b PARTIAL; whole #30 is NOT DONE.**

**CI evidence:** monitoring40580008502 SUCCESS all three jobs (743tests/database). Financial prerequisites [40587710798](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40587710798) SUCCESS all three jobs for exact5aa4177: SQLite729pass+22skip/9528assertions/38.49s; MySQL749pass+2skip/9603assertions/176.09s (751each), four-worker alert-cooldown and wallet-flag races passed. Local final suites, Pint/PHPStan/composer audit, API coverage/diff checks, ten monitoring plus eight financial real HTTP calls, synthetic source evidence/query-plan benchmarks and migration rollback/reapply passed. No real provider/cash/revenue/production availability claim.

**Delivered:** opt-in metadata-only request/query observations, bounded exact percentiles/UNKNOWN coverage and existing incident threshold alerts with immutable evidence/retention/cooldown. Wallet reconciliation locks before reading, bounds its standalone scan by initial max ID, retains one original open flag and does not repair cached balances/ledger. New reward evaluations freeze policy/rule terms; credits freeze source/commission/unit/ledger evidence; sponsorships freeze original on-top fee rate. Monetary rule/evidence changes are blocked; deactivation/lifecycle transitions remain. Increased approved commission re-checks original review limit; non-EUR rewards dispute without invented FX and acceptance409. Existing role/MFA/password/CSRF/dual-admin machinery reused. Scoped findings/regressions: docs/reliability/FINANCIAL_FINDINGS.md.

**Owner decision resolved:** new affiliate rewards become spendable only after network approval, the existing configured default (D-089). Frozen earlier regimes are not rewritten. Legacy missing timing/rate evidence stays unknown, no backfill from current configuration; points are not EUR. Existing compatibility code is not approval to activate instant credit. No main setting/rule percentage/balance/provider/mail flag changed; monitoring still defaults off. Main admin MFA remains enforced.

**Schema/local state:** migrations110000(api observations/immutable incident evidence) and120000(nullable reward/credit/fee snapshots) applied to shopbonus; SQLite/MySQL rollback/reapply PASS. Production rollback drops original evidence: quiesce affected processing/preserve first. Only isolated fixture schemas rolled back in tests. Temporary capture servers/DBs/cookies/harnesses removed; generic /tmp/shopbonus-b22* result logs retained. B21 private local fixture credentials intentionally remain gitignored at storage/app/private/local-integration/credentials.json. No unrelated cleanup/Redis flush; resource threshold not triggered.

**Exact next main-code work:** implement durable idempotent platform-wide reconciliation runs, immutable per-source findings/checks with bounded background processing and honest failed/unknown coverage. Compare existing source/owner/historical snapshot/duplicate-credit/compensating reversal/checkout and sponsorship allocation evidence. Reuse WalletService and authorized correction workflows; never post arbitrary balancing entries. Standalone wallet checks must acquire current wallet state before financial reads; avoid wrapping the entire audit in a stale global transaction snapshot. Then queue/provider/dependency operational overview, broader schema/query/security evidence, encrypted backup and isolated restore/main-code recovery. Expand/check off remaining B22b/B22 checklist only with actual executed evidence. No new package/route/table without reuse audit.

**External acceptance still missing:** production/staging/provider/offsite access, actual hosting backup inventory, adopted RPO/RTO capability/cost and full ten-card E2E/release evidence. Proposed15-minute RPO/four-hour RTO are not promises. Senior configures deployment/provider credentials without posting them in chat. Never mark #30 complete or ready to move wholly to Hassan from these partial backend checkpoints.

---

# Current handoff — B21 complete, 2026-10-27

Branch: `main`. Application SHA: `7991841c32307fa96b5011c3ac719a1f13fb6368`. Test-only clean-environment correction: `a7ebb0e9c1ecd286540f9ab1c6057c45f7df2a95`. Both pushed. This is a docs-only closeout; no uncommitted application/test changes remain. **B21 main backend is DONE.**

[CI40559448895](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40559448895) passed all three jobs for exact `a7ebb0e`: quality, SQLite (714 passed + 20 skips, 9417 assertions, 44.00s), MySQL (732 passed + 2 skips, 9484 assertions, 115.88s). Each database ran 734 tests; real six-process funding replay and promotional stock races passed. Local full suites, final API coverage, Pint/PHPStan L6/composer audit/OpenAPI references/diff checks also passed. The first CI exposed one test relying on local Privacy configuration; explicit test fixture correction passes with empty legal environment settings on both databases and in final CI. Application fail-closed behavior stays enforced.

Implemented optional UUID funding request identity scoped to workspace and actor, immutable amount/provider UUID, at most one provider create attempt and honest creating/created/uncertain state. Legal discovery returns canonical current versions and only matching published document links from the same page. Verified promotional claims use canonical inventory, one voucher per account/offer, with secret-free responses and support impersonation blocked. New opt-in local fixture command preserves existing identities/content and keeps normal MFA; default seeding is empty. D-086/087 record policy choices; affiliate credit timing remains unresolved and unchanged.

Migration `2026_10_27_100000_add_funding_request_identity.php` applied to local `shopbonus`; rollback/reapply passed on isolated SQLite and twice on MySQL. InnoDB rollback restores the original supporting FK index before dropping the new composite index. Rollback removes request history: quiesce funding writes and preserve identities before production rollback. Creating/uncertain payments without a provider reference require manual provider reconciliation, never automatic resubmission.

Local setup and safe rerun succeeded: four verified LOCAL SAMPLE accounts (consumer, merchant, two admins), labelled NL/EN legal/CMS/FAQ, approved public sample shop and an active promotional offer with ten codes. Both legal version/document availability checks pass. Generated passwords and IDs are only in `storage/app/private/local-integration/credentials.json` (gitignored, file0600/directory0700). Keep it private; admin MFA remains enforced. No wallet credits, live integrations or outbound mail enabled.24 real local HTTP captures include browser signup/login and auth/CSRF/rate/error/replay paths, using a synthetic payment upstream. Capture harnesses/servers/private ephemeral databases removed; generic test logs retained in `/tmp`. Local disk was approximately48GiB free at task start; no stop-rule exception was needed.

**Next:** Hassan uses [LOCAL_INTEGRATION.md](integration/LOCAL_INTEGRATION.md) and the API examples for frontend integration. Senior developer configures approved real legal content, mail/providers/models and deployment, then records actual live financial/inbox/staging acceptance. Those remain NOT RUN and are not claimed complete. No B21 main-code blocker or work remains. Finish pushing this tracking-only closeout and check git is clean.

---

# Latest handoff — #29 main backend complete

**Branch/SHA:** main; implementation911e053 +approval-scope follow-up5a8c36d committed/pushed; CI40412989638 and final40414186822 SUCCESS all3jobs. This final docs-only evidence closeout follows; no application code change or pre-existing edits overwritten. Owner explicitly “do it”; all remaining #29 main code complete. D-081 below-cutoff authorization persists for this task, no unrelated cleanup/Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Status:** B20a–f **DONE (main backend)**; ready for Hassan frontend integration. Whole Trello card still needs its UI and senior configured real mail/model/deployed Reverb/monitoring/staging acceptance. Those are NOT RUN, not guessed passes.
**Delivered:** full23-event catalogue, encrypted transactional source outbox and current eligibility; legacy merchant projection/shared-read compatibility with personal pre-projection receipts; full preferences/current public workspace context; durable queued mail claims/stable IDs/actual MIME, bounded safe pre-send retry and explicit ambiguous delivery review; configured default30/7/1 voucher/default7day offer reminders and last-moment checks; retention/deletion locks/minimal dedupe tombstones; email-only promotion support with public/favorite/consent checks. Immutable existing settings templates/test/independent publish/rollback/NL fallback; strict capped per-admin-idempotent configured Responses drafts, no provider/source PII, no auto publication or economic/contact policy activation.16new operations (15admin +owned DELETE);21 notification ops including prior5;104 actual HTTP captures.
**Configuration:** NOTIFICATION_EMAIL_ENABLED=false, RETENTION_DAYS90; AI_ENABLED=false, model/key empty, default10generations/day hard100. Senior configures actual MAIL/model/key/budget, queue retry_after>60, scheduler/Reverb/TLS/origins and records live acceptance. Reviewed notifications.policy controls bounded retention/email pause/reminder windows. No credentials needed from owner for coding.
**Migrations:**150000 outbox/legacy link/personal read receipts/in_app_enabled;160000 delivery records;170000 tests/AI quotas/generations/fallbacks. Final local MySQL apply/rollback/reapply PASS, currently applied shopbonus. SQLite and MySQL CI down/up regression PASS. Only empty task schemas were used during local rollback checks; no unrelated data deleted.
**Exact CI40412989638:**719tests/database. SQLite701pass+18skip/9278assertions/40.77s; MySQL717pass+2skip/9340assertions/144.63s. Publication4-worker/global quota2-worker/mail claim4-worker races explicitly PASS on MySQL. Pint/PHPStan L6/composer audit green. Full local SQLite718total700pass+18skip/9262assertions before final recovery test;9-source-test45assertions after it; final contract13pass3669. Full local MySQL NOT RUN for this checkpoint (low disk), actual MySQL CI provides final executed evidence.
**Final follow-up:** actual stepped-up approval journey now explicitly asserts owner-only approved notice/correct workspace reference and admin-only review notice; AgreementAndReviewTest14pass375assertions locally. No app-code change from911e053. Follow-up5a8c36d CI40414186822 SUCCESS all3jobs: SQLite701pass+18skip/9281assertions/43.05s; MySQL717pass+2skip/9341assertions/101.94s. Actual approval-to-notification scope assertions passed on both databases.
**Evidence/setup:** docs/notifications/NOTIFICATIONS.md has exact APIs/fields/events/defaults/ten backend acceptance groups. B29_HTTP_CAPTURE.json +README/OpenAPI:104 real local HTTP requests, actual SPA password/seededOTP and DB queue worker; array-mail sent/attempts2 after reviewed synthetic prior uncertain attempt. Model completions/malformed output and one storage500 are explicitly temporary local fixtures; no live-provider/customer/deployed WebSocket claim. phpunit test-only512MiB after observed128MiB limit; production unchanged. D-083–085.
**Cleanup/status:** all task capture fixture DBs/env/keys/passwords/cookies/router/logs/helper scripts removed;8079/8089 stopped and no fixture switch shipped. Only generic verification/CI logs /tmp/shopbonus-b29-* retained. Working tree clean after this normal docs/test commit/push; no open main-code blocker.
**Next exact:** Hassan builds frontend using notification handoff/API examples; senior configures/enables/independently publishes tested policy/templates and records real mail/OpenAI/Reverb/deployment/staging acceptance. No further #29 main-code implementation required. Main code and follow-up tests already CI-verified; final docs-only normal commit/push precedes the report. No further backend action remains.

---

# Previous implementation checkpoint — #29

**Authorization/branch:** owner “do it” completes #29 main backend; senior configures credentials. D-081 below-cutoff authorization persists. main/deea998 clean start, all current code/docs task-owned and ready for implementation commit/push; no pre-existing work overwritten. No unrelated cleanup/Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Status:** B20b–e main implementation locally verified; final B20f/CI/MySQL acceptance pending before backend Done. Full card frontend/live/deployed acceptance is never claimed from local mocks.
**Features:** durable encrypted source intents and current recipient eligibility; canonical merchant compatibility projection/personal read receipts; full23-event registry/preferences and16 new operations (15 admin+owned DELETE,21 notification operations including prior5); same-transaction mail rows, queued claim/stable Message-ID, actual MIME render, bounded pre-send retry and explicit uncertain outcome review; configurable30/7/1 voucher/default7day offer reminders, last-moment source/stage checks, retention/deletion locks/tombstones; email-only promotions hidden from inbox with current consent/public/favorite eligibility. Immutable existing settings template versions/test/independent publish/rollback and metered NL fallback; strict fixed-host/no-PII configured Responses drafts, per-admin UUID/global daily cap, no automatic publication or finance/contact policy activation.
**Migrations:**150000 outbox/legacy link/read receipts/in_app_enabled;160000 mail deliveries;170000 template tests/AI quota/generations/fallbacks. Exact final local MySQL apply/rollback/reapply PASS, currently applied shopbonus; empty-task-table guard preserved all unrelated data. SQLite new-migration rollback/reapply test PASS. Initially overlong fallback index fixed; no unrelated tables removed.
**Evidence:** full SQLite718total700pass+18skip/9262assertions/75.523s. Final new outbox failure/recovery regression subsequently passes9 source tests/45assertions, current total719 expected CI. Final API+diagnostics13pass/3669assertions after104 actual HTTP captures. Full Pint/PHPStan L6/composer audit/diff PASS. Full local MySQL/races NOT RUN due~300MiB free; exact-code CI must execute complete MySQL and3 real process races. phpunit test memory512MiB after observed128MiB failure; production limit unchanged. Generated empty YAML artifact fixed and registry-dependent regression suite passes.
**HTTP:**104 captures in B29_HTTP_CAPTURE.json +README/OpenAPI, actual sessions/password/seededOTP, actual DB queue worker/array transport; synthetic Responses completion/refusal/malformed and kernel500 fixtures labelled. Reviewed synthetic uncertain mail accepted by actual worker (sent/attempts2, previous attempt explicitly synthetic). No live model/customer inbox/deployedReverb/frontend/staging claims. Templates/roles/private policy safeguards tested; docs/notifications/NOTIFICATIONS.md has complete contract/event/setup/limits. D-083–085.
**Cleanup:** all local capture databases/env/keys/passwords/cookies/router/logs removed;8079/8089 stopped, no fixture switch in app/config/routes. Temporary helper/generic verification logs /tmp/shopbonus-b29-* remain for final closeout cleanup.
**Next exact:** commit/push implementation main normally; verify all CI jobs including actual MySQL source publication, AI-quota and mail-claim races. Fix real failures if any. Then replace this pending status with exact SHA/CI evidence, tick final B20f/CI items only after pass, docs-only closeout commit/push and clean status. Hassan frontend +senior config/live verification follow; no credential request needed.

---

# Previous working checkpoint — #29

**Branch/base:** main/deea998 clean start. All current implementation/docs changes are task-owned and uncommitted; no user work overwritten. Owner says “do it”; finish #29 main backend. D-081 authorizes below-cutoff disk continuation for all #29, no repeated permission request. No unrelated cleanup, Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Current:** B20b–f main source/outbox/merchant bridge, mail, reminder/retention/delete, strict reviewed AI templates/admin API code implemented; final verification/API docs still IN_PROGRESS. Parent card not Done or ready frontend-only yet.
**New code:** NotificationSources/Catalogue/Dispatcher/Delivery/Mail/Maintenance/Policy/Templates/TemplateAi, DeliverNotificationMail, DispatchNotifications, NotificationEvent/Delivery, new NotificationController under /api/v1/platform/notifications; User-owned DELETE notification; existing preferences/catalogue/platform-settings expanded. No economic/credit timing/contact policy activation. Source intents persist with financial transaction; no inline mail during posting. Ambiguous SMTP delivery requires human review, not automatic retry or a false exactly-once provider claim.
**Migrations:**150000 notification_events/legacy link,160000 notification_deliveries,170000 template tests/generation quotas/fallbacks, all MySQL apply/rollback/reapply PASS and currently applied shopbonus. Initial overlong fallback index fixed; removed only2 empty newly created task tables from failed170000, no unrelated deletion.
**Executed:** focused37tests34pass+3MySQL-only skips/248assertions before last support-review/deactivation source refinement. Actual multipart mail through array transport and unsafe/pre-send/uncertain/retry/template lifecycle/source/reminder tests pass.74 real local HTTP captures (B29_HTTP_CAPTURE.json), actual session/password/seededOTP, actual DB queue worker +arraymail, synthetic provider success/failure and isolated kernel storage500 fixture. Temporary router/DB/env/keys/credentials/cookies/logs removed. No genuine provider/mail/deployed Reverb/frontend evidence.
**Exact next:** finish API README/OpenAPI from74 captures; final focused/full SQLite and feasible local MySQL tests (free disk~370MiB), quality/audit; record unchecked scopes only after passing, commit/push main normally and verify all CI jobs. Expand/mend any actual gaps discovered; full card frontend/live acceptance remains senior/Hassan. Preserve source evidence and do not claim missing tests as pass. Generic task logs /tmp/shopbonus-b20-* and /tmp/shopbonus-b29-* remain.

---

# Previous #29 B20a checkpoint

**Session/authorization:** 2026-10-26. Owner asks to start #29; senior configures credentials. Explicit “Continue #29 below the limit” is recorded in D-081; do not request permission again for this task's ~5GiB cutoff. No unrelated deletion/Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Branch/base:** main/d2fb5ed clean start; implementation5c1d8cc committed/pushed, CI40399876856 SUCCESS all3jobs. This docs-only closeout updates evidence; no pre-existing work overwritten.
**Status:** whole #29 PARTIAL. B20a owned inbox/support-reply foundation DONE and exact-code CI-verified. No full-card Done or frontend-only handoff yet.
**Code:** NotificationInbox/NotificationPreferences, encrypted CentralNotification (Laravel DatabaseNotification), NotificationInboxChanged;5 new verified owner routes, InboxController; User notifications relation; SupportReviews same-transaction support notification; support-session guards/channel callback. Source/card preserved and B20a–f checklist expanded first.
**Behavior:** generic server NL/EN text, no reply body/codes/financial facts; per-user/source dedupe+rowlock, current membership/non-suspended workspace and current admin-role visibility, idempotent read/read-all, shared locale/preferences without granting consent. Only support_reply/workspace_support_reply wired. Email unavailable/not_implemented explicit. Minimal queued after-commit hints, durable HTTP reconnect; queue failure cannot roll back committed reply/read state. Legacy merchant APIs unchanged until explicit B20b compatibility bridge.
**Migration:**2026_10_26_140000_create_central_notifications adds notifications +notification_preferences; local MySQL apply/rollback/reapply PASS, currently applied shopbonus. Test DB shopbonus_testing isolated.
**Tests/evidence:** full SQLite688total672pass+16skip/8873assertions/71.284s. Final MySQL688total686pass+2skip/8927assertions/273.128s. Earlier focused MySQL11pass98assertions includes actual4-worker replay; final4 negative tests included in full SQLite. Pint/PHPStan L6/composer audit PASS; OpenAPI3pass3358assertions.24 actual local HTTP captures, synthetic users/source reply and isolated SQLite/database queue/sessions; actual Reverb-configured channel auth owner200/other403/support403.500 temporary kernel-bound storage failure labelled; ephemeral signature redacted. No deployed WebSocket/live mail/model/frontend proof. docs/notifications/NOTIFICATIONS.md and B20A_HTTP_CAPTURE.json; README/OpenAPI updated. Task capture fixture files/credentials/environment/database/router logs removed; generic /tmp/shopbonus-b20-* verification logs remain.
**Next exact:** B20a implementation/CI complete; closing docs-only commit/push records5c1d8cc and green CI. Next implementation boundary B20b: centralize merchant notification compatibility/event wiring; B20c email outbox/recovery/retention, B20d reminders, B20e approved AI templates/admin, B20f full10 backend E2Es remain unchecked. Credentials are not a coding prerequisite.

**Exact implementation CI:**40399876856 SUCCESS all3jobs,688tests/database. SQLite672pass+16skip/8877assertions/39.76s; MySQL686pass+2skip/8927assertions/126.47s; quality green. Dispatch-failure recovery fixture throws an event listener at dispatch; genuine deployed queue/email/Reverb outages NOT RUN. Local server/router/capture fixtures/credentials cleaned; no8079 listener remains. Last disk~168MiB, no ENOSPC; no unrelated cleanup. Closing docs-only changes are the only uncommitted work at this checkpoint, then normal commit/push.

---

# Previous checkpoint — #26 complete main backend

**Session:** 2026-10-26, Codex. Owner explicitly asks to finish remaining support backend; model/key/budget configured by senior developer. D-075 authorizes continuing #26 below~5GiB; do not ask again on that cutoff. No unrelated cleanup, Redis flush or binlog purge. No subagents/frontend/NPM/external customer messages.
**Branch/base:** main /7c1657d clean start. Implementation d63f616 +final corpus guard7616e88 committed/pushed; final CI40388179684 SUCCESS all3jobs. Final docs-only evidence closeout follows; no pre-existing work overwritten. No pre-existing work overwritten.
**Status:** complete #26 **main backend implementation**, **DONE**, committed and exact-code CI-verified.16 new operations and all remaining chat/history/model/retry/Reverb/feedback/review/settings/knowledge main-code features delivered. Whole Trello card retains Hassan frontend and senior live-model/deployed TLS/production monitoring/staging acceptance. Do not label those unexecuted checks as passed.
**Code:** SupportChat queued scoped/idempotent CAS engine, SupportPlanner, SupportProvider, SupportPolicy, SupportReviews, SupportKnowledge, expanded SupportTools/VerifiedSupportAnswer;7 Support* models; ProcessSupportMessage/SupportProgress; Support/ChatController +Admin/AiSupportController; existing CmsController mutations delegate to immutable FAQ service. Config support_ai/reverb, routes.api/channels/console, PruneSupport command. Existing quote now truly readonly; campaign paid attribution excludes cancelled/refunded/incomplete sources. Unknown starts remain null; no reward-unit/payment/date guesses.100 static EN/NL questions+bounded500 approved human regressions actually run; changed corpus/failed cases gate publication, not automatic learning. FAQ snapshots/rollback preserve provenance/checksum/newrevision/reason, legacy approved_at unknown/null.
**Security:** fresh identity/workspace/individual permissions before egress and rendering; Super Admin no tenant bypass; support impersonation blocked for all chat APIs/private channel. Model gets only opaque selected evidence plus allowlisted facts; raw question/IDs/contact/payment/codes excluded. FAQ questions/prose/contact text stays in Laravel. Strict required Responses function, fixed host/no redirects/retries, one attempt, bounded tokens/time; server templates only, no financial actions/model prose. One distinct targeted research round; integrity rereads are bounded checks. Encrypted scoped private transcripts/evidence/comments/replies, default30d retention (1–90), explicit deletion/scheduler recovery. Actual human review reference only after DB success.
**Configuration:** envSUPPORT_AI_ENABLED=false default plus independently published support.ai enablement, configurable model/key; global100/peruser20 requests/day defaults, output1000 hard256–2000, timeout20 hard5–20; model/key/project budget senior-owned. Queue tries1/timeout60, retry-after>60. Reverb1.12 installed, loopback/default-deny origins, credentials empty; Guzzle7/PSR7+Promises2 resolve supported dependency ranges, full app tests pass. No real OpenAI call/model account claim.
**Migrations:**120000_create_support_chat and130000_create_support_knowledge_versions both apply/rollback/reapply PASS local MySQL; applied local shopbonus. Test suites manage shopbonus_testing. No ledger/legal/affiliate/contact activation.
**Validation:** final full SQLite672total657pass+15skip/8690assertions/60.13s; sequential MySQL672total670pass+2skip/8752assertions/149.91s. Both include FAQ rollback/corpus gates. Final small provider metadata-only privacy refinement+added test:13provider tests39assertions PASS; Exact d63f616 CI673total: SQLite658pass+15skip/8706assertions/19.41s; MySQL671pass+2skip/8753assertions/77.80s; all3jobs SUCCESS. Final before/after corpus fingerprint guard proved with mid-read human-case insertion:5admin tests68assertions passed on both databases, plus quality. Exact final7616e88 CI673total: SQLite658pass+15skip/8704assertions/31.67s; MySQL671pass+2skip/8755assertions/94.99s; all3jobs SUCCESS.2 real MySQL process races pass (UUID idempotency, global quota). Focused Support+CMS+API169total167pass+2skip/4649assertions before final provider privacy test; latest API coverage after8 extra route-specific409 captures also checked. Final Pint/PHPStan L6/composer audit/diff check PASS.
**Actual integration evidence:**75 real local HTTP captures in API README/OpenAPI for16new ops+changed settings/FAQ/quote/banner behavior; actual browser/bearer/password+seededOTP and database queue worker, synthetic upstream explicitly labelled. Real local Reverb WebSocket/subscription/signed broadcast test passed owner200/other403, Research→Verification→Verified, only conversation/message/status payload. Sanitized docs/support/REVERB_LOCAL_CAPTURE.json. No live email/OpenAI/deployedReverb/staging/frontend claim. All task capture fixture databases/credentials/environment/harness/logs deleted; ports8079/8089 stopped. Generic test logs remain /tmp/shopbonus-b17-*.log.
**Handoff:** docs/support/SUPPORT_CHAT.md has route inventory, config, client flow, source limits, retention and all10backend acceptance mappings. EVIDENCE.md original B17a inventory is marked historical/superseded. D-076–080 record choices.
**Next exact:** no B17 main-code work remains. Closing turn commits/pushes this docs-only evidence checkpoint and checks status. Hassan integrates frontend with docs/support/SUPPORT_CHAT.md and API examples. Senior developer configures supported model/key/budget, publishes tested support.ai settings, deploys queue/scheduler/Reverb/TLS/origins and records actual provider/production monitoring/staging acceptance. Do not request credentials from owner as a coding prerequisite. Whole Trello/front-end/live acceptance is still not marked Done. Platform B18release/live integrations are separate unfinished acceptance, not claimed completed.

---

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- **Local Redis is shared with other projects.** Only delete keys matching `shopbonus-*` (databases 0 and 1). Never `FLUSHDB`/`FLUSHALL`; see the incident note below.
- Migrations applied locally: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000/130100` (audit, errors), `2026_10_23_140000..140300` (identity), `2026_10_23_150000` (webshops, memberships, platform roles), `2026_10_23_160000` (invitations), `2026_10_23_170000` (business profiles, verifications, ownership columns), `2026_10_23_180000` (agreements), `2026_10_23_190000` (connections, webhook events), `2026_10_23_200000` (orders, order events), `2026_10_23_210000` (offers, offer versions), `2026_10_23_220000` (voucher codes, batches, vouchers, events), `2026_10_23_230000` (contact suppressions, opportunities, outbox; order customer_key/consent), `2026_10_23_240000` (integration clients, inbound events, opportunity events, voucher claims, claim reminders), `2026_10_23_250000` (public profiles, public categories, offer slugs), `2026_10_23_260000` (users phone/avatar, email change requests, favourites, category preferences, privacy requests), `2026_10_23_270000` (wallets, ledger + triggers, lots, reservations, pending, reconciliation flags), `2026_10_23_280000` (affiliate connections, programs, selections, clicks, reward rules, transactions, reconciliation events), `2026_10_24_100000` (voucher checkouts, checkout payments), `2026_10_24_110000` (voucher redemption columns, disputes, dispute events), `2026_10_24_120000` (funding invoices, sponsorship obligations, top-ups), `2026_10_24_130000` (merchant notifications), `2026_10_24_150000` (CMS pages, locales, revisions, assets, FAQ), `2026_10_24_160000` (cookie policies, cookie consents), `2026_10_24_170000` (users two-factor columns, step-up challenges, impersonation sessions). `2026_10_24_140000` (banner installations, campaigns, versions, assets, pairings, events, clicks, daily stats; `webshop_orders.attribution_click_id`).
- Env key names added in B02: `LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION`, `LEGAL_MARKETING_CONSENT_VERSION` (empty = registration disabled), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` (true in prod), `SESSION_SAME_SITE`, `VERIFICATION_CODE_TTL_MINUTES`, `VERIFICATION_CODE_MAX_ATTEMPTS`, `VERIFICATION_RESEND_COOLDOWN_SECONDS`. B07: `ANSWER24_DISPATCH_ENABLED` (false), `ANSWER24_CONTACT_PATH` / `ANSWER24_CANCEL_PATH` / `ANSWER24_CLAIM_STATUS_PATH` (Shopbonus-defined defaults), `ANSWER24_BASE_URL`, `ANSWER24_SIGNING_SECRET` (secret), `ANSWER24_ALLOW_LEGITIMATE_INTEREST` (false), `ANSWER24_RECENT_CONTACT_DAYS` (30). B06: `DIRECT_REWARD_FEE_BPS` (3000). B05: `BUSINESS_TIMEZONE` (default Europe/Amsterdam), `BROADCAST_CONNECTION` (null by default). B04: `WEBHOOK_PAYLOAD_RETENTION_DAYS` (default 14). B03c: `GOOGLE_PLACES_API_KEY` (secret; empty = manual entry only). B12: `FUNDING_REQUIRED` (true), `FUNDING_PERCENTAGE_BASIS` (maximum_discount). B11a: `MOLLIE_API_KEY` (secret; test_ keys only), `MOLLIE_API_BASE_URL`, `MOLLIE_ALLOW_LIVE` (false). B10: `AFFILIATE_CREDIT_TIMING` (on_network_approval), `AFFILIATE_FALLBACK_NETWORK` (daisycon), `DAISYCON_API_BASE_URL`. B09: `WALLET_CREDIT_VALIDITY_MONTHS` (12). B16a: `ADMIN_MFA_REQUIRED` (true; false in phpunit.xml), `ADMIN_STEP_UP_CODE_TTL_MINUTES` (10), `ADMIN_STEP_UP_MAX_ATTEMPTS` (5), `ADMIN_STEP_UP_VALID_MINUTES` (10), `ADMIN_SUPPORT_SESSION_MINUTES` (30). B08a: `DISCOVERY_CACHE_TTL_SECONDS` (60), `API_DOCS_ENABLED` (default on outside production). Earlier: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
- Capturing docs: run the server with `QUEUE_CONNECTION=sync MAIL_MAILER=log`, read codes/tokens from `storage/logs/laravel.log`, then **truncate that log**, since it holds live codes and reset tokens.
- **Admin captures since B16a:** admin routes need confirmed 2FA. Either start the server with `ADMIN_MFA_REQUIRED=false`, or enrol 2FA in the script and disable it again at the end (`DELETE /user/two-factor-authentication`), as the B16a capture did. TOTP codes can be computed with `php -r "require 'vendor/autoload.php'; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp('<secret>');"`. Never put the secret, QR code, recovery codes or support tokens in docs.

## Conventions to reuse

- Routes: `routes/api.php` v1 group. Private routes go inside `auth:sanctum` + `account.active`, and inside `verified` unless the email-verification flow itself needs them. Declare `x-access` per operation in `docs/api/openapi.yaml`; `OpenApiCoverageTest` enforces docs, examples and access matching.
- Errors: `ApiException` (supports `$details` and `$headers`). Audit: `AuditLogger::record()` in transactions, `recordSafely()` otherwise. Append-only models: `use App\Models\Concerns\AppendOnly`.
- Public IDs: models exposed to clients get a ULID `public_id` (`HasUlids` + `uniqueIds()`); never expose integer keys.
- Session-flow tests: `use Tests\Concerns\BrowsesLikeSpa` and `$this->spa('POST', ...)`. Bearer-token requests after `spa()` must `flushHeaders()` and flush the session store (see `AdminSecurityTest::asSupport`), or the test client leaks the SPA origin.
- Sensitive admin actions: `password.recent` then `step_up.otp` (emailed code, `StepUpCodes`). New sensitive non-admin routes must be added to the `GuardSupportSession` denylist so support sessions cannot use them.
- Index names over 64 characters fail on MySQL only: give composite indexes explicit short names.

## Incident note (2026-10-23)

While capturing docs, an early script ran `redis-cli FLUSHDB` on local Redis **database 0 twice**. Database 0 may have held other local projects' data, which would be lost. Database 1 (other projects' cache keys) was not touched. The capture script now deletes only `shopbonus-*` keys.

## Blockers / open decisions

- OPEN-SIGNUP-ENUMERATION (new), OPEN-AFFILIATE-CREDIT-TIMING, OPEN-REWARD-UNITS, OPEN-FINANCIAL-LEGAL, OPEN-MARKETING-CONSENT, OPEN-PROVIDER-CAPABILITIES, OPEN-EMPTY-CARD-27 (see `docs/DECISIONS.md`). None block B03.
- Remote: `origin` = https://github.com/nauticsecure-dev/shopbonus_backend (owner-requested one-time force push on 2026-10-24 replaced an unrelated "first commit" `7d7790c`, kept locally as tag `backup/remote-first-commit-7d7790c`). Normal pushes only from now on. GitHub CI runs; PHP 8.4 only (D-055). No staging (p95 unverified).

## Workspace conventions (B03a)

- Workspace-scoped routes: `Route::prefix('workspaces/{workspace}')->middleware('workspace')`, plus `->middleware('workspace.can:<permission>')` per route. Read the webshop from `CurrentWorkspace`, never from input. Declare `x-permission` in OpenAPI.
- Non-member access is a generic 404 (and audited by the resolver); members lacking a permission get 403.
- Commercial routes (offers, vouchers, integrations, payments) must add `workspace.commercial` after `workspace`.
- Tests needing a commercially active webshop: `use Tests\Concerns\CreatesActiveWebshops` → `$this->activeWebshop($owner)`.
- Fees: always `FeeCalculator` (never inline percentages).
- Vouchers: only through `VoucherInventory` (reserve with an idempotency key such as `order:<id>:offer:<id>` or `answer24-handoff:<ref>`). Never update voucher/code status directly.
- Bulk inserts that bypass Eloquent must generate `public_id` with `strtolower((string) Str::ulid())`.
- Sponsored vouchers move merchant funding automatically inside `VoucherInventory`; tests get funded webshops from `CreatesActiveWebshops` (drain with `WalletService::adjust` to test unfunded cases).
- User-facing messages: write them in English and add the Dutch text to `lang/nl.json` (keyed by the English text); `__()` is applied in the error renderer.
- MySQL returns 0 affected rows when an UPDATE changes nothing: never treat `update() !== 1` as "not found" unless the update always changes a column (status/revision).
- Push with `git push origin main` (never force) after each task; CI must stay green.
- Checkouts only through `CheckoutService`; lock order offer → checkout → code → wallet. Test Mollie with an `Http::fake` callback driven by test properties (the first registered fake wins, so re-faking inside a test does nothing).
- Wallet changes only through `WalletService` (idempotency key on every call; never touch `wallets` columns directly). Choose the unit explicitly.
- Tests that commit real rows (concurrency) use `Tests\Concerns\RacesProcesses` + DatabaseTruncation, call `truncateAfterRace()` in tearDown, and override `truncateDatabaseTables()` to skip on SQLite (see `ConcurrentWalletTest`).
- Partner (server-to-server) routes: `->middleware('integration.signed:<scope>')`, declared `x-access: integration` with `security: [{ integrationSignature: [] }]`. Credentials only via `php artisan shopbonus:create-integration-client`. Tests sign with `App\Support\SignatureV1::header()` (see `Answer24ChannelTest::signed()`).
- Claim links: only through `App\Services\Answer24\ClaimLinks` (handoff/accept/reminder/expire). Outbox delivery only through `OutboxDispatcher` (`shopbonus:dispatch-outbox`).
- Public read endpoints: under `Route::prefix('public')`, data through `PublicCatalog` only (published fields, cached via `DiscoveryCache`). Models whose change affects public data must call `DiscoveryCache::flush()` on save.
- Consumer routes: under `Route::prefix('consumer')` in the verified group; always `$request->user()`, never an ID from input. Optional mail must check `CommunicationPreferences::allows()`.
- Notifications sent with `Notification::route(...)` must type `toMail(object $notifiable)` (an on-demand recipient is not a `User`); `Notification::fake()` never renders mails, so add a render test.
- API docs browsable at `/docs` and `/docs/reference` (local: `php artisan serve` → http://127.0.0.1:8000/docs).
- Admin routes: inside the `admin` prefix group (`platform:super_admin`); mutations inside `password.recent`. Test them with a stateful session (`Referer`/`Origin` headers + `withSession(['auth.password_confirmed_at' => time()])`).
- **Never write a denial/security audit inside a DB transaction that then throws**: it rolls back with it. Audit before the transaction, or after commit.
- Never use database `NOW()` in queries or scripts: local MySQL runs in the server's timezone while the app writes UTC. Use PHP `now()`.
- The agent shell is zsh: never name a shell variable `path` (it is tied to `PATH`). Capture scripts are bash files using `caplib.sh` (see the scratchpad pattern described in PROGRESS); keep that pattern.
