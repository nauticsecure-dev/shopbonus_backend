# Universal Webshop Order Adapter — audit (2026-10-30)

Reuse-first audit of ShopBonus integrations. Trello names map to existing classes (see below). **No duplicate** order tables, webhook receivers, or wallet engines were introduced.

## Naming map

| Spec name | Implementation |
|-----------|----------------|
| ConnectorRegistry | `App\Integrations\ConnectorRegistry` |
| ConnectionManager | `App\Integrations\ConnectionManager` |
| WebhookReceiver / Verifier | `WebhookController` + `Connector::verifyIncomingEvent` |
| OrderFetcher / Normalizer / Ingestion | `OrderRecovery` + `Connector::normalizeOrder` + `OrderIngestion` |
| OrderEligibilityService | `App\Services\Answer24\OrderEligibility` |
| Answer24DispatchService | `App\Services\Answer24\OutboxDispatcher` |
| ConnectionHealthMonitor | `App\Integrations\ConnectionReadiness` + connection `health` API |
| OrderSheetBroadcaster | `App\Events\OrderSheetUpdated` |
| answer24_contact_jobs | `contact_opportunities` + `integration_outbox` |
| integration_audit_logs | `audit_events` |

## Capability status

| Area | Status | Evidence |
|------|--------|----------|
| Webhook intake + ProcessWebhookEvent | WORKING | `ConnectionsAndWebhooksTest` |
| Connection CRUD + checklist (never CONNECTED on credentials alone) | WORKING | `ConnectionManager`, `ConnectionReadiness` 7 gates |
| Order Sheet API + Reverb event | WORKING | `OrderController`, `OrderSheetUpdated`; FE wired 2026-10-30 |
| Answer24 eligibility / grouping / cancel | WORKING | `ContactEligibilityTest` |
| Answer24 live dispatch | PARTIAL | Complete code; `ANSWER24_DISPATCH_ENABLED` default false |
| Platform catalogue + capabilities | WORKING | `CapabilityMatrix` + catalogue `capabilities` |
| WooCommerce products/coupons sync | WORKING | `CatalogCapable` + `CatalogSync` + `CapabilityAndCatalogTest` |
| Shopify/Wix/BC/Ecwid/Lightspeed C/Presta/Shopware/Magento order adapters | PARTIAL | Laravel adapters present; live `verification: unverified` |
| OpenCart | LIMITED | Shopbonus HMAC bridge only; no native order read |
| Lightspeed E, Squarespace, Mijnwebwinkel, CCV | MISSING | `connector => null`, honest `blocked_reason` |
| Ten-platform API specs + capability profiles | WORKING | `docs/integrations/PLATFORM_API_SPECS.md` + `config/integrations.php` profiles |
| Cross-path wallet dedup (affiliate ↔ direct) | WORKING | `RewardSourceGuard` |
| Merchant Webshops / Orders / Banner Studio UI | WORKING | `/dashboard/webshops`, `/dashboard/orders`, `/dashboard/banners` |
| Native storefront banner publish | LIMITED | Manual embed; `publishBanner` returns manual_embed |
| Private shop modules (Presta/OC/SW/Magento ZIPs) | MISSING | Backend contract only |

## Connection statuses

Server enum: `setup_required`, `testing`, `connected`, `limited`, `error`, `disconnected`.

Merchant UI lifecycle also shows `not_connected` (no row) and `awaiting_test` (derived from checklist / setup webhook present while still `setup_required`). **CONNECTED requires all seven native gates** — never credentials alone.

## New / extended surfaces (no duplication)

- `GET /api/v1/integrations/platforms` → includes `capabilities` + `category`
- `GET .../connections/{id}/capabilities|health|products|coupons`
- `POST .../connections/{id}/sync/products|coupons`
- Tables: `catalog_products`, `catalog_coupons`, `catalog_sync_runs`

## Ten E2E targets vs coverage

1. Audit existing connector — this doc + catalogue API  
2. WooCommerce paid order live — existing `ConnectionsAndWebhooksTest` + FE Order Sheet  
3. Shopify permissions / webhooks — existing `ShopifyApiTest` / OAuth tests  
4. Import 100 products — sync pages (bounded `MAX_PAGES_PER_RUN`); extend with live store when authorised  
5. Coupons valid/expired → `eligible_public` — `CapabilityAndCatalogTest`  
6. Banner generate/approve/publish — existing `BannerAiTest` / `BannerTest` + FE studio  
7. Cashback 70/30 exactly once — existing `MyBenefitsE2ETest`  
8. Duplicate / affiliate+direct — `RewardSourceGuard` + affiliate duplicate tests  
9. Cancel/refund Answer24 + ledger — existing Answer24 / wallet tests  
10. Tenant isolation / secrets / retry — existing integration + Answer24 channel tests  

Live provider acceptance remains **unverified** until merchant-authorised stores are available.
