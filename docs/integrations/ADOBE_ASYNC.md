# Adobe asynchronous delivery and SaaS REST — B77

Backend implementation boundary; genuine native deployment/installation/order/refund/cleanup acceptance is **NOT RUN**. Two candidate source archives are shipped:

- `/downloads/integrations/shopbonus-magento2-async.zip`: Magento2.4 self-hosted/PaaS module. No PHP module runs on SaaS.
- `/downloads/integrations/shopbonus-adobe-appbuilder-source.zip`: private Node22 Adobe Runtime Action for Commerce SaaS/PaaS I/O Events; no storefront/UI component.

## PaaS module

Install/configure through the merchant's supported Magento deployment workflow; see bundled `MAGENTO_INSTALL.md`. Scope settings to the exact store view with current native REST credentials on the Laravel connection. The module observes order/invoice/credit-memo save-commit events, writes an ID-only coalescing local outbox and performs no checkout HTTP. A native cron job selects ten enabled-store pending rows, obtains a shared Magento lock and delivers signed notifications with bounded retry. A concurrent observer replaces the event ID; an old worker can acknowledge only the event it actually sent. Disabled stores do not starve enabled-store batches. Outbox/cron failure logs require monitoring plus existing ShopBonus native discovery/recovery. Native DI compilation, observer invocation, schema creation and cron execution require actual Magento validation; source/archive tests do not certify them.

The module's native declarative table is `shopbonus_async_outbox`; no Laravel migration is added. Configuration uses Magento's encrypted secret backend and store-scoped admin ACL. Before removal: disable the delivery configuration, wait for active cron, reconcile/back up pending rows, revoke/disconnect credentials, then follow supported Magento module/schema removal. No automatic deletion of financial evidence or pending deliveries; no storefront code is installed.

## App Builder Runtime Action

See bundled `ADOBE_INSTALL.md`. Keep `web: 'no'`, register as a **Runtime Action** in Adobe Developer Console and preserve Adobe I/O's signature-validator chain. This is not an unsigned/public Laravel Adobe webhook or an externally exposed Runtime web action. Runtime namespace/app administrators are trusted operators. The actual organization/workspace, provider registration, signature validation, event retries/dead-letter/replay and app lifecycle require deployment evidence.

Pin both actual CloudEvent `source` and merchant/environment `data.source`, plus exact store ID/code. Enable only after those checks. Supported types are `com.adobe.commerce.observer.sales_order_save_commit_after`, `...sales_order_invoice_save_commit_after`, `...sales_order_creditmemo_save_commit_after`; verify event availability/selected fields on the deployment. Only native `entity_id`/`order_id` and store scope are forwarded. Source values remain in `data.value`; posted payment/customer data is discarded. Missing/wrong scope, unsafe JavaScript numeric IDs or unknown events fail closed. Stable event IDs support dedupe; delivery failures throw generic errors for explicit provider retry/replay and backend recovery. Do not assume every Runtime activation is automatically retried.

HTTPS has a fixed configured ShopBonus webhook path, public-address checks, DNS pinning, TLS validation, no redirects/proxy and an8second abort budget including DNS. A late DNS result cannot issue a request after abort. Responses are bounded to64KiB. Ingestion acknowledgement is never financial success.

## Scoped SaaS REST

Existing connection-create accepts Magento `deployment: saas`, `saas_api_url`, verified public `store_url`, exact `store_id`, `store_code` and current IMS `access_token`:

```json
{"platform":"magento","deployment":"saas","saas_api_url":"https://na1.api.commerce.adobe.com/SYNTHETIC_TENANT","store_url":"https://shop.example.com","store_id":"2","store_code":"default","access_token":"<CURRENT_SCOPED_IMS_TOKEN>"}
```

Use the **actual assigned** regional/tenant API URL, not this synthetic example. The host must be a single server label under `api.commerce.adobe.com`; URL credentials, ports, query/fragments, extra path components and global `Store: all` are refused. Tenant URL is part of the connection's frozen external identity. Store configurations are independently read and must match ID/code and the verified public storefront. Subsequent order/transaction/invoice/credit-memo reads remain scoped and order-bound through existing validation. SaaS URLs omit `/rest/{store}` and use the `Store` header with IMS bearer authentication; PaaS preserves its existing REST URL/auth contract. Manually supplied IMS tokens remain deployment-managed; B85 below adds optional client-credentials acquisition/renewal. A Magento admin token is not substituted. An expired token yields the existing provider-authentication error.

The current official November2026 SaaS OpenAPI includes `/V1/orders/{id}`, `/V1/store/storeConfigs`, `/V1/transactions`, `/V1/invoices` and `/V1/creditmemos`. The official overview defines IMS + Store-header routing. Schema existence does not establish actual tenant entitlement or live field values. Existing canonical capture/refund qualifications remain unchanged; credit memos or authorizations alone cannot manufacture settled cash.

