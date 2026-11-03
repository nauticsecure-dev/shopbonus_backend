# Discovery integration and accuracy review — 2026-10-31

Backend branch `HassanDev`, base `59916e9`; frontend branch `HassanDev`, base
`3d0b0ad`. Changes are uncommitted. Existing unrelated edits are preserved.
No migrations, new routes, dependencies, commits or deployment.

## Result

- API booleans serialize as `1`/`0`; Laravel validation continues rejecting the
  string `true`. Cover retrieval is independent of logo presence. These fixes
  were already present in the working directory and have been retained/tested.
- Directory counts and featured offers use current obtainability: active,
  public offer of a listed shop, started, not ended (`ends_at > now`), available
  code, and allocation limit above non-cancelled voucher count. Sorting now
  reuses the same SQL predicate as filtering rather than separate handwritten SQL.
- Categories, commission agreements, affiliate decisions/programs/connections,
  reward rules, code stock and issued counts load in batches. Missing cashback
  and zero issued counts are authoritative preloaded results, avoiding repeated
  lookups. First-time affiliate decisions are inserted together.
- Shared voucher state owns both the complete collection and loading/error
  status. Concurrent requests are coalesced; responses from an old account or
  locale are discarded. Directory counts derive from that collection. Provider
  startup, purchases, claim/checkout, wallet events, reconnect and focus refresh
  feed this state. Filtered benefits views no longer replace it with a subset.
- URL navigation restores search, category, has-offers, sort and page. Local
  edits create history entries, navigation is applied before URL writes, and
  debounce does not reset a restored page. Existing cancellation/stale-response
  protection, card styling, slugs, wallet integration and guest CTA are retained.

## Freshness contract

Responses are shared public projections, never allocation guarantees.
`DISCOVERY_CACHE_TTL_SECONDS` is clamped to 1–30 seconds. Known future offer
start/end boundaries shorten expiry; boundaries are measured from computation
start, so a request spanning the boundary cannot cache its old result for a
further TTL. Stock status saves/deletes, CSV imports, unique allocation,
voucher creation/cancellation and publication changes bump the cache version.
A second version bump after transaction commit prevents a concurrent reader
from preserving a pre-commit snapshot. Checkout still validates atomically.
Bulk writes bypassing these services/model events must explicitly invalidate;
otherwise their fallback freshness is at most 30 seconds.

## Executed evidence

- SQLite Discovery + Vouchers + OpenAPI coverage: **47 tests, 43 passed,
  4 skipped, 5490 assertions**. Skips include the explicitly opt-in benchmark.
- Separate representative benchmark: **1 passed, 201 assertions**. Reproduce
  in PowerShell with `$env:DISCOVERY_BENCHMARK='1'` and
  `php vendor/bin/phpunit tests/Feature/Discovery/DiscoveryPerformanceTest.php`.
- Added HTTP regressions cover stock/limit changes with a warm cache,
  upcoming/expired/exhausted offers across sort/count/filter, exact scheduled
  boundaries, cover without logo, and constant query growth at 1 vs 12 cards
  including first-time affiliate selection.
- Chromium directory fixture suite: **10 passed**, run with
  `BASE_URL=http://localhost:3000` and `PLAYWRIGHT_CHROMIUM_EXECUTABLE` pointing
  to installed Chromium 1223. `e2e/discovery.spec.ts` exercises desktop three
  columns, mobile one column/no overflow, combined filters/boolean transport,
  debounce/clear, Back/Forward without loops, direct page two/pagination,
  broken images, retry, voucher loading/error and one shared lifecycle refresh.
  API responses in this suite are controlled fixtures, explicitly not live
  platform acceptance. Desktop/mobile screenshots were visually inspected.
