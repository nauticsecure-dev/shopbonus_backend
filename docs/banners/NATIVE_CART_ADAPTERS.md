# Native cart readers — 2026-11-04

`public/embed/native-cart-v1.js` implements the existing `ShopBonusCart` snapshot/subscribe/refresh contract for WooCommerce, BigCommerce and the opt-in B93 PrestaShop9/OpenCart4.1/Magento2.4 native package cart sources. `setAttribution` (assignment/click persistence into the provider order) exists for WooCommerce through the plugin bridge (0.3.0+, `data-attribution-url`/`data-attribution-nonce`) and, opt-in, for Shopify (`data-persist-attribution="true"`); see docs/banners/ASSIGNMENT_BRIDGES.md. BigCommerce has none. Load it before `embed/v1.js` using ordered deferred scripts. It preserves an existing bridge. It never writes a cart, persists identifiers, scrapes prices or uses order webhooks for progress. No checkout attribution writer is added.

## WooCommerce

The candidate WooCommerce plugin (0.2.0) now emits the reader before the renderer when WooCommerce is installed. It supplies WordPress `rest_url('wc/store/v1/cart')`, including subdirectory/plain-permalink installations. It excludes native checkout and account pages, including customized slugs, before emitting either script. Reinstall the rebuilt candidate ZIP on the authorized test store to obtain this change.

For a custom authorized installation:

```html
<script defer src="https://YOUR_BACKEND/embed/native-cart-v1.js" data-platform="woocommerce" data-cart-url="https://YOUR_STORE/wp-json/wc/store/v1/cart"></script>
<script defer src="https://YOUR_BACKEND/embed/v1.js" data-shopbonus-key="YOUR_PUBLIC_KEY"></script>
```

Read source: session-bound GET Store API cart, `totals.total_price` (integer string in minor units), `currency_code`, `currency_minor_unit`. Total includes the provider's applied discounts, tax and shipping. Classic jQuery add/remove/cart-refresh events and Blocks add/remove DOM events invalidate the snapshot. Quantity, coupon, currency and custom theme mutations can explicitly notify after completion; polling remains the fallback. Custom REST prefixes need separate review; current explicit pretty URL validation accepts `/wp-json/`, or the native plain `rest_route` format. Headless/cart-token sessions are unsupported.

## BigCommerce

The native Widgets publisher (`shopbonus:banner-native install`, docs/banners/NATIVE_PUBLICATION.md) loads this reader before the renderer for new plans. B92 upgrades matching legacy plans in place when install is run again, or through the explicit `shopbonus:banner-native upgrade` command; no remove/reinstall cycle is required. For a manual installation, use the merchant-authorized Stencil storefront script placement, before the renderer:

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

The shared reader branches allow the same explicit two-decimal currencies as the existing Shopify reader: EUR, USD, GBP, CAD, AUD, NZD, CHF, SEK, NOK, DKK, PLN, CZK. Unknown precision/currency, negative/unsafe/malformed values, HTTP/JSON failure and timeouts invalidate progress. The renderer also requires campaign currency agreement. Poll every 10 seconds when visible; reads time out after 5 seconds; observation time is request start and expires after 30 seconds. Hidden pages and pagehide clear evidence; bfcache restoration refetches. In-flight invalidations discard old responses and coalesce a fresh read. Sensitive route segments, including localized prefixes and BigCommerce PHP account/checkout routes, are excluded. Custom storefront routing must also exclude sensitive pages at installation.