## Shared receiver and evidence

Both couriers emit `shopbonus_magento_lookup_v1: true`, string `store_id`, `store_code`, `order_id` under the existing ShopBonus signed webhook headers. Laravel authenticates first, verifies exact configured scope and enqueues native order recovery. Extra posted financial fields never replace native reads. Legacy explicitly signed bridge format remains supported. Native verification still requires all real evidence gates; neither archive nor saved credentials establishes Connected.

SQLite integration/unit241PASS/2188; focused MySQL5PASS/43; final package/Runtime/API11PASS/8541 and API inventory/coverage5PASS/8353; four Node security/protocol tests execute inside AdobeRuntimeActionTest. Eleven actual private Laravel HTTP exchanges in `docs/qa/B77_ADOBE_HTTP_CAPTURE.json` cover setup/catalogue/detail401/403/404/422, an actual Node-signed202, duplicate200 and signed foreign-store401. Native recovery workers are deliberately queued, not executed, in that HTTP capture. Reproduce with `python3 scripts/capture-adobe-async.py` (temporary SQLite/storage/key, synthetic merchant/provider setup). No real Adobe requests, merchant credentials, provider registration or deployment occurs.

Primary sources:

- https://developer.adobe.com/commerce/php/development/components/events-and-observers/
- https://developer.adobe.com/commerce/extensibility/events/events-reference
- https://developer.adobe.com/commerce/extensibility/events/tutorial/deployment
- https://experienceleague.adobe.com/en/docs/platform-learn/tutorials/monitoring/monitor-events-in-slack
- https://developer.adobe.com/commerce/webapi/rest/
- https://developer.adobe.com/commerce/webapi/reference/rest/saas/
- https://adobe-commerce-saas.redoc.ly/ (November2026 schema, linked by Adobe's REST reference)

Remaining release gates: native module install/DI/observer/cron/backoff/concurrent-update/uninstall execution, actual private Runtime/signature-validator registration, genuine scoped IMS renewal, paid/refunded orders and cleanup with independent evidence. These remain **NOT RUN**; this checkpoint closes missing source implementation, not provider acceptance. Lightspeed/Squarespace native publisher and Lightspeed C cash gaps remain separate.

## B85 — Automatic IMS client-credentials renewal

Existing Adobe SaaS connection create/rotate accepts paired `ims_client_id` and `ims_client_secret` in place of `access_token`. Never submit both modes. Both are encrypted in the existing credentials column; no new migration. The senior developer can configure genuine credentials later. Native read permissions, the assigned exact tenant, verified public storefront and store ID/code remain mandatory; setting credentials does not establish authorization/Connected readiness.

The existing pinned HTTPS reader posts URL-encoded client credentials to the fixed `https://ims-na1.adobelogin.com/ims/token/v3` endpoint with Adobe's documented `openid,AdobeID,email,profile,additional_info.roles,additional_info.projectedProductContext,commerce.accs` scopes. No arbitrary token endpoint or client-chosen additional scope is accepted. Provider account permissions must be configured for the required read operations. Native requests keep the scoped Store header. Tokens are encrypted with their expiry; a five-minute safety margin triggers renewal under the existing shared-cache lock architecture. Use a shared production cache across worker nodes. OAuth TTL must be an integer600..86400 seconds and token_type bearer. Provider failures, redirects, oversized/invalid responses and rate limits use existing bounded transport/circuit handling; there is no fallback to stale credentials.

Lifecycle/store identity and fresh authority are checked before token reuse/mint and after mint under a row lock. Rotation, disconnect, pause or ownership loss during renewal cannot save a token. Renewal does not increment credential_generation; only token/expiry changes preserve authority. Client secret, tenant/store/domain/signing identity changes do not. Manually supplied SaaS tokens remain operator-managed; PaaS and legacy bridge mode are unchanged. An access token revoked before its advertised expiry can still cause native authentication failure; reconfigure/rotate the connection through the authorized existing workflow. No claim of automatic recovery from revoked grant permissions.

[Fourteen actual local HTTP captures](../qa/B85_ADOBE_IMS_HTTP_CAPTURE.json) include managed setup and validation/security negatives, using synthetic client credentials. IMS mint/expiry/rotation/pause/response validation are exercised with synthetic transport in `AdobeImsTokensTest`; these are not genuine Adobe acceptance. PaaS module/private Runtime source remains B77; installation/registration/DI/cron/event/replay/uninstall and authentic SaaS token/order/refund execution remain NOT RUN.

Primary sources rechecked2026-11-04:

- https://developer.adobe.com/commerce/webapi/rest/authentication/server-to-server
- https://developer.adobe.com/developer-console/docs/guides/authentication/ServerToServerAuthentication/implementation
- https://developer.adobe.com/commerce/extensibility/app-development/use-cases/social-login-sso
