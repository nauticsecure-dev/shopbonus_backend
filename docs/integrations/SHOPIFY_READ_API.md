# Shopify read API — B23b / #31

This checkpoint extends the existing Shopify webhook connector and the existing managed API test route. It implements native reads, not OAuth installation, token refresh, discount writes, banner publication, automatic ingestion or full connection readiness. No live store has been verified; `last_live_verified_at` remains null. The senior developer configures approved credentials locally. Existing webhook-only connections remain valid without an access token.

An optional `access_token` is encrypted with the existing connection credentials. Creation and credential replacement still require `shop_domain` and `signing_secret`. Supplied credentials are never returned. An app token is sent only in `X-Shopify-Access-Token` to the exact configured `*.myshopify.com` host over pinned HTTPS at `/admin/api/2026-10/graphql.json`. The response version header must match the reviewed version. The shared transport uses public-address DNS checks, address pinning, verified TLS, no redirect/proxy/retry, an eight-second deadline and a 1 MiB response cap. HTTP 429 and GraphQL HTTP-200 `THROTTLED` errors pause credential-bound requests; network/server failures open a bounded circuit. cURL is now an explicit Composer platform requirement.

Before order reads, a fixed identity query verifies `shop.myshopifyDomain`, the workspace's verified domain against `shop.primaryDomain.host` or its canonical Shopify domain, and `currentAppInstallation.accessScopes`. Orders require `read_orders` (or its granted write equivalent); basic product metadata requires `read_products`. Scopes do not prove protected customer access or historical-order access. Current token permissions and Shopify's order access window constrain available records. A null order or denied financial/schema fields fail explicitly. Only exact protected customer field denial paths may yield incomplete order data; denied values are scrubbed even if a partial response supplies them.

| Canonical field | GraphQL source / interpretation |
| --- | --- |
| Store | Identity query `shop.myshopifyDomain` plus verified workspace domain |
| External order / reference | `legacyResourceId` / `name`; single lookup also checks exact `id` GID |
| First name / email / phone | `billingAddress.firstName` / `email` / `phone` or billing phone; denied/missing stays null |
| Gross amount / currency | `totalPriceSet.shopMoney.amount` / `currencyCode`, exact integer minor units |
| Refunded amount | `totalRefundedSet.shopMoney.amount`; same currency, nonnegative and no greater than gross |
| Payment state | `displayFinancialStatus`, `fullyPaid` and cancellation; authorization alone remains pending |
| Paid / refund time | Successful `SALE`/`CAPTURE` or `REFUND` transaction `processedAt`; never order creation/processing time |
| Provider update / order time | `updatedAt` / `createdAt` |
| Codes / attribution | `discountCodes`; validated `customAttributes` Shopbonus identifiers |

The gross amount deliberately uses total before returns. Existing analytics subtract the refunded amount once; using `currentTotalPriceSet` would double-subtract refunds. No payment/contact/wallet effect is triggered by these reads. Line-item mapping, SKU/quantity/eligible amounts and durable event identity/recovery remain later #31 work; basic product reads return only ID/title/handle/update time, not a completed catalog import.

`fetchOrder` performs identity and a fixed order query, requesting 101 transactions as lookahead and accepting at most 100 inside the body limit; larger histories fail explicitly and require future background reconciliation. `syncOrders` reads at most five pages of 25 orders using a fixed UTC upper timestamp, an updated-at filter and `UPDATED_AT` sort. Repeated/invalid cursors or an unfinished backlog fail explicitly. This primitive does not persist cursors/watermarks or ingest partial pages. A bounded product metadata page exposes pagination to internal callers only. No caller can submit GraphQL, an arbitrary endpoint or a mutation.

`POST /api/v1/workspaces/{workspace}/connections/{connection}/test-api` reuses existing active/verified account, workspace integration-manage permission, support-session write guard, CSRF, scoped lookup, 3/minute throttle and chained audit. Empty input observes identity/scopes; `{"order_id":"4711"}` returns a PII-free normalization summary. Every successful probe reports `state_changed:false` and `production_verified:false`. A successful read does not promote a connection or prove all seven #31 gates. Legacy signed-webhook status behavior remains compatible and must be replaced by credential-bound gate evidence in a later boundary.

Evidence: [11 actual HTTP captures](B23_SHOPIFY_HTTP_CAPTURE.json), the API reference, and `tests/Feature/Integrations/ShopifyApiTest.php`. Provider HTTP/DNS in these checks is synthetic; real Shopify OAuth/order/webhook/protected-data/storefront acceptance is NOT RUN. WooCommerce transport regressions run alongside Shopify tests. No migration or provider activation.

Official sources reviewed 2026-10-28:

- [Order fields and access](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/Order)
- [Shop identity](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/shop)
- [Granted app scopes](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/AppInstallation)
- [Order transactions](https://shopify.dev/docs/api/admin-graphql/2026-10/objects/OrderTransaction)
- [Order search and pagination](https://shopify.dev/docs/api/admin-graphql/2026-10/queries/orders)
- [Version response headers](https://shopify.dev/docs/api/usage/versioning)

Next boundaries: OAuth lifecycle and credential-bound seven-gate readiness; durable missed-event/order/refund reconciliation through the canonical ingestion pipeline; detailed product mappings; remaining native providers. Real installations and storefront/banner modules remain outside this Laravel-only implementation.
