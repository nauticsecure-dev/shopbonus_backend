# Lightspeed C product catalog checkpoint — 2026-11-03

Local implementation complete; genuine installed-store acceptance NOT RUN. Backend branch HassanDev; initial observed HEAD e9d01b6e35204b6f00ac19aaa6290147a8df4495, current shared-tree HEAD during verification 9546163c2d9a1caf9bb8e3f11203ca6efc3233d8. This checkpoint remains uncommitted. No provider verification flag, application database migration, deployment or native provider write.

The existing Lightspeed C connector now implements CatalogReader. The existing queued CatalogSynchronizer persists tenant-bound snapshots and retains its credential-generation, pause, disconnect and failed-pass removal protections. Native reads reuse the verified domain/shop identity gate, cluster/language allowlist, HTTP Basic authentication and PinnedJsonReader security/rate limits. Each page rechecks the observed shop currency against the currency captured at pass start.

Reviewed official contracts on 2026-11-03: [products](https://developers.lightspeedhq.com/ecom/endpoints/product/) and [variants](https://developers.lightspeedhq.com/ecom/endpoints/variant/). Products use GET /{language}/products.json, page/limit=25. Each product uses GET /{language}/variants.json?product={numeric_id}&page=1&limit=2. Provider resource URLs are never followed. Exactly one returned variant supplies SKU, tax-inclusive price/old price and tracked stock quantity. With zero or multiple variants these values stay null. Product identity/title, description, visibility, image and update timestamp are mapped. Malformed/oversized pages, duplicate product IDs and foreign variant associations fail the pass. A full product page requests another page; only an observed final page permits removal of stale snapshots.

Limits: no aggregate multi-variant price/stock, per-variant table, category assignments, inferred storefront URL, coupon reads/writes, native banner publisher or live provider acceptance. Product URLs remain null because the API value is a slug whose storefront routing is theme/language dependent. Stock quantity is the provider-reported value; Lightspeed documents it as combined stock when multishop is enabled, not store-local sellability. Category and coupon capabilities remain UNSUPPORTED; implementing products does not promote them. Provider-owned setup/error localization, OpenCart native reads, Shopify coupon writes, BigCommerce/other native publishing and Adobe async/SaaS remain separate open work.

Executed checks on PHP 8.4.15, isolated test SQLite, Xdebug off:

- Focused Lightspeed C catalog suite: 12 PASS / 267 assertions.
- Full integration directory plus OpenAPI coverage: 206 PASS / 9,914 assertions; JUnit: storage/app/lightspeed-catalog-regression.junit.xml.
- After refreshing the captured OpenAPI example: OpenApiCoverageTest 3 PASS / 7,868 assertions. Final scoped Pint and git diff whitespace checks passed.
- PHPStan for the changed application/config files: zero errors.
- Pint for changed PHP files: PASS after formatting.
- Two actual local HTTP 200 responses captured in [LIGHTSPEED_CATALOG_HTTP_CAPTURE.json](../api/LIGHTSPEED_CATALOG_HTTP_CAPTURE.json), reproduced with `php -d xdebug.mode=off scripts/capture-lightspeed-catalog.php`. Isolated synthetic SQLite, actual Laravel HTTP server, no provider requests. They establish platform/connection declarations, not successful remote synchronization. API README/OpenAPI reference the capture.

Earlier focused test attempts failed because the synthetic .test domain was correctly rejected by the ownership-domain validator; fixtures now use a public-domain-shaped example.com name with a mocked resolver. The final suites above pass.

Next action: review this uncommitted checkpoint, then execute an authorized C-Series installed-store run for both clusters/eligible plans, language, variant cardinality, currency drift, provider rate limits, pause/resume, credential rotation and snapshot removal. Choose a release SHA before recording provider acceptance in VERIFICATION.md. No migration or new endpoint was added. Checklist boxes remain unticked pending the required implementation commit SHA.
