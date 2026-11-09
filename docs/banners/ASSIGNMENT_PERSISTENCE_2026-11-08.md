# Assignment persistence follow-up — 2026-11-08

PARTIAL across the platform list; local BigCommerce and Ecwid/E-Series implementations added. No genuine provider installation/checkout acceptance has run. Neither metadata parsing nor these synthetic tests certify a working installed integration.

## BigCommerce Stencil

The documented native order `cart_id` is read-only and correlates with the Cart API: [Get Order](https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/get-order), [List Orders field definition](https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/get-orders). [Cart conversion](https://docs.bigcommerce.com/developer/docs/integrations/webhooks/event-reference/events) deletes the live cart. The bridge records the assignment while the cart exists, then resolves it from the later authenticated order read. It never accepts an order ID or payment state from the browser and never writes a fabricated native order metadata field.

1. Deploy migration `2026_11_08_100000_create_banner_cart_assignments` through the normal deployment workflow. Only isolated test databases were migrated here.
2. Grant the existing server connection Carts read access alongside its existing order/store scopes. Set `BIGCOMMERCE_CART_ASSIGNMENTS_ENABLED=true` in the authorized test environment after migration. It defaults false.
3. Load `native-cart-v1.js` with `data-platform="bigcommerce" data-persist-attribution="true"` before `v1.js`. Existing approved public key, origin/CORS and CMP setup remain required. The default reader has no assignment context without the opt-in attribute.

The reader exposes a current single native cart UUID through `getAssignmentContext`. The embed includes optional `cart_context: {platform: "bigcommerce", cart_id: UUID}` on the existing consent-validated assignments request. A cart created after initial assignment triggers revalidation. Both holdout and variant follow the same path. No cart UUID is sent to the assignment API without recorded tracking permission.

The server verifies the active single connection, exact owned store/domain and live cart through the existing pinned authenticated transport, then associates the cart with the first server-issued assignment, matching the embed's existing first-assignment priority. It stores only HMAC cart identity, assignment ID, connection and credential generation. A native cart UUID is a bearer capability, not customer identity. Public origin checks are the existing browser boundary, not proof against an attacker who already knows another cart's UUID. Do not log raw cart IDs or expose management credentials in storefront scripts.

Repeated requests keep the original creation time and assignment. Conflicts return409; they never overwrite another visitor's binding. Rotation, changed store/domain, pause/disconnect, revoked/expired assignments, wrong cart and orders predating binding resolve to null. Optional existing provider metafields must agree with the bridge if present. Paid/refund evidence and reporting windows are unchanged. No native order metafield write is required or claimed. Legacy metafield reads remain a separate opt-in. If binding fails, the embed retries assignment alone and emits `shopbonus:attribution-error` with `cart_assignment_unavailable`; it preserves stable participation and retries binding at the next consent refresh. This fallback does not claim order linkage.

## Ecwid / Lightspeed E-Series

Install `/embed/ecwid-attribution-v1.js` after the native Ecwid loader exposes `OnAPILoaded` and before `/embed/v1.js`, through the authorized native app's `customJsUrl` bootstrap or merchant-controlled storefront. Scripts must execute in that order. Deploy updated `v1.js` too: its attribution event includes the exact current consent lease deadline.

The writer uses [documented hidden extra fields](https://docs.ecwid.com/api-reference/rest-api/checkout-extra-fields/add-checkout-extra-fields-with-javascript): `ec.order.extraFields.shopbonus_assignment_token = {value: TOKEN, orderDetailsDisplaySection: "hidden"}` followed by `Ecwid.refreshConfig()`. It sets no checkout section/title/input, sends no browser management API request and preserves merchant fields. Existing native order extraction reads this reserved field. Late native API readiness applies only the latest current value. Invalid/missing assignment, CMP change, withdrawal, page exit or the current consent deadline removes it. No local browser storage is added. Native cart totals remain unsupported for these platforms; this is an attribution writer only.

## Still open

| Platform | Remaining boundary |
| --- | --- |
| CCV Shop | Official [REST v1 order schema](https://demo.ccvshop.nl/API/Schema/vnd.verto.webshop.resource.orders.v1.json) was retrieved and reviewed. It does not define a dedicated hidden assignment field. `bannerAssignmentToken` stays null; shopper reference/findus and invented metadata do not supply it. Need authoritative partner/session-to-order binding before implementing extraction. |
| Lightspeed C | Embedded order-metafield reader remains; no storefront-to-order writer installed. Need native cart/session-to-order binding and authorized metafield writer, with recovery when embedding is absent. |
| Squarespace | Removed shopper-visible `formSubmission` extraction. Orders/fulfillment APIs do not establish hidden checkout-write authority. No hidden bridge is implemented. |
| Shopware, Magento, Wix, PrestaShop, OpenCart, Mijnwebwinkel | Existing readers or signed bridge contracts do not supply missing native storefront writers. Those writers remain unimplemented; Magento SaaS is a distinct contract. No blanket completion claim. |

## Validation

Commands: `node --test tests/ecwid-attribution.test.cjs tests/embed-lifecycle.test.cjs`; focused PHPUnit Banners/assignment/remaining/Squarespace suites; integration/Banners/OpenAPI regression; changed application PHPStan and Pint. Executed counts/limits are recorded in HANDOFF/PROGRESS at closeout.

`scripts/capture-cart-assignments.php` captures seven actual loopback Laravel responses with isolated synthetic SQLite and mocked native BigCommerce transport/DNS, then removes its database/router/process/log. [Captured request/response evidence](../api/CART_ASSIGNMENT_HTTP_CAPTURE.json) covers binding/repeat200, conflict409, foreign-origin403, invalid-cart422, native-identity502 and withdrawal200. This is not provider acceptance.

Before enabling on a merchant store, execute no-consent absence; grant; both randomized arms including no-banner holdout; create/change/empty/recreate cart; reload; native checkout order; genuine paid/refund updates; replay; withdrawal; expiry; cart conversion during binding; rotation/pause/uninstall; source/report reconciliation. Missing propagation stays unattributed. No winner/capability verification flags are promoted.
