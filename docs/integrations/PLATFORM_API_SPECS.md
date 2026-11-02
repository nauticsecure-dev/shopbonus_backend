# Top 10 commerce platform API & webhook specifications

Reviewed **2026-10-30**. Extends `ConnectorRegistry` / `ConnectionManager` / `OrderIngestion` / catalogue capabilities. No parallel integration architecture.

**Capability registry:** each platform `profile` in `config/integrations.php` holds `api_version`, `auth_method`, `scopes`, `webhook_topics`, `order_api`, `refund_api`, `product_api`, `coupon_api`, `banner_method`, `rate_limit_policy`, `reviewed_at`, `last_live_verified_at`, `sources`.

**Connection quality:** `CONNECTED` only after seven native gates in `ConnectionReadiness`. Otherwise `LIMITED`, `SETUP_REQUIRED`, or `ERROR`. Live `verification` stays `unverified` until merchant-authorized evidence is recorded in [VERIFICATION.md](VERIFICATION.md).

**Shared mapping targets**

| ShopBonus field | Meaning |
| --- | --- |
| `shop_id` | Provider store identity (`external_shop_ref`) |
| `external_order_id` | Provider order ID |
| `order_reference` | Human-readable reference |
| `customer_first_name` | Authorized first name |
| `customer_email` | Authorized email |
| `customer_phone` | Optional; null when missing |
| `total_minor` | Normalized amount |
| `payment_status` | Verified provider status |
| `refund_minor` | Verified refund total |
| `products[]` | ID, SKU, quantity, eligible amount |
| `source_event_id` | Provider event / dedupe identity |
| `event_created_at` | Provider timestamp |

`null` = unavailable/incomplete. `[]` = observed empty. Never invent cashback from order existence alone.

---

## 1. Shopify

| Item | Value |
| --- | --- |
| Version | Admin GraphQL `2026-10` |
| Install | Merchant-authorized app; optional browser OAuth (`SHOPIFY_OAUTH_ENABLED`) |
| Auth / scopes | `read_orders`, `read_products`; webhook HMAC with app/signing secret |
| Docs | https://shopify.dev/docs/api/admin-graphql · https://shopify.dev/docs/apps/build/webhooks · https://shopify.dev/docs/apps/build/online-store/theme-app-extensions |
| Orders | `order` / `orders` GraphQL queries |
| Refunds | `totalRefundedSet` + successful REFUND transactions (no refund mutation) |
| Products | GraphQL products (bounded); coupons **UNSUPPORTED** |
| Webhooks | `orders/create`, `orders/updated`, `orders/paid`, `orders/cancelled`, `refunds/create`, `app/uninstalled` |
| Signature | Base64 HMAC-SHA256 over **raw body**; dedupe delivery/event IDs |
| Banners | Theme App Extension (App Blocks/Embed); not implemented — manual embed LIMITED |
| Status | Adapter **PARTIAL**; live **NOT RUN** |

**Field mapping (GraphQL order)**

| Target | Path | Notes |
| --- | --- | --- |
| shop_id | shop domain | Bound at OAuth/connect |
| external_order_id | `id` / legacy numeric | |
| order_reference | `name` | |
| customer_first_name | `customer.firstName` / billing | May be redacted without protected customer data |
| customer_email | `email` | May be redacted |
| customer_phone | billing/customer phone | Optional |
| total_minor | `totalPriceSet.shopMoney` | Shop-money basis |
| payment_status | displayFinancialStatus + transactions | |
| refund_minor | successful refunds | |
| amount_breakdown | `totalPriceSet` (original), `currentTotalPriceSet` (after returns), `totalTaxSet`, `totalDiscountsSet`, `taxesIncluded` | Same shop currency only; shipping null (`totalShippingPriceSet` is documented as shipping returned to the customer). Verified against the Admin GraphQL Order reference 2026-10-31 |
| products[] | `lineItems(first: 100)` `discountedTotalSet(withCodeDiscounts: true)` | |
| source_event_id | `X-Shopify-Event-Id` | |
| event_created_at | `updatedAt` / webhook | |

