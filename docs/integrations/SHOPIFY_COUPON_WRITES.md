# B91 — Opt-in Shopify discount writes

Backend operator workflow implemented locally, default OFF. This is not a new merchant generation HTTP API, an automatically installed Shopify app, or proof of checkout acceptance/redemption. D-109 (owner2026-11-05) approves per-offer opt-in, not unrestricted store discount administration. No frontend changes.

## Deployment and supported terms

Apply migration `2026_11_07_190000_create_shopify_coupon_operations` through normal deployment. It creates one current opt-in per offer plus durable encrypted operation intents. No migration was applied to the application database during development. Preserve the application encryption key in recovery procedures: both intent codes/input and normal inventory codes depend on it.

`SHOPIFY_COUPON_WRITES_ENABLED=false` is the default. Setting it true permits fixed native writes and adds `write_discounts` to new browser OAuth requests. Existing merchant grants must be reauthorized; changing this flag does not grant provider access. Default OAuth remains `read_orders,read_products`; automatic scope escalation is not attempted. An installed app/token, exact verified store domain and live `write_discounts` proof are required. Shopify GraphQL version remains pinned to `2026-10`; the existing DNS-pinned, no-redirect, bounded transport is reused.

The supported subset is unique, single-use, store-wide basic percentage (integer basis points, max10000) or fixed amount (integer minor units), a two-decimal configured currency matching the actual store, optional minimum subtotal, no combinations, and explicit absolute start/end timestamps. Product/category targeting, free shipping, relative claim/redemption validity, maximum-discount caps, recurring/subscription discounts and reusable codes are rejected before mutation. Future-start codes remain unimportable until active. Native reads must establish all restrictions, exactly one matching code, unused count0, usage limit1, exact dates and the frozen discount/currency/minimum. Creation alone never establishes checkout acceptance.

## Authorized operations

Commands run on the backend host as an operator acting for a current merchant. `--actor` is the public ID of an active user with active membership and **all three** `offers.manage`, `vouchers.manage`, `integrations.manage` permissions in that offer's workspace. A Super Admin label alone does not bypass membership. Each call revalidates current authority, pause/disconnect, verified domain, credential generation and version bindings. Foreign resources return concealed404; insufficient membership grants return403.

Use actual public IDs in place of the placeholders:

```sh
php artisan shopbonus:shopify-coupon enable OFFER --connection=CONNECTION --actor=MERCHANT
php artisan shopbonus:shopify-coupon prepare OFFER --connection=CONNECTION --actor=MERCHANT --request-key=stable-request-0001
php artisan shopbonus:shopify-coupon create OPERATION --actor=MERCHANT
php artisan shopbonus:shopify-coupon reconcile OPERATION --actor=MERCHANT
php artisan shopbonus:shopify-coupon import OPERATION --actor=MERCHANT
php artisan shopbonus:shopify-coupon deactivate OPERATION --actor=MERCHANT
php artisan shopbonus:shopify-coupon disable OFFER --connection=CONNECTION --actor=MERCHANT
```

Enabling freezes the reviewed offer version. Subsequent term changes create a new version and require fresh opt-in. Preparing generates a cryptographically random code inside an encrypted immutable intent, but sends no provider request. The same offer/request key returns the same operation. Command output contains the operation ID, state, remote ID and imported flag, never the code/input/token. Consumers receive codes only through existing authorized inventory/issuance flows.

Creation first checks collision/currency, commits `create_unknown` plus audit before sending exactly one fixed mutation, then reads the code back. Repeating create never sends another mutation. A lost response leaves a durable uncertain intent; use **reconcile**, which performs reads only. It may bind a remote ID only when the exact random code and operation-specific `ShopBonus OPERATION` title are observed. A missing/foreign resource is not adopted. Do not reset uncertain rows or switch request keys to retry an uncertain operation.

`repair OPERATION` reapplies that operation's frozen terms using `discountCodeBasicUpdate`, only to its owned, unpublished discount. It is not an arbitrary discount editor. It writes `update_unknown` first and performs fresh readback afterwards. An uncertain update cannot be blindly repeated; reconcile first. An incorrect/unsupported/used provider configuration stays outside inventory. Deleting or manually changing ownership markers is an operator-recovery condition, not permission to adopt another discount.

Import performs a fresh native comparison, rechecks current version/grant/opt-in under row locks and inserts one encrypted unique inventory code plus immutable event/audit references. Generated codes cannot be allocated under a different offer version. After import, repair is prohibited. Reconciliation of changed native terms marks validation mismatch and closes any still-available code; issued/reserved vouchers retain their status for explicit support/recovery.

Deactivation verifies remote ownership, rejects reserved/issued/redeemed/used inventory, closes unused inventory under a row lock **before** the external mutation and records `deactivate_unknown`. No automatic repeat follows a timeout; reconciliation requires the owned discount to report EXPIRED. Disabled per-offer generation still permits authorized cleanup, but the deployment write gate must remain enabled. Deactivating an already confirmed operation is idempotent. No provider deletes or customer/payment mutations exist.

## Evidence and remaining acceptance

Focused native transport tests use synthetic HTTP responses, including provider identity/scopes, exact terms, foreign actors, suspensions, grant rotation, pause, duplicate keys, timeout reconciliation, import, drift and cleanup. Real loopback API captures in `docs/qa/B91_SHOPIFY_CAPABILITIES_HTTP_CAPTURE.json` establish that platform/connection capability responses describe this as **LIMITED**, not connection-wide verified. They do not execute genuine provider writes.

Genuine installed-store acceptance is **NOT RUN**: authorize `write_discounts`, generate percentage and fixed codes, demonstrate actual checkout acceptance and single-use enforcement, payment/refund binding where required, uncertain-response recovery, cleanup and scope revocation. Record deployment SHA, provider request IDs and independent evidence before promoting a provider capability. No production configuration, provider activation, wallet accounting or financial policy was changed.

Sources reviewed2026-11-07:

- https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/discountCodeBasicCreate
- https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/discountCodeBasicUpdate
- https://shopify.dev/docs/api/admin-graphql/2026-10/mutations/discountCodeDeactivate
- https://shopify.dev/docs/api/admin-graphql/2026-10/input-objects/DiscountCodeBasicInput
- https://shopify.dev/docs/api/admin-graphql/2026-10/enums/DiscountBuyerSelection

## Other requested platforms

OpenCart owner decision D-110 was reconfirmed2026-11-07: retain the approved six-hour signed worker snapshot architecture, no live catalogue endpoint on merchant shops.

Ecwid/Lightspeed E-Series still requires a registered native app with `customize_storefront` and reviewed `customJsUrl`. App settings are registered capabilities; an order-reading token does not install that app or prove ownership of its app-specific storage. The reviewed public storage write API (`PUT /api/v3/{storeId}/storage/{key}`, `update_store_profile`) is not a script-installation API. Automatic installation/publication and cleanup remain OPEN; no undocumented registration/write endpoint was invented. See https://docs.ecwid.com/develop-apps/app-settings and https://docs.ecwid.com/api-reference/rest-api/application/update-specific-app-storage-data .

Squarespace remains merchant Code Injection/manual installation. The reviewed public Commerce API and Custom Code specification do not establish a remote Code Injection write contract; automatic publication/cleanup remains OPEN. See https://developers.squarespace.com/commerce-apis/overview and https://developers.squarespace.com/custom-code/about . Setup/readiness explanations are localized by B90.