| Platform | Reader source | Installed-store acceptance |
|---|---|---|
| Shopify | Existing `shopify-cart-v1.js` | NOT RUN |
| WooCommerce | `native-cart-v1.js`, opt-in plugin integration | NOT RUN |
| BigCommerce Stencil | `native-cart-v1.js`, ordered merchant script installation | NOT RUN |
| Wix, Shopware, Mijnwebwinkel, CCV | No native reader shipped; retain truthful static fallback or an existing verified merchant bridge | NOT RUN / unsupported by these readers |
| PrestaShop9, OpenCart4.1.0.3, Magento2.4 / Adobe self-hosted/PaaS | B93 native package session source + shared reader (details below) | NOT RUN |
| Adobe Commerce SaaS / Edge Delivery storefront drop-ins | B94 `native-cart-v1.js` `data-platform="adobe-storefront"` + merchant `cart/data` bridge (below) | NOT RUN |
| Ecwid / Lightspeed E-Series, Lightspeed C-Series, Squarespace | No reader shipped for these environments; missing verified native source/currency/installation contracts (see below) | NOT RUN |

This does not promote platform capability/Connected status or prove payment, attribution, installation or reward eligibility. Server eligibility remains authoritative. Real merchant acceptance must record platform/theme versions and exercise add/remove/quantity/coupon/currency, empty cart, outages, sensitive pages, desktop/mobile, consent and uninstall. No live provider store was available in this task.

## Remaining native cart contracts (reviewed 2026-11-07)

A reader needs a documented storefront source for an exact cart total **and** its ISO currency. Without both it would have to guess units or currency, and a wrong guess shows progress 100× off or in the wrong currency. Earlier browser-object limitations for PrestaShop/OpenCart/Magento are superseded by B93 native server packages below. They do not imply support for other native versions or SaaS/headless storefronts.