Redacted sample: `{ "id": "gid://shopify/Order/1", "name": "#1001", "email": "[redacted]", "totalPriceSet": { "shopMoney": { "amount": "25.00", "currencyCode": "EUR" } }, "displayFinancialStatus": "PAID" }`

---

## 2. WooCommerce

| Item | Value |
| --- | --- |
| Version | REST `wc/v3` |
| Install | Restricted consumer key/secret or dedicated plugin |
| Auth | HTTPS Basic; webhook `X-WC-Webhook-Signature` HMAC-SHA256 base64 |
| Docs | https://developer.woocommerce.com/docs/apis/rest-api/v3/ · orders · webhooks |
| Endpoints | `GET /wp-json/wc/v3/orders[/{id}]`, `/products`, `/coupons`, `/orders/{id}/refunds` |
| Webhooks | `order.created`, `order.updated` (+ refund via updated status) |
| Banners | WP block/plugin preferred; `publishBanner` → `manual_embed` |
| Status | CatalogCapable **WORKING**; live **NOT RUN** |

| Target | Path |
| --- | --- |
| shop_id | store URL host |
| external_order_id | `id` |
| order_reference | `number` |
| customer_first_name | `billing.first_name` |
| customer_email | `billing.email` |
| customer_phone | `billing.phone` |
| total_minor | `total` + `currency` |
| payment_status | `status` / `date_paid` |
| refund_minor | `/refunds` totals |
| source_event_id | `X-WC-Webhook-Delivery-ID` |
| products[] | `line_items[].{id,sku,quantity,total}` |

---

## 3. Wix eCommerce

| Item | Value |
| --- | --- |
| Version | eCommerce Orders v1 (not obsolete Stores Orders) |
| Install | App authorization / site-owner token |
| Auth | RS256 JWT (RSA≥2048) + API token; bind instance/site |
| Event | `wix.ecom.v1.order_created` (+ updated/paid/canceled where subscribed) |
| Docs | https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/introduction · order-created · webhooks |
| Orders | `GET https://www.wixapis.com/ecom/v1/orders/{uuid}` |
| Transactions | `GET .../ecom/v1/payments/orders/{uuid}` |
| Banners | App/Blocks/Velo only; no silent HTML |
| Status | Native lookup **WORKING**; backfill via `POST /ecom/v1/orders/search`; `paid_at` null by design |

Webhook fields: `eventType`, `instanceId`, `entityId`, `eventTime`, `id`, `slug`.

| Target | Path |
| --- | --- |
| shop_id | `instanceId` |
| external_order_id | order `id` (UUID) |
| order_reference | `number` |
| customer_first_name | `billingInfo.contactDetails.firstName` |
| customer_email | `buyerInfo.email` |
| customer_phone | `billingInfo.contactDetails.phone` |
| total_minor | price summary + currency |
| payment_status | transactions mapping |
| amount_breakdown | `priceSummary.{total,tax,shipping,discount}.amount` (current total not mapped) |
| products[] | `lineItems[].{id, quantity, catalogReference.catalogItemId, physicalProperties.sku, totalPriceBeforeTax.amount}` (after discounts, before tax). Verified against the Wix eCom Order object 2026-10-31 |
| source_event_id | JWT / event `id` |

---

## 4. BigCommerce

| Item | Value |
| --- | --- |
| Version | Orders v2; transactions/refunds v3 |
| Install | OAuth app or store token |
| Auth | `X-Auth-Token`; Standard Webhooks HMAC over `id.timestamp.rawbody` |
| Docs | webhooks overview · event reference · widgets |
| Topics | `store/order/created`, `updated`, `statusUpdated`, `refund/created`, `transaction/created` |
| Sample | `{"scope":"store/order/created","producer":"stores/abc123","data":{"type":"order","id":250}}` |
| Important | `created` may be unpaid — fetch order + capture proof before reward |
| Banners | Widgets API; publish not implemented |

