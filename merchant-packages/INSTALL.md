# Shopbonus private merchant packages — 0.1.0

These are candidate installation packages, not live-certified integrations. No credentials are bundled. Back up the test store first. Real provider acceptance remains NOT RUN. Build with `php scripts/build-merchant-packages.php`; ZIPs and SHA-256 manifest are in `public/downloads/integrations/`.

## PrestaShop 9

Upload `shopbonus-prestashop9.zip` in Module Manager and install **Shopbonus order bridge**. Select one shop in multistore context before configuring it. In Shopbonus configure the exact shop ID, verified HTTPS installation URL, shop timezone and restricted GET Webservice key for shop_urls/orders/customers/addresses/currencies/order_payments/order_slip. Enter the returned webhook URL and signing secret in the module. The secret is never redisplayed; rotate it in both places together.

Run `php /absolute/store/modules/shopbonus/bin/worker.php` every minute as the store's OS user. A nonblocking process lock prevents overlapping workers. Order, history, payment and credit-slip hooks queue only shop/order references; checkout performs no outbound request. Workers send signed ID notifications; Laravel performs the native authenticated read and mapping. Retries keep their event ID but refresh their HMAC timestamp. New changes during delivery remain pending. Order validation itself never proves payment. Slip-only refunds remain Unknown under the native mapper.

For banners, set the backend HTTPS origin and public `pk_...` key, enable the banner, authorize the storefront domain and publish the campaign in Shopbonus. The module uses the native footer display hook. Themes lacking that hook need approved theme work. Disable/uninstall the module to stop placement and remove its configuration/outbox; stop its cron and disconnect the backend connection separately. Backend records are retained under the existing retention policy. Payment, refund, multistore and install/uninstall behavior require a genuine PS9 acceptance run.

## OpenCart 4

Source contract reviewed against OpenCart **4.1.0.3**; OpenCart 3 and 4.0 are not claimed compatible. Upload **`shopbonus.ocmod.zip`** in Extensions → Installer, install that entry, then install/configure **Shopbonus order bridge** under Extensions → Extensions → Modules. Keep the ZIP basename: OpenCart derives the extension folder/namespace from it. Grant the standard module access/modify permissions only to the intended administrator.

This first package supports **default store 0 only**. Enter the backend connection's webhook URL/signing secret and the actual timezone used by order database timestamps, then enable the bridge. Run `php /absolute/store/extension/shopbonus/bin/worker.php` every minute as the store's OS user. The worker supports the native mysqli database driver and reads local `config.php`; it has no public HTTP entrypoint. Events queue references, not customer payloads. Disable the module before stopping cron; uninstall removes events, settings and outbox.

Order IDs/references/customer fields and native local timestamps are delivered using Shopbonus signed v1. **Order status does not prove capture, settlement or refund:** payment status remains Unknown, no paid/refund timestamp or refund cash is invented. Only unconverted EUR/USD/GBP amounts with exact two-decimal precision are mapped; other currency conversion/precision stays incomplete. Native admin order API, authoritative payment/refund provider extension, bulk recovery and seven-gate Connected remain unsupported. Do not call this a completed paid-order integration.

Package 0.2.0 also sends **product and coupon snapshots** (D-110): at most every six hours the same worker reads the default store's `product`/`product_description` (default storefront language, `config_language`) and `coupon`/`coupon_product`/`coupon_category`, and posts them as signed pages to the same webhook URL plus `/catalog` (topic `catalog.snapshot`). Prices are in the store's base currency (`config_currency`); times use the configured timezone. Nothing is exposed on the shop. A snapshot only counts once every page arrived; a failure part-way keeps the previous snapshot. Coupon `date_start` is not sent (not-yet-active coupons appear active), and products without a name in the default language are skipped. Live store acceptance is NOT RUN.

Optional banner placement uses the native footer event and is disabled initially. Configure the public key/origin and enable it explicitly. Theme compatibility/editor acceptance remains NOT RUN.

## WooCommerce

