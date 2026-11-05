# Native cart readers — 2026-11-04

`public/embed/native-cart-v1.js` implements the existing `ShopBonusCart` snapshot/subscribe/refresh contract for WooCommerce and BigCommerce. Load it before `embed/v1.js` using ordered deferred scripts. It preserves an existing bridge. It never writes a cart, persists identifiers, scrapes prices or uses order webhooks for progress. No checkout attribution writer is added.

## WooCommerce

The candidate WooCommerce plugin (0.2.0) now emits the reader before the renderer when WooCommerce is installed. It supplies WordPress `rest_url('wc/store/v1/cart')`, including subdirectory/plain-permalink installations. It excludes native checkout and account pages, including customized slugs, before emitting either script. Reinstall the rebuilt candidate ZIP on the authorized test store to obtain this change.

For a custom authorized installation:

```html
<script defer src="https://YOUR_BACKEND/embed/native-cart-v1.js" data-platform="woocommerce" data-cart-url="https://YOUR_STORE/wp-json/wc/store/v1/cart"></script>
<script defer src="https://YOUR_BACKEND/embed/v1.js" data-shopbonus-key="YOUR_PUBLIC_KEY"></script>
```

Read source: session-bound GET Store API cart, `totals.total_price` (integer string in minor units), `currency_code`, `currency_minor_unit`. Total includes the provider's applied discounts, tax and shipping. Classic jQuery add/remove/cart-refresh events and Blocks add/remove DOM events invalidate the snapshot. Quantity, coupon, currency and custom theme mutations can explicitly notify after completion; polling remains the fallback. Custom REST prefixes need separate review; current explicit pretty URL validation accepts `/wp-json/`, or the native plain `rest_route` format. Headless/cart-token sessions are unsupported.

## BigCommerce

The native Widgets publisher (`shopbonus:banner-native install`, docs/banners/NATIVE_PUBLICATION.md) now loads this reader before the renderer automatically for new plans. For a manual installation, use the merchant-authorized Stencil storefront script placement, before the renderer:

```html
<script defer src="https://YOUR_BACKEND/embed/native-cart-v1.js" data-platform="bigcommerce"></script>
<script defer src="https://YOUR_BACKEND/embed/v1.js" data-shopbonus-key="YOUR_PUBLIC_KEY"></script>
```

Read source: same-origin session GET `/api/storefront/carts`, exactly one cart's `cartAmount` and `currency.code`/`decimalPlaces`. Decimal amounts convert exactly without rounding fractional minor units. No cart (`[]`) or multiple carts yields no snapshot because a unique total/currency is unavailable. No synthetic zero is inferred. `cartAmount` is the native cart amount, not a checkout grand total. Headless, cross-origin carts and checkout pages are unsupported. Stencil theme code must dispatch the shared notification after completed cart mutations for immediate updates; otherwise polling detects changes.

```js
window.dispatchEvent(new Event('shopbonus:cart-invalidated'));
```

The notification carries no money. The reader always refetches the native cart. No global fetch/XHR hooks are installed.

## Freshness and limits

Both readers allow the same explicit two-decimal currencies as the existing Shopify reader: EUR, USD, GBP, CAD, AUD, NZD, CHF, SEK, NOK, DKK, PLN, CZK. Unknown precision/currency, negative/unsafe/malformed values, HTTP/JSON failure and timeouts invalidate progress. The renderer also requires campaign currency agreement. Poll every 10 seconds when visible; reads time out after 5 seconds; observation time is request start and expires after 30 seconds. Hidden pages and pagehide clear evidence; bfcache restoration refetches. In-flight invalidations discard old responses and coalesce a fresh read. Sensitive route segments, including localized prefixes and BigCommerce PHP account/checkout routes, are excluded. Custom storefront routing must also exclude sensitive pages at installation.

| Platform | Reader source | Installed-store acceptance |
|---|---|---|
| Shopify | Existing `shopify-cart-v1.js` | NOT RUN |
| WooCommerce | `native-cart-v1.js`, opt-in plugin integration | NOT RUN |
| BigCommerce Stencil | `native-cart-v1.js`, ordered merchant script installation | NOT RUN |
| Wix, Ecwid, Lightspeed, PrestaShop, OpenCart, Shopware, Magento, Squarespace, Mijnwebwinkel, CCV | No native reader shipped; retain truthful static fallback or an existing verified merchant bridge | NOT RUN / unsupported by these readers |

This does not promote platform capability/Connected status or prove payment, attribution, installation or reward eligibility. Server eligibility remains authoritative. Real merchant acceptance must record platform/theme versions and exercise add/remove/quantity/coupon/currency, empty cart, outages, sensitive pages, desktop/mobile, consent and uninstall. No live provider store was available in this task.

## Verification

- `node --test tests/embed-lifecycle.test.cjs`: 32 passed, including 11 new cases with actual reader/renderer execution, dummy provider responses, strict validation, race/timeout/lifecycle handling, stale/static fallback, plain WordPress routing and WooCommerce classic/Blocks notifications.
- `php -d xdebug.mode=off tests/woocommerce-banner.test.php`: isolated footer contract fixture, checks ordered output, native URL, disabled/unsafe settings and checkout/account exclusions. This is not WordPress acceptance.
- Merchant archives rebuilt using `scripts/build-merchant-packages.php`; archive/source/hash consistency checked with `MerchantPackageTest`.

Official provider contracts checked 2026-11-04: [WooCommerce Cart API](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/cart), [WooCommerce Blocks DOM events](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/dom-events/), [BigCommerce REST Storefront carts](https://docs.bigcommerce.com/developer/docs/admin/checkout-and-cart/custom-checkouts/rest-storefront). Local fixtures prove the implemented contract, not current merchant installation behavior.
