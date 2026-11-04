# Adobe App Builder asynchronous source — candidate 0.1.0

Backend-only Node22 Runtime Action; no UI/storefront component. No production deployment, Adobe registration, signature-validator chain, tenant/scopes, token-renewal or real order/refund/uninstall acceptance is certified. These remain NOT RUN. Use Adobe's supported App Builder CLI/Developer Console workflow; configure a real authorized Commerce event provider first. This source has no third-party runtime dependency.

Keep `web: 'no'` and the action private. Register it as a **Runtime Action** in Adobe Developer Console, not as a public webhook URL. Adobe documents that this registration places its signature validator before invocation and handles challenge verification. Do not bypass that chain, publish a public web action, or call this directly from unsigned merchant browsers. Namespace credentials are administrative secrets. Deploy the app only in the correct organization/workspace and restrict who can change its action parameters/event registrations.

Configure server-side values through the trusted deployment environment:

- `SHOPBONUS_ENABLED=true` only after staging authorization/registration checks.
- `SHOPBONUS_ENDPOINT`: exact HTTPS Magento webhook URL returned by ShopBonus.
- `SHOPBONUS_SECRET`: current generated ShopBonus connection signing secret (32–128 alphanumeric characters).
- `ADOBE_EVENT_SOURCE`: exact CloudEvent `source` from the registered provider.
- `COMMERCE_EVENT_SOURCE`: exact merchant/environment `data.source` configured in Commerce eventing.
- `COMMERCE_STORE_ID`, `COMMERCE_STORE_CODE`: exact native store view matching the ShopBonus connection. Global `all` is refused.

Register only supported commit events: `observer.sales_order_save_commit_after`, `observer.sales_order_invoice_save_commit_after`, `observer.sales_order_creditmemo_save_commit_after`. Confirm these event names/availability on the actual Commerce deployment before registering. Select minimal event fields: store_id + entity_id for order changes, store_id + order_id for invoice/credit changes. Commerce event payload is `data.value`; metadata/source outside it is not an order. Order-import paths that do not emit these events need the existing native discovery/recovery workflow.

The action rejects wrong providers/environments/stores, unknown types, missing IDs and unsafe JavaScript numeric IDs. It forwards only scope + order ID, not customer/payment/credit fields. The outgoing event ID is stable across Adobe retries; signatures use a fresh timestamp. HTTPS uses public-address validation, DNS pinning, no redirects, bounded response bytes and an8second timeout. Failed delivery throws a generic error without secrets; configure/verify actual Adobe event retry, retention, dead-letter and replay operations. This source does not claim that Runtime automatically retries every failed activation. Use provider failure evidence plus native backend backfill to recover uncertain/lost events. Acknowledgement is ingestion only, never financial success.

For Commerce SaaS, configure `deployment=saas`, exact tenant `saas_api_url`, verified public storefront `store_url`, store view ID/code and current scoped IMS token on the Laravel connection. REST URLs omit `/rest` and set the Store header. This source does not acquire or renew IMS tokens; senior deployment config owns renewal. Self-hosted/PaaS connections retain their original REST paths. App Builder and the PHP module are alternative couriers; both only request native reads.

Disable event registration/delivery and drain or reconcile failed events before removing the Runtime Action/app; revoke source signing credentials and connection access after reconciliation. Removing this backend courier does not remove storefront code (none is installed), financial evidence or prior wallet entries. Validate installation/update/uninstall through actual Adobe App Management/Developer Console; source packaging is not certification.

Primary contracts: Adobe Commerce events tutorial deployment, events reference, REST overview and November2026 SaaS OpenAPI linked in the ShopBonus runbook. Local Node tests run from the backend repository with `node --test tests/NativeAdobe/action.test.js` and are included in PHPUnit CI via AdobeRuntimeActionTest. All transports/events in those tests are explicitly synthetic.