- Frontend TypeScript and targeted ESLint passed. Targeted backend Pint passed.
- Broader Affiliate + Vouchers + OpenAPI run: **73 tests, 67 passed, 4 skipped,
  2 failed**, 5582 assertions. Failures are the pending-wallet activity row in
  `AffiliateCashbackOffersE2ETest::test_acceptance_08_*` and the Dutch pending
  explanation in `AffiliateTest::test_card19_e2e10_*`. Existing unrelated
  WalletController/AffiliateActivityController edits explicitly remove pending
  rows/change this wording; those edits were preserved. Do not call that suite
  green.

## Performance and remaining acceptance

The fixture assumes **200 shops, 1000 offers, 2000 codes**, mixed upcoming,
expired, allocation-limited and obtainable offers. Four query shapes cover
broad search/most-offers, combined filters, narrower name search and no match.
There are 100 sequential cold-cache and 100 warm-cache samples through the
Laravel HTTP kernel, SQLite in memory, array cache, throttling disabled.
Local P50/P95: cold **168.64/431.16 ms**, warm **10.82/62.97 ms**. Raw results:
[`directory-benchmark.json`](directory-benchmark.json). These measurements
include application handling but exclude network/webserver overhead and are
not MySQL/Redis/staging/load evidence. Cold local P95 exceeds 300 ms; the staging
P95 target remains unverified. Query growth is fixed; performance acceptance is
still partial.

The public list example was recaptured with a real HTTP request to local
`artisan serve` on port 8018 using the existing demo catalog, working-tree
backend based on `59916e9`. See
[`directory-http-capture.json`](directory-http-capture.json); OpenAPI's list
example uses this actual response. Earlier README captures are historical.

`image(20261023-121324).png` was not supplied, so pixel-close matching remains
unverified. The ten directory browser regressions do not establish all ten
broader Trello #18 journeys (registration, activation, token protection,
profile/security and production operational acceptance). Those require a
separate complete browser run against the real services. The whole brief
remains PARTIAL for these acceptance items.


### Additional live browser evidence

Chromium live API smoke: 1 passed (26.3s), against frontend localhost:3000
and the actual local Laravel server 127.0.0.1:8018 using existing demo catalog
data. The browser proxy forwarded API requests; no fixture API responses or
customer mutations. Search=woon + category=home_living + has_offers=1 + sort=name
and the resulting clean-slug detail rendered successfully. Test source:
frontend e2e/discovery-live.spec.ts; enable with DISCOVERY_LIVE_API_BASE_URL.
Actual screenshots: [desktop](../../../docs/discovery/directory-live-desktop.png),
[mobile](../../../docs/discovery/directory-live-mobile.png). The earlier fixture
suite passed 10 tests independently. Full platform journeys remain unverified.
The temporary API server was stopped after captures; existing frontend left running.
Post-documentation OpenAPI coverage: 3 passed / 5101 assertions.

## Owner-selected local HTTP follow-up — 2026-11-03

The owner selected local services for further evidence. Actual Laravel HTTP + Next/Chrome checks, isolation, exact scenario coverage and remaining limits are documented in ../../shopbonus_frontend/docs/consumer-platform-local-acceptance.md. Final raw 100-sample measurements are in ../../shopbonus_frontend/docs/consumer-platform-local-results.json: directory discovery-cache cold P95 257.74 ms; repeated directory 106.28 ms; authenticated dashboard 146.01 ms. Runtime: PHP 8.4.25, bundled process-scoped OPcache, Xdebug disabled, isolated SQLite (200 shops, 1001 offers, 2008 codes), database cache, sequential loopback HTTP; browser page activity stopped before timing. Control bootstrap/cache invalidation is outside the timed request; PHP opcode cache is warm.

The local target passes under that configuration. Earlier no-OPcache measurements and a paging/heap-affected interrupted attempt remain retained rather than being relabeled as passes. No global PHP configuration, production API code or historical 431.16 ms HTTP-kernel result was altered. Representative staging, MySQL/Redis/load, deployed cache invalidation, full accessibility and approved visual acceptance remain unverified. Serial ConsumerPlatformE2ETest/PointsCheckoutTest/OpenApiCoverageTest: 21 passed, 8035 assertions; docs/release/consumer-platform-local-tests.junit.xml.
