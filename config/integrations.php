<?php

use App\Integrations\Connectors\BigCommerceConnector;
use App\Integrations\Connectors\EcwidConnector;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
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
                'api_version' => 'wc/v3', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'HTTPS HTTP Basic consumer key/secret; read-only merchant key',
                'scopes' => ['read'], 'webhook_topics' => ['order.created', 'order.updated'],
                'order_api' => 'GET /wp-json/wc/v3/orders/{id}', 'refund_api' => 'GET /wp-json/wc/v3/orders/{id}/refunds',
                'product_api' => 'GET /wp-json/wc/v3/products', 'coupon_api' => 'GET /wp-json/wc/v3/coupons',
                'banner_method' => 'manual; native WordPress publication/module not implemented',
                'rate_limit_policy' => 'No redirects/retries;8second reads,1MiB body,100rows/page,5order pages;429cooldown/3failure circuit.',
                'sources' => ['https://developer.woocommerce.com/docs/apis/rest-api/', 'https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/',
                    'https://developer.woocommerce.com/docs/apis/rest-api/v3/order-refunds/', 'https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/'],
            ],
            'name' => 'WooCommerce', 'priority' => 'production',
            'connector' => WooCommerceConnector::class,
            'method' => 'webhook', 'auth' => 'shared secret (HMAC-SHA256, base64)',
            'order_source' => 'webhook payload (REST v3 order)', 'refunds' => 'order.updated status/refunds',
            'reliability' => 'high', 'limitations' => [], 'verification' => 'unverified',
        ],
        'shopify' => [
            'profile' => [
                'api_version' => '2026-10', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'Encrypted approved app token for pinned GraphQL; manual signing-secret compatibility. optional disabled-by-default browser OAuth and expiring offline token renewal implemented',
                'scopes' => ['read_orders', 'read_products (catalog only)'],
                'order_api' => 'POST /admin/api/2026-10/graphql.json: order/orders queries',
                'refund_api' => 'Order.totalRefundedSet and successful REFUND transactions; no refund mutation',
                'product_api' => 'products query; bounded core catalog metadata only', 'coupon_api' => 'UNSUPPORTED in this boundary',
                'rate_limit_policy' => 'Pinned version,8second/1MiB reads,25orders/page,max5pages; no retries, HTTP200GraphQLerrors checked; throttling pauses reads.',
                'webhook_topics' => ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'refunds/create', 'app/uninstalled'],
                'banner_method' => 'Theme App Extension requires separate implementation and merchant activation',
                'sources' => ['https://shopify.dev/docs/api/admin-graphql/2026-10', 'https://shopify.dev/docs/apps/build/webhooks'],
            ],
            'name' => 'Shopify', 'priority' => 'production',
            'connector' => ShopifyConnector::class,
            'method' => 'webhook', 'auth' => 'webhook signing secret (HMAC-SHA256, base64)',
            'order_source' => 'webhook payload', 'refunds' => 'refunds/create, orders/updated',
            'reliability' => 'high', 'limitations' => ['customer fields may be redacted without protected customer data access'],
            'verification' => 'unverified',
        ],
        'wix' => [
            'profile' => ['api_version' => 'eCommerce Orders v1 / App Instance v1', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null, 'native_read_api_implemented' => true, 'scopes' => ['Read Orders', 'Read Order Transactions', 'Manage Your App'], 'sources' => ['https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/orders/get-order', 'https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/order-transactions/list-transactions-for-single-order', 'https://dev.wix.com/docs/build-apps/develop-your-app/api-integrations/events-and-webhooks/about-webhooks']],
            'name' => 'Wix', 'priority' => 'production', 'connector' => WixConnector::class,
            'method' => 'webhook', 'auth' => 'App RSA-signed JWT + merchant-authorized API token lookup',
            'reliability' => 'unknown', 'limitations' => [], 'verification' => 'unverified',
            'blocked_reason' => null,
        ],
        'bigcommerce' => [
            'profile' => ['api_version' => 'Orders v2 / Transactions and Refunds v3', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null, 'native_read_api_implemented' => true, 'scopes' => ['store_v2_orders_read_only', 'store_v2_transactions_read_only', 'store_v2_information_read_only'], 'sources' => ['https://docs.bigcommerce.com/developer/docs/integrations/webhooks/https', 'https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/get-order', 'https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/order-operations/get-order-transactions']],
            'name' => 'BigCommerce', 'priority' => 'production', 'connector' => BigCommerceConnector::class,
            'method' => 'webhook', 'auth' => 'Standard Webhooks app-secret HMAC + restricted API token lookup', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified',
            'blocked_reason' => null,
        ],
        'ecwid' => [
            'profile' => ['api_version' => 'v3', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null, 'native_read_api_implemented' => true, 'scopes' => ['read_orders', 'read_store_profile'], 'order_api' => 'GET https://app.ecwid.com/api/v3/{storeId}/orders/{orderId}', 'webhook_topics' => ['order.created', 'order.updated'], 'sources' => ['https://docs.ecwid.com/api-reference/rest-api/orders/get-order', 'https://docs.ecwid.com/api-reference/rest-api/store-profile/get-store-profile', 'https://github.com/Ecwid/ecwid-api-docs/blob/master/includes/_webhooks.md']],
            'name' => 'Ecwid', 'priority' => 'production', 'connector' => EcwidConnector::class,
            'method' => 'webhook', 'auth' => 'app client-secret HMAC notification + store API token lookup', 'reliability' => 'limited',
            'limitations' => ['Reviewed order API has no immutable order currency or settled payment time; monetary mapping remains incomplete. Native reads and plan checks implemented; live verification pending.'], 'verification' => 'unverified', 'blocked_reason' => null,
        ],
        'lightspeed_c' => [
            'name' => 'Lightspeed C-Series', 'priority' => 'production', 'connector' => null,
            'method' => 'confirmation tracking where supported', 'auth' => 'to be determined', 'reliability' => 'lower',
            'limitations' => ['lower reliability'], 'verification' => 'unverified', 'blocked_reason' => 'C-Series requires an eligible Advanced/Professional plan and API access. Current C-Series order/payment/refund field contracts could not be verified from accessible official documentation; no tracking-page payment proof or Retail/E-Series endpoints are substituted.',
        ],
        'prestashop' => [
            'name' => 'PrestaShop', 'priority' => 'additional',
            'connector' => PrestaShopConnector::class,
            'profile' => ['api_version' => 'PrestaShop9 Webservice JSON', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null, 'native_read_api_implemented' => true, 'sources' => ['https://devdocs.prestashop-project.org/9/webservice/resources/orders/', 'https://devdocs.prestashop-project.org/9/webservice/resources/order_payments/', 'https://devdocs.prestashop-project.org/9/webservice/resources/order_slip/']],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private ZIP module must be installed'],
            'verification' => 'unverified',
        ],
        'opencart' => [
            'name' => 'OpenCart', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private OCMOD extension must be installed'],
            'verification' => 'unverified',
        ],
        'shopware6' => [
            'name' => 'Shopware 6', 'priority' => 'additional',
            'connector' => ShopwareConnector::class,
            'profile' => ['api_version' => 'Shopware6 Admin API', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null, 'native_read_api_implemented' => true, 'sources' => ['https://github.com/shopware/admin-api-reference/blob/main/docs/concepts/authentication-authorisation.md', 'https://github.com/shopware/admin-api-reference/blob/main/docs/guides/quick-start/05-order-management.md']],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'extension payload',
            'reliability' => 'high (extension not yet built)', 'limitations' => ['private extension must be installed'],
            'verification' => 'unverified',
        ],
        'magento' => [
            'name' => 'Magento / Adobe Commerce', 'priority' => 'additional',
            'connector' => MagentoConnector::class,
            'profile' => ['api_version' => 'Magento/Adobe Commerce2.4 self-hosted/PaaS REST', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null, 'native_read_api_implemented' => true, 'sources' => ['https://developer.adobe.com/commerce/webapi/rest/attributes/', 'https://developer.adobe.com/commerce/webapi/rest/use-rest/performing-searches/', 'https://github.com/magento/magento2']],
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private module must be installed'],
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