Upload `shopbonus-woocommerce-banner.zip` using Plugins → Add New → Upload, activate, then configure Settings → Shopbonus Banner with the backend HTTPS origin and public `pk_...` key. Enable explicitly. Publish the campaign and authorize the storefront domain in Shopbonus. The plugin uses `wp_footer`; it adds no payment or editor authorization. Continue using WooCommerce's native signed order webhooks and restricted REST key for orders. Deactivation stops placement; plugin deletion removes its local settings. Multisite network-wide deployment/cleanup is not supported by this first package.

Plugin 0.2.0 loads `/embed/native-cart-v1.js` before the banner when WooCommerce is present, using WordPress's native Store API cart URL. Deploy that reader alongside the backend public embed. Checkout/account pages are excluded. Store API session totals drive progress; invalid/stale data falls back to static terms. Classic/Blocks notifications and bounded polling refresh the cart; custom themes should dispatch `shopbonus:cart-invalidated` after completed mutations. Two-decimal currencies only; no headless cart tokens, checkout attribution writes or genuine installed-store acceptance are claimed. Details: backend `docs/banners/NATIVE_CART_ADAPTERS.md`.

## Shopify

`shopbonus-shopify-theme-source.zip` contains **Theme App Extension source**, not a Shopify merchant-uploadable ZIP. Copy `extensions/shopbonus-banner` into the existing authorized Shopify app project, preserve that app's identity and run its Shopify CLI checks/deployment. Do not create a second app or change the registered app's scopes as part of this source import.

After installing that app on the authorized test store, the merchant enables **Shopbonus Banner** in the theme editor's App embeds, enters the public banner key/backend HTTPS origin, and saves. Campaign publication and storefront-domain authorization remain separate Shopbonus actions. Disable the embed and save to stop placement. Uninstall through Shopify and disconnect the backend connection to end integration access. The extension targets body, never checkout. The loader is idempotent and rejects non-HTTPS/credential-bearing origins. Theme CLI validation, app deployment, merchant activation and real storefront/editor acceptance are **NOT RUN** without the authorized app project and store.

## Acceptance required before release

Record exact platform/PHP/theme versions, package SHA-256, store reference, genuine authorization, paid/pending/failed orders, full/partial refunds, duplicate/tampered delivery, worker outage/retry, concurrent update, credential rotation, disconnect/uninstall and downstream ledger/contact results. For banners include desktop/mobile storefront, pairing/editor review-and-publish, consent and disabled/uninstalled checks. Synthetic local tests cannot substitute for these runs. No package marks a platform verified or a connection Connected.

Primary contracts: [PrestaShop modules](https://devdocs.prestashop-project.org/9/modules/introduction/), [OpenCart extensions](https://docs.opencart.com/developer-guide/extensions), [OpenCart 4.1 events](https://github.com/opencart/opencart/blob/4.1.0.3/upload/admin/model/setting/event.php), [WordPress Settings API](https://developer.wordpress.org/plugins/settings/using-settings-api/), [Shopify theme extensions](https://shopify.dev/docs/apps/build/online-store/theme-app-extensions/configuration).

## Assignment persistence (2026-11-08)

BigCommerce Stencil: deploy the cart-assignment migration, grant the server connection Carts read, enable `BIGCOMMERCE_CART_ASSIGNMENTS_ENABLED` in the authorized test environment and add `data-persist-attribution="true"` to the installed `native-cart-v1.js` tag. The existing renderer must follow that tag. This stores a server-side native cart/order association, not an order metafield. Do not expose management tokens.

Ecwid/Lightspeed E-Series: load `/embed/ecwid-attribution-v1.js` after the native Ecwid loader exposes OnAPILoaded and before updated `/embed/v1.js` in the authorized app/storefront bootstrap. The writer uses hidden order extra fields and removes them on withdrawal/expiry. It does not add a cart-total reader. No automatic app installation is supplied.

Full setup, privacy boundary and unimplemented-platform list: backend `docs/banners/ASSIGNMENT_PERSISTENCE_2026-11-08.md`. All genuine provider checkout/holdout/withdrawal acceptance remains NOT RUN.
