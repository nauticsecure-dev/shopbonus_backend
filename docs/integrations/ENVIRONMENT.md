# Integration environment and workspace credentials

Read from this checkout's `config/integrations.php`; defaults remain disabled. Put actual secrets in the deployment environment or the encrypted connection form/API, not in this document or chat.

```dotenv
SHOPIFY_OAUTH_ENABLED=false
SHOPIFY_CLIENT_ID=
SHOPIFY_CLIENT_SECRET=
SHOPIFY_WEBHOOK_SETUP_ENABLED=false

WIX_APP_ENABLED=false
WIX_APP_ID=
WIX_APP_SECRET=

SHOPWARE_APP_ENABLED=false
SHOPWARE_APP_NAME=
SHOPWARE_APP_SECRET=

INTEGRATION_SYNC_ENABLED=false
INTEGRATION_SYNC_CONNECTIONS=
CATALOG_SYNC_MAX_PAGES=50
WEBHOOK_PAYLOAD_RETENTION_DAYS=14
```

`INTEGRATION_SYNC_CONNECTIONS` is a comma-separated allowlist of connection public IDs, not platform names or store URLs. Provision the corresponding authorized app, exact HTTPS callbacks and restricted scopes before enabling an app mode. Queue workers and the Laravel scheduler must run for asynchronous recovery. Existing core `APP_URL`, session, CORS, queue and database configuration must match that deployment; do not copy secrets between environments.

Default Shopify OAuth requests `read_orders,read_products`. Existing native coupon reads additionally need an authorized token with `read_discounts`; enabling the OAuth flag does not grant that permission. Supported reads are single-code basic/free-shipping discounts, with BXGY/app/bulk-code discounts skipped. Coupon writes are not implemented.

Store-specific credentials are encrypted on `webshop_connections` through `POST /api/v1/workspaces/{workspace}/connections` or credential replacement. They are **not global environment variables**:

| Platform | Connection input keys |
| --- | --- |
| WooCommerce | `store_url`, `consumer_key`, `consumer_secret` |
| Shopify manual compatibility | `shop_domain`, `signing_secret`, optional authorized `access_token`; browser OAuth uses the server app keys above |
| Wix | `instance_id`, `site_id`, `public_key`; approved token/app-instance flow is separate from webhook verification |
| BigCommerce | `store_hash`, `access_token`, `client_secret` |
| Ecwid / Lightspeed E-Series | `store_id`, `access_token`, `client_secret` |
| Lightspeed C-Series | `shop_id`, `cluster`, `language`, `api_key`, `api_secret` |
| PrestaShop | `store_url`, `webservice_key`, `shop_id`, `timezone` |
| OpenCart candidate bridge | Generated `signing_secret` is returned once at connection creation; no implemented native product/coupon API credentials |
| Shopware manual integration | `store_url`, `client_id`, `client_secret`, `sales_channel_id`; app registration uses `app_registration` instead of manual client credentials |
| Adobe Commerce / Magento PaaS/on-premises | `store_url`, `access_token`, `store_code`, `store_id`; SaaS is a separate unsupported boundary |
| Squarespace | `website_id`, `api_key` |

Use the provider-specific rules and setup response as authoritative for required/optional combinations. The merchant UI currently exposes credential fields for only a subset; the remaining inputs use the existing authorized API/operator flows. A supplied credential does not establish verification. See [provider evidence requirements](VERIFICATION.md) and [candidate package installation](../../merchant-packages/INSTALL.md).
