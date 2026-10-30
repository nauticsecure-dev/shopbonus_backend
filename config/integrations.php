<?php

use App\Integrations\Connectors\BigCommerceConnector;
use App\Integrations\Connectors\EcwidConnector;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Integrations\Connectors\LightspeedCConnector;
use App\Integrations\Connectors\MagentoConnector;
use App\Integrations\Connectors\PrestaShopConnector;
use App\Integrations\Connectors\ShopifyConnector;
use App\Integrations\Connectors\ShopwareConnector;
use App\Integrations\Connectors\WixConnector;
use App\Integrations\Connectors\WooCommerceConnector;

/*
|--------------------------------------------------------------------------
| Ecommerce platform capability matrix (Trello #1 "Supported platforms")
|--------------------------------------------------------------------------
|
| `connector` null = no adapter yet: the platform is listed but cannot be
| connected (never advertised as available). `verification` records what
| has actually been tested against a real store or sandbox: every entry is
| `unverified` until someone with provider access runs the checks in
| docs/integrations/VERIFICATION.md and updates this file with evidence.
|
*/

return [

    'sync' => ['enabled' => (bool) env('INTEGRATION_SYNC_ENABLED', false), 'connections' => array_values(array_filter(explode(',', (string) env('INTEGRATION_SYNC_CONNECTIONS', ''))))],

    'shopify_webhooks' => ['enabled' => (bool) env('SHOPIFY_WEBHOOK_SETUP_ENABLED', false)],

    // Wix OAuth app (client_credentials per app instance). Off: senior supplies tokens manually.
    // Shopware app system: name and app secret from the Shopware Account (never in a distributed manifest).
    'shopware_app' => ['enabled' => (bool) env('SHOPWARE_APP_ENABLED', false), 'name' => env('SHOPWARE_APP_NAME', ''), 'secret' => env('SHOPWARE_APP_SECRET', '')],

    // Read-only catalog/coupon snapshots (B25): at most this many native pages of 100 per pass; beyond it the pass is partial.
    'catalog' => ['max_pages' => (int) env('CATALOG_SYNC_MAX_PAGES', 50)],

    'wix_app' => ['enabled' => (bool) env('WIX_APP_ENABLED', false), 'app_id' => env('WIX_APP_ID', ''), 'app_secret' => env('WIX_APP_SECRET', '')],

    'shopify_oauth' => [
        'enabled' => (bool) env('SHOPIFY_OAUTH_ENABLED', false),
        'client_id' => env('SHOPIFY_CLIENT_ID', ''),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),
        'scopes' => ['read_orders', 'read_products'],
    ],

    'webhook_retention_days' => (int) env('WEBHOOK_PAYLOAD_RETENTION_DAYS', 14),

    'platforms' => [
        'woocommerce' => [
            'profile' => [
                'api_version' => 'wc/v3', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'HTTPS HTTP Basic consumer key/secret; read-only merchant key',
                'scopes' => ['read'], 'webhook_topics' => ['order.created', 'order.updated'],
                'order_api' => 'GET /wp-json/wc/v3/orders/{id}', 'refund_api' => 'GET /wp-json/wc/v3/orders/{id}/refunds',
                'product_api' => 'GET /wp-json/wc/v3/products', 'coupon_api' => 'GET /wp-json/wc/v3/coupons',
                'banner_method' => 'manual; native WordPress publication/module not implemented',
                'rate_limit_policy' => 'No redirects/retries;8second reads,1MiB body,100rows/page,5order pages;429cooldown/3failure circuit.',
                'sources' => [
                    'https://developer.woocommerce.com/docs/apis/rest-api/v3/',
                    'https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/',
                    'https://developer.woocommerce.com/docs/apis/rest-api/v3/order-refunds/',
                    'https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/',
                ],
            ],
            'name' => 'WooCommerce', 'priority' => 'production',
            'connector' => WooCommerceConnector::class,
            'method' => 'webhook', 'auth' => 'shared secret (HMAC-SHA256, base64)',
            'order_source' => 'webhook payload (REST v3 order)', 'refunds' => 'order.updated status/refunds',
            'reliability' => 'high', 'limitations' => [], 'verification' => 'unverified',
        ],
        'shopify' => [
            'profile' => [
                'api_version' => '2026-10', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'Encrypted approved app token for pinned GraphQL; manual signing-secret compatibility. optional disabled-by-default browser OAuth and expiring offline token renewal implemented',
                'scopes' => ['read_orders', 'read_products (catalog only)'],
                'order_api' => 'POST /admin/api/2026-10/graphql.json: order/orders queries',
                'refund_api' => 'Order.totalRefundedSet and successful REFUND transactions; no refund mutation',
                'product_api' => 'products query; bounded core catalog metadata only', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'rate_limit_policy' => 'Pinned version,8second/1MiB reads,25orders/page,max5pages; no retries, HTTP200GraphQLerrors checked; throttling pauses reads.',
                'webhook_topics' => ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'refunds/create', 'app/uninstalled'],
                'banner_method' => 'Theme App Extension requires separate implementation and merchant activation',
                'sources' => ['https://shopify.dev/docs/api/admin-graphql/2026-10', 'https://shopify.dev/docs/apps/build/webhooks', 'https://shopify.dev/docs/apps/build/online-store/theme-app-extensions'],
            ],
            'name' => 'Shopify', 'priority' => 'production',
            'connector' => ShopifyConnector::class,
            'method' => 'webhook', 'auth' => 'webhook signing secret (HMAC-SHA256, base64)',
            'order_source' => 'webhook payload', 'refunds' => 'refunds/create, orders/updated',
            'reliability' => 'high', 'limitations' => ['customer fields may be redacted without protected customer data access'],
            'verification' => 'unverified',
        ],
        'wix' => [
            'profile' => [
                'api_version' => 'eCommerce Orders v1 / App Instance v1', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'App RSA-signed JWT webhook + merchant-authorized API token',
                'scopes' => ['Read Orders', 'Read Order Transactions', 'Manage Your App'],
                'webhook_topics' => ['wix.ecom.v1.order_created', 'wix.ecom.v1.order_updated', 'wix.ecom.v1.order_paid', 'wix.ecom.v1.order_canceled'],
                'order_api' => 'GET https://www.wixapis.com/ecom/v1/orders/{id}', 'refund_api' => 'GET https://www.wixapis.com/ecom/v1/payments/orders/{id}',
                'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'Wix app/site extension or Blocks/Velo; silent external HTML publish not assumed',
                'rate_limit_policy' => 'Pinned HTTPS reads,8second/1MiB; JWT verify before native lookup; no search backfill in this boundary.',
                'sources' => ['https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/orders/get-order', 'https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/order-transactions/list-transactions-for-single-order', 'https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/orders/order-created', 'https://dev.wix.com/docs/build-apps/develop-your-app/api-integrations/events-and-webhooks/about-webhooks'],
            ],
            'name' => 'Wix', 'priority' => 'production', 'connector' => WixConnector::class,
            'method' => 'webhook', 'auth' => 'App RSA-signed JWT + merchant-authorized API token lookup',
            'reliability' => 'unknown', 'limitations' => ['No OAuth install/refresh or search backfill; payment createdDate is not settled-at; paid_at stays null.'], 'verification' => 'unverified',
            'blocked_reason' => null,
        ],
        'bigcommerce' => [
            'profile' => [
                'api_version' => 'Orders v2 / Transactions and Refunds v3', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'Standard Webhooks app-secret HMAC + X-Auth-Token',
                'scopes' => ['store_v2_orders_read_only', 'store_v2_transactions_read_only', 'store_v2_information_read_only'],
                'webhook_topics' => ['store/order/created', 'store/order/updated', 'store/order/statusUpdated', 'store/order/refund/created', 'store/order/transaction/created'],
                'order_api' => 'GET https://api.bigcommerce.com/stores/{hash}/v2/orders/{id}', 'refund_api' => 'GET .../v3/orders/{id}/payment_actions/refunds',
                'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'BigCommerce Widgets API; native publish not implemented',
                'rate_limit_policy' => 'No redirects/retries;8second/1MiB; webhook timestamp±300s; unpaid created orders imported but not rewarded until capture proof.',
                'sources' => ['https://docs.bigcommerce.com/developer/docs/integrations/webhooks/overview', 'https://docs.bigcommerce.com/developer/docs/integrations/webhooks/event-reference/events', 'https://docs.bigcommerce.com/developer/docs/admin/widgets-and-scripts/overview', 'https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/get-order'],
            ],
            'name' => 'BigCommerce', 'priority' => 'production', 'connector' => BigCommerceConnector::class,
            'method' => 'webhook', 'auth' => 'Standard Webhooks app-secret HMAC + restricted API token lookup', 'reliability' => 'unknown',
            'limitations' => ['Multi-storefront refused; display/transaction currency mismatch refused; widget/catalog publication not implemented.'], 'verification' => 'unverified',
            'blocked_reason' => null,
        ],
        'ecwid' => [
            'profile' => [
                'api_version' => 'v3', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'X-Ecwid-Webhook-Signature (app client_secret) + Bearer store token',
                'scopes' => ['read_orders', 'read_store_profile'],
                'webhook_topics' => ['order.created', 'order.updated'],
                'order_api' => 'GET https://app.ecwid.com/api/v3/{storeId}/orders/{orderId}', 'refund_api' => 'paymentStatus on order; dedicated refund cash totals unsupported',
                'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'Documented storefront customization or merchant-approved manual placement',
                'rate_limit_policy' => 'Notification→native lookup; plan/API feature check; durable updatedFrom discovery; no invented currency.',
                'sources' => ['https://docs.ecwid.com/', 'https://docs.ecwid.com/api-reference/rest-api/orders/get-order', 'https://docs.ecwid.com/api-reference/rest-api/store-profile/get-store-profile', 'https://github.com/Ecwid/ecwid-api-docs/blob/master/includes/_webhooks.md'],
            ],
            'name' => 'Ecwid', 'priority' => 'production', 'connector' => EcwidConnector::class,
            'method' => 'webhook', 'auth' => 'app client-secret HMAC notification + store API token lookup', 'reliability' => 'limited',
            'limitations' => ['Reviewed order API has no immutable order currency or settled payment time; monetary mapping remains incomplete. Native reads and plan checks implemented; live verification pending.'], 'verification' => 'unverified', 'blocked_reason' => null,
        ],
        'lightspeed_c' => [
            'profile' => [
                'api_version' => 'Lightspeed eCom C-Series REST', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'HTTP Basic api_key:api_secret; app webhook x-signature=md5(body+api_secret)',
                'scopes' => ['store API key on Advanced/Professional plan'],
                'webhook_topics' => ['orders/created', 'orders/updated', 'orders/paid', 'orders/deleted', 'invoices/created', 'invoices/updated', 'returns/created', 'returns/updated'],
                'order_api' => 'GET https://{cluster}/{lang}/orders/{id}.json', 'refund_api' => 'paymentStatus only; returns cash mapping UNSUPPORTED in this boundary',
                'product_api' => 'GET /products.json available upstream; catalog sync UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'Theme/script placement; Shop/scripts resource exists upstream; automatic publish not implemented',
                'rate_limit_policy' => 'Cluster eu1|us1 only;8second/1MiB reads; require x-signature (app webhooks); Retail/E-Series refused; confirmation-page tracking not used.',
                'sources' => [
                    'https://ecom-support.lightspeedhq.com/hc/en-us/articles/220320568-API-documentation',
                    'https://developers.lightspeedhq.com/ecom/introduction/authentication/',
                    'https://developers.lightspeedhq.com/ecom/endpoints/order/',
                    'https://developers.lightspeedhq.com/ecom/endpoints/shop/',
                    'https://developers.lightspeedhq.com/ecom/tutorials/webhooks/',
                ],
            ],
            'name' => 'Lightspeed C-Series', 'priority' => 'production', 'connector' => LightspeedCConnector::class,
            'method' => 'webhook', 'auth' => 'HTTP Basic API key/secret + app webhook md5 signature',
            'reliability' => 'limited', 'limitations' => [
                'Advanced/Professional plan required for API access.',
                'Order resource has no per-order currency; amounts use verified shop main currency at read time.',
                'paymentStatus has no refunded value; refund_minor stays null until returns cash mapping is verified.',
                'paid_at is unavailable from documented order fields.',
                'Retail/E-Series and confirmation-page tracking are not substituted.',
            ], 'verification' => 'unverified', 'blocked_reason' => null,
        ],
        'prestashop' => [
            'name' => 'PrestaShop', 'priority' => 'additional',
            'connector' => PrestaShopConnector::class,
            'profile' => [
                'api_version' => 'PrestaShop9 Webservice JSON', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'Shopbonus HMAC module bridge + optional HTTPS Basic webservice key',
                'scopes' => ['GET shop_urls,orders,customers,addresses,currencies,order_payments,order_slip'],
                'webhook_topics' => ['order.created', 'order.updated', 'order.paid', 'order.cancelled', 'order.refunded', 'ping'],
                'order_api' => 'GET {installation}/api/orders/{id}?output_format=JSON', 'refund_api' => 'order_slip observed but not settled-cash; Unknown when slip-only',
                'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'Storefront display hooks via private ZIP module; module not shipped in this backend',
                'rate_limit_policy' => 'id_shop scope; ambiguous DST rejected; module ZIP outside Laravel scope.',
                'sources' => ['https://devdocs.prestashop-project.org/9/modules/introduction/', 'https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionvalidateorderafter/', 'https://devdocs.prestashop-project.org/9/webservice/resources/orders/'],
            ],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private ZIP module must be installed', 'actionValidateOrderAfter is not settled-payment proof'],
            'verification' => 'unverified',
        ],
        'opencart' => [
            'name' => 'OpenCart', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'profile' => [
                'api_version' => 'Shopbonus webhook v1 bridge (OpenCart 3/4 extension required)', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => false, 'auth_method' => 'Shopbonus HMAC-SHA256 signed webhook v1',
                'scopes' => ['merchant extension pairing secret'],
                'webhook_topics' => ['order.created', 'order.updated', 'order.paid', 'order.cancelled', 'order.refunded', 'ping'],
                'order_api' => 'UNSUPPORTED native admin order read; OpenCart catalog API is checkout-session oriented, not authoritative order history',
                'refund_api' => 'Via module payload fields only', 'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'OpenCart 4 module/events or OCMOD when hooks insufficient; extension not shipped in this backend',
                'rate_limit_policy' => 'Module must deliver server-to-server signed events; OC3 vs OC4 packaging differs; no invented REST admin orders API.',
                'sources' => ['https://docs.opencart.com/developer-guide/extensions', 'https://docs.opencart.com/admin-interface/system/users/api'],
            ],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private OCMOD/extension must be installed', 'native order API not assumed; seven-gate Connected requires native_api evidence'],
            'verification' => 'unverified',
        ],
        'shopware6' => [
            'name' => 'Shopware 6', 'priority' => 'additional',
            'connector' => ShopwareConnector::class,
            'profile' => [
                'api_version' => 'Shopware6 Admin API', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'Shopbonus HMAC bridge + optional Admin API client_credentials',
                'scopes' => ['read order, sales-channel-domain, state-machine-history'],
                'webhook_topics' => ['order.created', 'order.updated', 'order.paid', 'order.cancelled', 'order.refunded', 'ping'],
                'order_api' => 'POST {installation}/api/search/order', 'refund_api' => 'Partial/refund cash mapping incomplete without history evidence',
                'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'Shopware CMS/app extension; native app manifest webhooks not the Shopbonus HMAC bridge',
                'rate_limit_policy' => 'Existing Shopbonus signed-module contract is explicit, not native Shopware app-signature lifecycle.',
                'sources' => ['https://developer.shopware.com/docs/guides/plugins/apps/app-base-guide.html', 'https://developer.shopware.com/docs/guides/plugins/apps/lifecycle/webhook.html', 'https://developer.shopware.com/docs/resources/references/app-reference/manifest-reference.html'],
            ],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'extension payload',
            'reliability' => 'high (extension not yet built)', 'limitations' => ['private extension must be installed', 'native Shopware app registration/signatures/lifecycle separate'],
            'verification' => 'unverified',
        ],
        'magento' => [
            'name' => 'Magento / Adobe Commerce', 'priority' => 'additional',
            'connector' => MagentoConnector::class,
            'profile' => [
                'api_version' => 'Magento/Adobe Commerce2.4 self-hosted/PaaS REST', 'reviewed_at' => '2026-10-30', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'Shopbonus HMAC bridge + optional REST integration bearer token',
                'scopes' => ['orders, invoices, transactions, creditmemos read'],
                'webhook_topics' => ['order.created', 'order.updated', 'order.paid', 'order.cancelled', 'order.refunded', 'ping'],
                'order_api' => 'GET {installation}/rest/{store_code}/V1/orders/{id}', 'refund_api' => 'creditmemos + refund transactions required for refund qualification',
                'product_api' => 'UNSUPPORTED in this boundary', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'banner_method' => 'Approved Commerce module/storefront component; architecture-dependent',
                'rate_limit_policy' => 'Prefer Adobe I/O Events async; do not use synchronous Commerce Webhooks for slow ingestion. SaaS tenant auth not substituted.',
                'sources' => ['https://developer.adobe.com/commerce/extensibility/events/', 'https://developer.adobe.com/commerce/extensibility/webhooks/', 'https://developer.adobe.com/commerce/webapi/rest/attributes/', 'https://github.com/magento/magento2'],
            ],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private module must be installed', 'SaaS vs PaaS/on-prem differences unverified live'],
            'verification' => 'unverified',
        ],
        'lightspeed_e' => [
            'name' => 'Lightspeed E-Series', 'priority' => 'additional', 'connector' => null,
            'method' => 'API/confirmation (to be determined)', 'auth' => 'to be determined', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Supported API method not yet verified.',
        ],
        'squarespace' => [
            'name' => 'Squarespace', 'priority' => 'additional', 'connector' => null,
            'method' => 'polling (read-only Orders API)', 'auth' => 'API key', 'reliability' => 'polling delay',
            'limitations' => ['polling'], 'verification' => 'unverified', 'blocked_reason' => 'Polling adapter not built; requires API key.',
        ],
        'mijnwebwinkel' => [
            'name' => 'Mijnwebwinkel', 'priority' => 'additional', 'connector' => null,
            'method' => 'partner API', 'auth' => 'partner authorisation', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Partner/API authorisation pending.',
        ],
        'ccv' => [
            'name' => 'CCV Shop', 'priority' => 'additional', 'connector' => null,
            'method' => 'to be investigated', 'auth' => 'to be determined', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Merchant-accessible API options not yet investigated.',
        ],
        'other' => [
            'name' => 'Other / custom shop', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'webhook', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'custom integration',
            'reliability' => 'depends on the custom integration', 'limitations' => [], 'verification' => 'unverified',
        ],
    ],

];
