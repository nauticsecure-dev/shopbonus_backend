# Banner storefront local dummy follow-up — 2026-11-02

Status: PARTIAL real merchant/provider acceptance. Owner requested **local dummy data** in this chat. Backend `HassanDev` base `b92f46ab2b471fc4796310e059568bdddde276a4`; frontend `HassanDev` base `bd76ff420cda1aa180e45e44a521ff0707670b3f`. Changes uncommitted; unrelated voucher/dependency work preserved. No migration, deployment, provider activation or live AI call.

## Changes

`public/embed/shopify-cart-v1.js` is an opt-in, read-only Shopify theme cart adapter. It reads the same-origin locale-aware `cart.js` endpoint with same-origin credentials and no caching or redirects. Only safe, nonnegative integer `total_price` and explicitly supported two-decimal presentment currencies become `shopbonus.cart.v1` snapshots. Supported currencies: EUR, USD, GBP, CAD, AUD, NZD, CHF, SEK, NOK, DKK, PLN, CZK. Other currencies/precisions fall back to static terms. This is deliberately limited; it is not a universal provider adapter.

The implementation follows Shopify's [locale-aware Ajax API](https://shopify.dev/docs/api/ajax) and [native cart currency/total contract](https://shopify.dev/docs/api/ajax/reference/cart). No customer, order, cart token, item data or attributes are retained, logged or sent to ShopBonus by this reader.

At most one read runs at a time. Visible pages poll every 10 seconds; reads time out at five seconds. Theme notifications, focus and visibility changes immediately invalidate old evidence. An invalidation during a read discards that response and coalesces notifications into one fresh read. Observation time is the start of the real read, never an evaluator tick; snapshots expire after 30 seconds. Failure clears progress, and a later valid read restores it. Hidden pages stop polling; pagehide aborts and clears evidence; pageshow supports bfcache restoration. Checkout/payment/account paths, including the configured locale prefix, do not install the adapter. An existing merchant bridge is preserved.

`public/embed/v1.js` now discards late draft responses after disconnect, token replacement or expiry. Disconnect also cancels a pending pairing exchange. A failing regression demonstrated private editor content returning after disconnect before the fix; it now passes.

## Installation and theme integration

Within the merchant-authorized Shopify Theme App Embed, load the reader before the public embed, after Shopify's routes object is available. These are example URLs, not an installed extension:

```html
<script defer src="https://YOUR_BACKEND/embed/shopify-cart-v1.js"></script>
<script defer src="https://YOUR_BACKEND/embed/v1.js" data-shopbonus-key="YOUR_PUBLIC_KEY"></script>
```

Theme integration must dispatch the following **after** a successful native add/remove/quantity/currency operation completes:

```js
window.dispatchEvent(new Event('shopbonus:cart-invalidated'));
```

The event supplies no total; the adapter rereads the native endpoint. Without theme notification, polling detects changes within the polling/read interval. Reads are not transactional checkout evidence. No global fetch/XHR patching, DOM price scraping or native cart mutation is used. This adapter does not implement consent-gated checkout attribution (`setAttribution`); an existing full bridge must be retained for that function. Installing this file does not prove Shopify App Embed activation, platform capability or successful paid-order attribution.

## Executed evidence

- `node --test tests/embed-lifecycle.test.cjs`: 21 passed. Actual adapter and embed executed together in a controlled VM. Covers dummy add/quantity/remove/empty/currency changes, bad units/unsupported currencies, failure/recovery/staleness, invalidation during reads, timeout, hidden tabs/bfcache, unsubscribe, route rejection, existing bridge preservation, pairing cancellation and late draft/expiry security.
- PHP 8.4, Xdebug disabled: `BannerTest.php`, `BannerAiTest.php`, `LiveBannerStudioE2ETest.php`: 30 passed, 329 assertions. SQLite test fixtures, synthetic provider only. JUnit: `local-dummy-followup.junit.xml`.
- Frontend `e2e/banner-shopify-cart.spec.ts`: two Chrome cases passed at 1280px and 390px. Uses intercepted dummy storefront/cart/config HTTP responses with the actual reader and embed. Verifies 94% progress, add/quantity/remove, mismatched currency/static terms, outage/recovery, no shopper editor handles, viewport bounds and unchanged native body HTML. Does not require the Next.js server.
- Frontend `npx tsc --noEmit` and targeted ESLint passed. Both public JavaScript syntax checks and diff whitespace checks passed. No API endpoint or response contract changed.

Reproduce Chrome from the frontend directory with `PLAYWRIGHT_CHROMIUM_EXECUTABLE` set to the installed Chrome binary and `npx playwright test e2e/banner-shopify-cart.spec.ts --reporter=list`.

## Acceptance disposition

| Finding | Local evidence | Still open |
|---|---|---|
| Real storefront | Pairing/disconnect/expiry regressions; prior controlled frame/popup cases | Genuine merchant theme, CSP/frame/opener restrictions, authenticated unsaved edit/save/publish |
| Native cart | Shopify read adapter and actual embed exercised with dummy native responses | Real add/remove/quantity/Markets currency journeys; WooCommerce and other native adapters |
| Positioning | Public widget fits desktop/mobile dummy page; prior controlled editor gesture cases | Responsive merchant themes, scrolling/dynamic layouts and detached targets |
| AI design | Synthetic provider regression: editable layers and private reviewed draft | Genuine provider output, brand approval and merchant apply/edit/save/publish |

Full suites, new actual merchant HTTP captures, real provider output and genuine merchant acceptance NOT RUN. No whole-card Done claim. Exact next action: review this patch, install through an authorized Shopify Theme App Embed in a test store, wire post-mutation notification and collect genuine desktop/mobile/cart/AI acceptance evidence. Checklist remains unticked pending an implementation commit/SHA under AGENTS.md.