| Platform | What the official docs provide | Missing for a reader |
|---|---|---|
| Ecwid / Lightspeed E-Series | `Ecwid.OnAPILoaded`, `Ecwid.OnCartChanged`, `Ecwid.Cart.calculateTotal(order)` with `order.total` "includes all costs, fees, taxes, and discounts" ([calculate cart details](https://docs.ecwid.com/storefronts/manage-cart-and-checkout/calculate-cart-details.md)) | The unit of `total` is not stated (example `"total": 3850`, prices `500`); no storefront currency code (only `Ecwid.formatCurrency()` strings); the ISO code is only in the server-side REST profile `formatsAndUnits.currency`. Needs Ecwid confirmation of units, or a live test store, plus a server-declared currency. |
| Lightspeed C-Series | Official [Rain variables](https://ecom-support.lightspeedhq.com/hc/en-us/articles/115000277594-3-b-Rain-variables) lists page.cart.total.price and shop currency/theme variables | No reviewed native JSON/session endpoint plus current presentment ISO currency/change contract is established. Do not turn a page variable or display conversion into a universal fresh cart reader. |
| Squarespace | Custom Code Spec documents only static DOM attributes; Commerce APIs are server-side orders, no carts ([developers](https://developers.squarespace.com/custom-code)) | Any supported storefront cart read or cart-changed event. |

On these platforms banners keep the truthful static fallback. Adding a reader requires the missing contract first; record its source here.

## Verification

- `node --test tests/embed-lifecycle.test.cjs`: 32 passed, including 11 new cases with actual reader/renderer execution, dummy provider responses, strict validation, race/timeout/lifecycle handling, stale/static fallback, plain WordPress routing and WooCommerce classic/Blocks notifications.
- `php -d xdebug.mode=off tests/woocommerce-banner.test.php`: isolated footer contract fixture, checks ordered output, native URL, disabled/unsafe settings and checkout/account exclusions. This is not WordPress acceptance.
- Merchant archives rebuilt using `scripts/build-merchant-packages.php`; archive/source/hash consistency checked with `MerchantPackageTest`.

Official provider contracts checked 2026-11-04: [WooCommerce Cart API](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/cart), [WooCommerce Blocks DOM events](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/dom-events/), [BigCommerce REST Storefront carts](https://docs.bigcommerce.com/developer/docs/admin/checkout-and-cart/custom-checkouts/rest-storefront). Local fixtures prove the implemented contract, not current merchant installation behavior.

## B93 — Native package session sources

No ShopBonus frontend application files were changed. Integration scripts and native PHP packages live in this backend repository. They are optional candidate assets; real framework installation/DI compilation, CSP/theme execution and shopper evidence remain NOT RUN.

All three sources return only `{schema:"shopbonus.cart-source.v1",platform,total_minor,currency,minor_unit:2}`. Amounts use the native grand total including the native current shipping/tax/discount choices, not order webhooks or theme price scraping. A shared package helper converts native major-unit decimals exactly, rejects sub-cent precision/negative/nonfinite/unsupported values, and requires64-bit PHP and the explicit existing two-decimal currency allowlist. The browser validates this schema/platform/units, retains only total/currency, timestamps the read and uses the existing invalidation/race/timeout/30-second expiry lifecycle. It performs same-origin cookie GETs with no-store/no redirects, no cart IDs, no customer data, no attribution writes and no backend telemetry for cart contents. Existing bridges remain untouched.

### PrestaShop9

Package0.2.0 loads the reader before the renderer in the enabled native footer hook, excluding native checkout/account controllers. A module GET at the current shop's `module/shopbonus/cart` (or native plain module routing) reads the actual context cart only. Its ID must match the native cookie and its shop/currency must match current context; catalog mode and price-hidden groups return no snapshot. It uses `Cart::getOrderTotal(true, Cart::BOTH)` and current currency ISO/precision. Missing carts/unsupported totals return409; non-GET returns405. Responses are private/no-store, vary Cookie and contain no identifiers. Query cart/customer IDs do not select another context. Native updateCart/updatedCart events invalidate/refetch; polling also covers custom themes. Module update and a theme with the native footer hook are required. Older PrestaShop versions are not claimed.

### OpenCart4.1.0.3

Package0.3.0 registers an after-event on the existing `common/cart.info` controller. It attaches a small `X-ShopBonus-Cart` header and private/no-store cache headers without changing the native HTML. The native total-extension pipeline runs for the current browser session, then OpenCart's own `currency->format(total, sessionCurrency, 0, false)` performs presentment conversion/rounding. Hidden guest prices, disabled bridge/banner and nondefault stores emit no source. The reader ignores the HTML body entirely and reads only this structured header. **No new shop/catalogue endpoint is introduced; D-110 signed six-hour catalogue snapshots are unchanged.** Deploy updated extension files and re-register install events as an authorized admin, retaining settings/outbox. Existing cart/page behavior is not replaced. Polling detects changes; themes may dispatch the established completion notification for immediate refresh. OpenCart3/4.0 are not claimed.

### Magento Open Source / Adobe Commerce self-hosted/PaaS2.4

Module0.2.0 adds a disabled-by-default store-view banner configuration, an ordered external-script layout block and private `shopbonus_cart` customer-data section through the **existing** `customer/section/load` native controller. It reads only the active checkout-session quote of the current store and current presentment currency, using native grand_total; no quote/customer ID selector exists. Cart values never enter public/full-page-cache HTML. Existing native customer-data invalidation mappings and add/remove notifications complement polling; checkout/customer/sales/payment pages emit no loader. Normal native module deployment/DI compilation/cache refresh and the merchant's CSP origin allowance are required. Disable the storefront flag before removing the module. This PHP package cannot run in Adobe Commerce SaaS or headless storefronts; no reader is claimed there.

Reviewed primary source contracts2026-11-07:

- PrestaShop9 native Cart: https://github.com/PrestaShop/PrestaShop/blob/9.0.0/classes/Cart.php ; Group visibility: https://github.com/PrestaShop/PrestaShop/blob/9.0.0/classes/Group.php ; native events: https://devdocs.prestashop-project.org/9/themes/reference/javascript-events/ .
- OpenCart4.1.0.3 native cart controller/model and currency conversion: https://github.com/opencart/opencart/blob/4.1.0.3/upload/catalog/controller/common/cart.php , https://github.com/opencart/opencart/blob/4.1.0.3/upload/catalog/model/checkout/cart.php , https://github.com/opencart/opencart/blob/4.1.0.3/upload/system/library/cart/currency.php .
- Magento2.4.8 native private cart section and DI registration: https://github.com/magento/magento2/blob/2.4.8/app/code/Magento/Checkout/CustomerData/Cart.php , https://github.com/magento/magento2/blob/2.4.8/app/code/Magento/Customer/etc/frontend/di.xml .

These are local native-source contracts, not provider acceptance. Squarespace's reviewed public Commerce/Custom Code docs and developer tools inventory do not establish a supported shopper cart-source interface; no private/undocumented endpoint or DOM price scrape is used. Ecwid's reviewed Cart.get/calculateTotal docs still do not establish a current ISO currency/units binding for this installation. See https://support.squarespace.com/hc/en-us/articles/41325887099533-Developer-Tools-APIs-at-Squarespace and https://docs.ecwid.com/storefronts/manage-cart-and-checkout/get-cart-details.md . Those remaining platform readers stay OPEN.

B93 executed evidence: `tests/Unit/Integrations/MerchantPackageTest.php` checks deterministic archive/source/hash identity, four isolated native-framework contract fixtures and the actual shared reader/renderer Node harness; native framework doubles are explicitly synthetic. Node48 PASS includes the three new platform paths, exact totals, malformed/mismatched/currency/precision failures, lost/stale responses, hidden/bfcache/sensitive routes, restricted URLs, native notifications and privacy. Package PHP syntax and Magento XML well-formedness pass. Full framework/storefront/provider/staging acceptance remains NOT RUN.

## B94 — Adobe Commerce SaaS/headless storefront drop-ins (2026-11-08)

Source contract (reviewed 2026-11-08): [Cart drop-in events](https://experienceleague.adobe.com/developer/commerce/storefront/dropins/cart/events/) — `cart/data` "emitted when cart data is available or changes", payload `CartModel | null`, subscribed via `events.on` from `/@dropins/tools/event-bus.js`; grand total `CartModel.total.includingTax: Price`. The page does not define `Price`; the same model documents prices as `{ currency: string; value: number }`, so the reader requires exactly a finite non-negative number with at most two decimals and an allowed ISO currency, and otherwise fails closed (no snapshot). Null/absent carts yield no snapshot; no zero is invented.

The event bus is an ES module, so the merchant storefront (e.g. `scripts/scripts.js`, registered **before** the cart drop-in initializes) forwards events; the reader never fetches or scrapes the DOM:

```html
<script defer src="https://YOUR_BACKEND/embed/native-cart-v1.js" data-platform="adobe-storefront"></script>
<script defer src="https://YOUR_BACKEND/embed/v1.js" data-shopbonus-key="YOUR_PUBLIC_KEY"></script>
```

```js
import { events } from '/@dropins/tools/event-bus.js';
events.on('cart/data', (cart) => window.dispatchEvent(new CustomEvent('shopbonus:adobe-cart-data', { detail: cart })));
```

The last pushed cart is re-stamped on visible refreshes (drop-in state stays current until its next `cart/data`); checkout/account pages are excluded as for other platforms. Unverified, explicitly NOT RUN: the `Price` shape against a live drop-in, the `eager` option and empty-cart payload shape (undocumented), and real storefront/CSP/currency-switch acceptance. Tests: `node --test tests/embed-lifecycle.test.cjs` 50 PASS (2 new: exact conversion, malformed/null/sub-cent/string/negative/unsupported-currency rejection, no fetch, sensitive-page exclusion).

Still OPEN, unchanged and intentionally not guessed: Ecwid/Lightspeed E-Series (unit of `order.total` and storefront ISO currency undocumented), Lightspeed C-Series (no reviewed JSON session/currency/change contract) and Squarespace (no public shopper cart source or change event).
