# Frank handoff — App Store backend gaps (Hassan FE shipped)

**Date:** 2026-10-30  
**Owner (UX):** Hassan · **Owner (Laravel):** Frank · **Priority:** P1  
**FE surface:** Merchant Dashboard → **App Store** (`/dashboard/app-store`)  
Legacy `/dashboard/webshops` redirects to App Store.

Hassan shipped Discover Apps | My Connections | Services, capability badges, Shopify OAuth start UI, health/catalog/events wiring, Connect / Manage / Reconnect / Disconnect, and employee App Store lockout (role `employee`).  
**No platform is live-verified.** Do not mark CONNECTED/PASS from UI alone.

---

## Contract mapping (do not invent a parallel engine)

| Spec name | Existing code |
|-----------|---------------|
| `startInstall` | `Connector::connect` + `ShopifyOAuth::start` |
| `verifyConnection` | `testConnection` + `POST …/verify` (7-gate readiness) |
| `listOrders` | `syncOrders` / `PagedOrderReader::discoverOrders` |
| `verifyWebhook` | `verifyIncomingEvent` |
| `listProducts` / `listCoupons` | `CatalogCapable::getProducts` / `getCoupons` |
| `installOrPublishBanner` | Woo `publishBanner` stub only |
| `reconnect` | `ConnectionManager::replaceCredentials` (+ Shopify OAuth) |
| `disconnect` | `Connector::disconnect` / manager disconnect |

Reuse: `ConnectorRegistry`, `ConnectionManager`, `ConnectionReadiness`, `OrderIngestion`, `OrderEligibility`, wallet, banners, Answer24, Order Sheet broadcast.

---

## Capability matrix — required ten

Legend: **Partial** = adapter registered, live `verification: unverified`.  
**Blocked** = cannot PASS until dependency exists. Never auto-green.

| Platform | Adapter | Orders/pay | Catalog | Coupons | Banner | Webhook reg. | Module/ZIP | Live E2E | Frank action |
|----------|---------|------------|---------|---------|--------|--------------|------------|----------|--------------|
| Shopify | `ShopifyConnector` | GraphQL Partial | API exists; **not** CatalogCapable | UNSUPPORTED write | Theme App Embed **Missing** | **Missing** auto | N/A | BLOCKED | Wire `CatalogCapable`; App Embed; optional webhook subscribe; enable OAuth env for acceptance |
| WooCommerce | `WooCommerceConnector` | Partial (richest) | CatalogCapable | Read yes; write UNSUPPORTED | Manual only (`publishBanner` ok:false) | Manual paste | Plugin/snippet TBD | BLOCKED | Plugin uninstall cleanup; live wc/v3 acceptance |
| Wix | `WixConnector` | Partial; `paid_at` null | UNSUPPORTED | UNSUPPORTED | UNSUPPORTED (label honest) | — | Component TBD | BLOCKED | OAuth/refresh; backfill; settlement timestamps or keep LIMITED |
| BigCommerce | `BigCommerceConnector` | Partial | UNSUPPORTED | UNSUPPORTED | Widgets **Missing** | — | — | BLOCKED | Catalog + Widgets place/cleanup |
| Ecwid | `EcwidConnector` | **Broken money** (`amountMinor`/`currency` null) | UNSUPPORTED | UNSUPPORTED | Manual | — | — | BLOCKED / LIMITED | Fix monetary mapping **or** keep BLOCKED for paid qualification |
| Lightspeed C | `LightspeedCConnector` | Partial; no `paid_at`; refunds UNSUPPORTED | Upstream products not synced | UNSUPPORTED | LIMITED | Poll where needed | — | BLOCKED | Document LIMITED; paging/refund proof |
| PrestaShop | `PrestaShopConnector` | Optional webservice | UNSUPPORTED | UNSUPPORTED | Hook via module | HMAC bridge | **ZIP Missing** | BLOCKED | Ship versioned module; multistore isolation proof |
| OpenCart | `GenericSignedWebhookConnector` | Webhook-only; no native read | UNSUPPORTED | UNSUPPORTED | Extension Missing | HMAC bridge | **Extension Missing** | BLOCKED | Versioned signed bridge; no invented Admin API |
| Shopware 6 | `ShopwareConnector` | Optional Admin API | UNSUPPORTED | UNSUPPORTED | CMS Missing | Shopbonus HMAC ≠ native app sig | Manifest/app Missing | BLOCKED | Native app manifest + signatures + CMS |
| Magento / Adobe | `MagentoConnector` | PaaS/on-prem Partial | UNSUPPORTED | UNSUPPORTED | Module Missing | Prefer async events | Module Missing | PaaS BLOCKED until live; **SaaS = BLOCKED** not PASS | Separate SaaS variant; no checkout-blocking; credit memo proof |

### Roadmap only (COMING SOON — not among verified ten)

| Platform | Status | `blocked_reason` |
|----------|--------|------------------|
| Lightspeed E-Series | Missing (`connector: null`) | Supported API method not yet verified |
| Squarespace | Missing | Polling adapter not built |
| Mijnwebwinkel | Missing | Partner/API authorisation pending |
| CCV Shop | Missing | Merchant-accessible API options not yet investigated |

---

## Cross-cutting backend defects / gaps

1. **All ten `verification: unverified`**, `last_live_verified_at: null` — provider-backed E2E required for PASS.
2. **Only WooCommerce implements `CatalogCapable`** — Shopify GraphQL `products()` unused by `CatalogSync`.
3. **No automatic `registerWebhook`** on any platform.
4. **No native banner publish** anywhere; Banner Studio + universal embed exist — storefront proof still required.
5. **Private modules** (Presta / OpenCart / Shopware / Magento) not in this Laravel tree.
6. **Remote token revoke** on disconnect special-cased mainly for Shopify uninstall; others wipe local credentials.
7. **Tenant isolation** = workspace middleware + `webshop_id`, not Eloquent Policies — add Policies if required by security gate.
8. **Queue default `database`**, not Redis — align ops with Redis/Reverb evidence for release.
9. **`MeResource` omits workspace `permissions`** — FE gates App Store on membership `role` (owner/manager). Prefer exposing effective permissions on `/me` for accurate UI (still never trust client as auth).
10. **QA matrix stale:** `qa-evidence/COVERAGE_MATRIX.md` still says Lightspeed C NOT IMPLEMENTED — connector now exists; update when retesting.

---

## FE already calling (do not break)

| Method | Path |
|--------|------|
| GET | `/api/v1/integrations/platforms` |
| GET/POST/DELETE | `/api/v1/workspaces/{id}/connections`… |
| POST | `…/credentials`, `…/verify` |
| GET | `…/capabilities`, `…/health`, `…/products`, `…/coupons`, `…/events` |
| POST | `…/sync/products`, `…/sync/coupons`, `…/events/{event}/retry` |
| POST | `…/integrations/shopify/oauth` |

Secrets must never appear in list JSON after first connect response. Capability states must stay AVAILABLE | LIMITED | UNSUPPORTED — FE maps to honest badges (never fake VERIFIED without `verification` / `last_verified_at`).

---

## Acceptance for Martijn (evidence)

Per platform: masked IDs, HTTP capture, DB before/after, worker/webhook logs, Order Sheet screenshot, audit row.  
Statuses only: **PASS / FAIL / BLOCKED / NOT IMPLEMENTED / UNSUPPORTED / STALE**.  
Mock/simulator = supporting only, never PASS.  
Hassan retests via Quality Center after Frank fixes; NO-GO on wrong tenant, fake paid, wallet/commission errors, duplicate orders/calls, or missing platform proof.