| Target | Path |
| --- | --- |
| shop_id | `store_hash` / producer |
| external_order_id | `order.id` |
| customer_* | `billing_address.*` |
| total_minor | `total_inc_tax` when currencies match |
| payment_status | successful non-test capture/purchase txs |
| refund_minor | `refunded_amount` + refund resources |
| source_event_id | `webhook-id` |

---

## 5. Ecwid

| Item | Value |
| --- | --- |
| Version | API v3 |
| Auth | `X-Ecwid-Webhook-Signature` = base64 HMAC of `eventCreated.eventId` with **app client_secret**; Bearer store token for reads |
| Scopes | `read_orders`, `read_store_profile` |
| Topics | `order.created`, `order.updated` |
| Flow | notification → authenticated order lookup → normalize |
| Docs | https://docs.ecwid.com/ · webhook automations · get-order |
| Money | **LIMITED** — no immutable order currency / settlement time; amounts stay null |
| Banners | Storefront customization or manual placement |

| Target | Path |
| --- | --- |
| shop_id | `storeId` |
| external_order_id | `internalId` |
| order_reference | `vendorOrderNumber` / `orderNumber` |
| customer_* | `billingPerson` / `email` |
| payment_status | `paymentStatus` enum map |
| total_minor / currency | **null** (unavailable) |
| source_event_id | `eventId` |

---

## 6. Lightspeed eCom C-Series

| Item | Value |
| --- | --- |
| Version | C-Series REST (clusters `eu1` / `us1`) |
| Plan | Advanced or Professional required for API |
| Auth | HTTP Basic `api_key:api_secret` |
| Webhook sig | App webhooks: `x-signature` = `md5(raw_body + api_secret)` (documented); unsigned merchant-direct hooks refused |
| Docs | https://ecom-support.lightspeedhq.com/hc/en-us/articles/220320568-API-documentation · https://developers.lightspeedhq.com/ecom/introduction/authentication/ · order · shop · webhooks |
| Orders | `GET /{lang}/orders/{id}.json`, list with `updated_at_min/max` |
| Shop identity | `GET /{lang}/shop.json` → `id`, `mainDomain`, `currency.shortcode` |
| paymentStatus | `not_paid\|partially_paid\|paid\|cancelled` |
| Topics | orders created/updated/paid/deleted; invoices/returns created/updated |
| Banners | Theme/script; automatic publish not implemented |
| Not used | Retail, E-Series, confirmation-page tracking |
| Status | Connector **IMPLEMENTED**; refund cash **UNSUPPORTED**; `paid_at` null; live **NOT RUN** |

| Target | Path |
| --- | --- |
| shop_id | shop `id` / `x-shop-id` |
| external_order_id | `order.id` |
| order_reference | `order.number` |
| customer_first_name | `order.firstname` / `firstName` |
| customer_email | `order.email` |
| customer_phone | `order.phone` / `mobile` |
| total_minor | `order.priceIncl` + **shop** `currency.shortcode` (order has no currency field) |
| payment_status | `order.paymentStatus` |
| refund_minor | **null** (no documented refunded enum/cash path in this boundary) |
| source_event_id | `x-shop-id` + `x-signature` + topic hash |
| event_created_at | `order.updatedAt` / `createdAt` |

Redacted sample: `{ "order": { "id": 1623550, "number": "ORD-1001", "email": "[redacted]", "firstname": "Ada", "priceIncl": "50.35", "paymentStatus": "paid", "createdAt": "2026-10-28T09:00:00+00:00" } }`

---

## 7. PrestaShop

| Item | Value |
| --- | --- |
| Version | PS 9 Webservice JSON + Shopbonus module bridge |
| Install | Signed ZIP module (not shipped in Laravel backend) |
| Hooks | `actionValidateOrderAfter` + status/refund hooks — validation ≠ settled payment |
| Auth | Shopbonus HMAC v1; optional webservice Basic key |
| Docs | https://devdocs.prestashop-project.org/9/modules/introduction/ · hooks · webservice orders |
| Banners | Display hooks via module |
| Status | Optional native read **WORKING**; module ZIP **MISSING** |

| Target | Path |
| --- | --- |
| external_order_id | `order.id` |
| order_reference | `order.reference` |
| customer_* | customer/address resources |
| total_minor | order total + currency ISO |
| payment_status | payments vs credit slips (slip ≠ cash) |

