# #31 B23a — WooCommerce native read API

2026-10-28. This boundary extends the existing WooCommerceConnector, ConnectorRegistry, connection endpoints and audit/security machinery. It is not a second order engine. #31 remains IN_PROGRESS: Shopify/native remaining providers, durable recovery and connection quality gates still need implementation; real provider/storefront acceptance is NOT RUN.

## What exists and what changed

Before B23a, WooCommerce and Shopify accepted signed webhook bodies; fetchOrder/syncOrders/testConnection inherited UnsupportedCapability. Generic Shopbonus-format adapters represent a signed module bridge, not a built/verified provider module. Other registry entries without adapters remain unavailable. This audit is based on actual code, not Trello ownership.

WooCommerce now supports authenticated GET order/refund reads, bounded modified-order polling and internal product/coupon page reads. Optional consumer_key/consumer_secret plus store_url are accepted by the existing create/replace-credentials endpoints and encrypted at rest. Supplied read keys are never returned. The generated webhook secret remains shown once. Replacing credentials replaces the set: omitting API keys removes them and rotates the webhook secret; existing webhook-only configurations still work.

The existing catalogue gains nullable reviewed profile metadata, with WooCommerce implemented code separated from its unverified live state. Shopify's GraphQL version is researched, but native API implementation remains false. No undocumented platform is marked available. No source verification dates are invented.

## Provider contract and official review

- [Current WooCommerce REST introduction/authentication](https://developer.woocommerce.com/docs/apis/rest-api/): use a merchant-created read-only API key via HTTPS HTTP Basic. No query-string credential fallback is implemented. An observed read does not prove the key lacks write scopes.
- [Orders](https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/): GET /wp-json/wc/v3/orders/{id}; list supports modified_after/modified_before, dates_are_gmt, orderby=modified and page/per_page. Read IDs and decimal-string amounts exactly; customer absence remains incomplete, not fabricated.
- [Order refunds](https://developer.woocommerce.com/docs/apis/rest-api/v3/order-refunds/): GET /orders/{id}/refunds; native amount is mapped into existing normalizer refund summaries. Native refund amounts must parse in the order currency and not exceed the order total. No refund-write endpoint is called.
- [Native webhooks](https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/): existing order.created/order.updated signed intake remains in place. This slice adds no invented refund.created subscription, native module or banner publisher.
- [Shopify current GraphQL reference](https://shopify.dev/docs/api/admin-graphql/latest): current reviewed version2026-10; implementation remains a later boundary, including OAuth/protected-data/scopes and lifecycle evidence.

Every source remains live-unverified. Specifications/code tests do not substitute for merchant-authorized store tests.

## Active API test

POST `/api/v1/workspaces/{workspace}/connections/{connection}/test-api`

Requires active verified membership and integrations.manage; support-session writes are blocked by the existing connections.* rule. A browser session needs CSRF. Per-user3/minute throttle spans workspaces. Optional body `{"order_id":"4711"}` reads that exact order plus at most2refund pages, returning only the existing no-PII order summary. Empty body tests a single native orders-list read. Unsupported adapters409. No arbitrary host/resource/cursor URL can be supplied.

The response explicitly returns state_changed=false and production_verified=false. It never changes connection status, ingests an order, posts wallet value or dispatches contact/vouchers. Successful and failed provider checks are audited using fixed result/error codes, with actor/workspace context. Credential change or disconnect during the read discards the stale result409. Audit failure does not yield an unaudited success.

Errors:401unauthenticated;403permission;404other workspace/missing connection;409missing configuration/unsupported/ownership/address/authentication/disconnect/credential-change;419CSRF;422bad order ID/URL input;429local throttle;502provider/redirect/schema/size failure;503provider cooldown/circuit;500unexpected local storage/audit fault. Existing generic error envelopes/request IDs are reused. Actual20HTTPcaptures cover all these statuses and changed catalogue/create/show/credential responses in B23_WOO_HTTP_CAPTURE.json and the API reference. Provider upstream and DNS are explicitly temporary synthetic fixtures; the Laravel HTTP server and browser auth/CSRF paths are real. No credential or webhook token is retained in documentation.

## Read safety and limits

Store URL must be HTTPS, a public domain, without userinfo/query/fragment/custom port/encoded path traversal. WordPress subdirectory installations use simple path segments. Existing ownership normalization treats leading www as the same ownership domain, while the actual request host remains unchanged. Unrelated subdomains require their own verification. Unicode host/path variants beyond current URL validation are not advertised supported.

Reuse HostResolver/PublicAddressGuard. Reject mixed public/private DNS, pin the selected checked address, retain normal TLS verification/hostname, disable proxies and redirects. No credentials in URLs, exceptions, captured responses or logs. Request timeout8seconds/connect3seconds; body1MiB;100rows/page. Empty or protected customer fields stay missing. API response IDs, JSON/list shapes, currency/amount/refunds and pagination are checked before reporting successful reads.

Native order polling freezes its upper time and handles at most5pages; too much/ambiguous pagination raises provider_pagination_limit rather than reporting complete recovery. It is a read primitive, not a new background ingestion/recovery pipeline. Existing canonical ingestion/replay/reversal services must be reused by the later durable sync boundary. Product/coupon reads return bounded native pages internally; catalog persistence/UI are not implemented by this slice.

No automatic HTTP retries. Provider429 sets a bounded1–3600second credential-specific pause and Retry-After;3network/server failures open a5minute circuit. Credential-scoped HMAC cache keys contain no plaintext key. Numeric-string pause timestamps from Redis are handled; a regression reproduces and prevents an extra upstream call during a pause. Scope/auth errors require configuration review. Native key permissions still need senior/provider verification. No network request is needed to save an optional key; do not collect credentials in chat.

## Acceptance and remaining work

Automated tests cover real connector methods against explicitly synthetic provider responses: Basic header/IP pin/redirect and proxy policy; original currency/refund mapping; modified-order/catalog reads; public/mixed DNS; www normalization; invalid URL/ID/body/schema/size/pages; auth/rate/network circuit; tenant/manage/anonymous/disconnect/stale credential guards; encrypted storage/no credential return; no order/wallet/outbox effect. Existing signed webhook/rotation/order suites remain intact.

Full #31 quality-gate work is not silently completed: legacy connected means the prior complete signed-webhook observation, not all seven new card gates. New API tests never promote it. Runtime store/auth/refund/rotation-bound readiness, durable recovery/backfill, remaining ten-provider profiles/adapters, native modules/app lifecycle and genuine store/banner tests remain later boundaries. No wallet spendability change; D-089 still governs affiliate rewards. #32 overlaps this work and is not a second implementation assignment.

No migration or package added. No real key/provider/dispatch configuration changed. Senior supplies read-only store credentials through the existing encrypted connection endpoints/configuration, then records real store/order/refund and credential-revocation evidence. Hassan owns any corresponding frontend/provider storefront implementation. Do not mark #31 Done or move its remaining backend to Hassan based on B23a alone.