---

## 8. OpenCart

| Item | Value |
| --- | --- |
| Version | Shopbonus webhook v1 bridge; OC 3 vs 4 packaging differ |
| Install | Version-specific extension / `.ocmod.zip` when required |
| Native API | Core admin order history API **not assumed**; catalog `api/order` is checkout-session oriented |
| Auth | Shopbonus HMAC v1 |
| Docs | https://docs.opencart.com/developer-guide/extensions |
| Banners | Module/events; OCMOD only if events insufficient |
| Status | Bridge **AVAILABLE**; native read **UNSUPPORTED**; cannot pass seven-gate Connected without native_api evidence |

---

## 9. Shopware 6

| Item | Value |
| --- | --- |
| Version | Admin API + optional Shopbonus module bridge |
| Install | App `manifest.xml` / private extension (native app lifecycle separate) |
| Auth | client_credentials Admin API; inbound Shopbonus HMAC today |
| Docs | https://developer.shopware.com/docs/guides/plugins/apps/app-base-guide.html · webhook · manifest |
| Orders | `POST /api/search/order` (+ state-machine-history for paid_at) |
| Banners | CMS/app extension |
| Status | Optional native read **WORKING**; native app signatures **NOT** this bridge |

Example manifest webhook shape (upstream):  
`<webhook name="…" url="https://shopbonus.example/webhook" event="product.written"/>` — order-state event names must be taken from current webhook reference before subscribe.

---

## 10. Adobe Commerce / Magento

| Item | Value |
| --- | --- |
| Version | 2.4 self-hosted/PaaS REST (SaaS not substituted) |
| Install | Compatible module/app for deployment model |
| Auth | Integration bearer + Shopbonus HMAC bridge |
| Prefer | Adobe I/O Events (async). **Do not** use synchronous Commerce Webhooks for slow ShopBonus ingestion |
| Docs | https://developer.adobe.com/commerce/extensibility/events/ · webhooks · REST |
| Orders | `/V1/orders/{id}`; invoices/captures/creditmemos for payment/refund proof |
| Banners | Approved module/storefront component |
| Status | Optional native read **WORKING**; async app delivery / SaaS **PENDING** |

---

## Ten E2E targets vs evidence

| # | Target | Fixture / code evidence | Live |
| --- | --- | --- | --- |
| 1 | Shopify OAuth, paid order, webhook, banner | `ShopifyOAuthTest`, `ShopifyApiTest` | NOT RUN |
| 2 | WooCommerce REST, signed event, products, refunds | `WooCommerceApiTest`, `ConnectionsAndWebhooksTest`, `CapabilityAndCatalogTest` | NOT RUN |
| 3 | Wix JWT, order lookup, dedupe | `WixConnectorTest` | NOT RUN |
| 4 | BigCommerce unpaid≠reward; paid update | `BigCommerceConnectorTest` | NOT RUN |
| 5 | Ecwid token, webhook, lookup, recovery | `EcwidConnectorTest`, `OrderRecoveryTest` | NOT RUN |
| 6 | Lightspeed plan/API + honest LIMITED money/refund | `LightspeedCConnectorTest`, catalogue profile | NOT RUN |
| 7 | PrestaShop ZIP, paid update, banner, uninstall | Backend contract + `PrestaShopConnectorTest`; ZIP external | NOT RUN |
| 8 | OpenCart extension, status event, uninstall | Bridge + profile LIMITED; extension external | NOT RUN |
| 9 | Shopware manifest, webhook, order, capabilities | `SelfHostedReadMappingTest`; native app external | NOT RUN |
| 10 | Adobe async event, refund, no checkout block | `MagentoConnector` + docs policy; live NOT RUN | NOT RUN |

---

## Explicit non-goals in this boundary

- Coupon **writes** on any platform
- Native storefront banner auto-publish (all LIMITED / manual)
- Invented Lightspeed Retail/E-Series or OpenCart admin order REST
- Marking `verification: verified` without merchant-authorized evidence
