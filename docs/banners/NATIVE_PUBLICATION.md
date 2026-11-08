# Native banner publication — backend boundary B73

Branch `frank/moneybird-experiment-followup`; B72 source `6a8c5c3`. This boundary implements BigCommerce content provisioning using the existing ShopBonus embed. It does not implement or change browser rendering, app-store deployment, or frontend controls. Actual provider/storefront execution is **NOT RUN**.

## BigCommerce

Default **OFF** (`BIGCOMMERCE_BANNERS_ENABLED=false`). Senior deployment configuration must provide a public bare HTTPS `APP_URL`, a verified single-store connection, Information read and Content modify permission, an approved funded/live campaign, and an existing merchant-reviewed native theme region. Multiple storefront/channel mappings are refused; this boundary explicitly targets channel 1. It does not modify a merchant theme to create regions.

Prepare a local target JSON file, for example:

```json
{"template_file":"pages/home","region":"home_below_menu","sort_order":1}
```

The region is illustrative: use a region actually returned by the merchant's native theme. Supported templates are home/category/product/brand/page. Optional `entity_id` is a positive native ID string; home must omit it. A missing entity ID targets that template generally. No arbitrary HTML, JavaScript, endpoint, merchant URL or native resource ID is accepted as a publication target.

Run as a trusted operator with an active owner/authorized member having both integration-management and settings-management permission, or active Super Admin. `--actor` is the existing user's public ID; shell access is also privileged. No new public API mutation was introduced.

```sh
php artisan shopbonus:banner-native readiness bigcommerce
php artisan shopbonus:banner-native install CONNECTION_ULID --actor=USER_ULID --file=/absolute/reviewed-target.json
php artisan shopbonus:banner-native reconcile CONNECTION_ULID --actor=USER_ULID
php artisan shopbonus:banner-native remove CONNECTION_ULID --actor=USER_ULID
```

Installation reads native store identity and current theme regions, freezes the source/domain/credential generation/public key/script origin/target in append-only audit events, then creates and reads back:

1. Widget template loading `/embed/native-cart-v1.js` (`data-platform="bigcommerce"`) and then the existing `/embed/v1.js` loader, both `defer` so the cart reader runs first and cart changes drive live progress. B92: plans frozen before 2026-11-05 keep their immutable original audit snapshot. Re-running install with the same target automatically upgrades their owned template in place; an explicit `shopbonus:banner-native upgrade CONNECTION --actor=USER` does the same. No widget/placement removal or reinstallation is required.
2. Widget with this workspace's public installation key.
3. Active placement in the frozen page/region.

Writes have fixed provider origins, TLS verification, no redirects or retries, 3-second connect/8-second total timeouts and bounded response bodies. Durable step intents are committed before egress. Repeating the same frozen installation reuses verified UUIDs and does not repeat a successful create. Native names, channels, parent IDs, template/configuration and placement must match exactly. A different target or credential generation cannot silently replace the plan. Native configuration acknowledgement never proves that a browser loaded/rendered the banner; responses retain `storefront_delivery: not_observed`. The existing runtime configuration API handles later internal campaign publication/pause; no repeated native installation is required.

### Lost responses and cleanup

A timeout, invalid response or failed readback leaves an uncertain intent. **Do not repeat the native POST or manually clear its audit history.** Locate the created object's UUID through the authorized native console and bind it; the service reads and verifies the exact frozen content before adoption:

```sh
php artisan shopbonus:banner-native bind CONNECTION_ULID --actor=USER_ULID --step=template --remote-id=NATIVE_UUID
php artisan shopbonus:banner-native install CONNECTION_ULID --actor=USER_ULID --file=/absolute/reviewed-target.json
```

Steps are `template`, `widget`, `placement`. If no native object can be proven, remain blocked for operator/provider investigation. Cleanup requires uncertain creates to be bound first. A durable removal marker prevents installation from racing cleanup. Removal verifies ownership, deletes placement/widget/template in reverse order and requires native GET 404 absence. A lost delete response is reconciled by reading absence; a still-present object after an uncertain delete is blocked, not retried automatically. Credential rotation is permitted for cleanup only when the current connection still proves the same store/domain/public key. Remove before disconnecting/revoking access; provider-side cleanup may otherwise be required. Removing does not delete campaign records, wallet data or audit history.

## Lightspeed C-Series (Shop Scripts)

Default **OFF** (`LIGHTSPEED_BANNERS_ENABLED=false`). Uses the documented [Shop Scripts](https://developers.lightspeedhq.com/ecom/endpoints/shopscript/) resource: `POST /shop/scripts.json`, `GET /shop/scripts/{id}.json` (wrapper `shopScript`), `DELETE` (204). The connection's API key must be allowed to manage shop scripts (the docs state no scope name; a 401/403 is reported as `native_banner_permission_missing`). Same prerequisites as BigCommerce otherwise: verified shop identity and owned domain (`testConnection`), live funded campaign, verified storefront origin allowed, public bare HTTPS `APP_URL`.

One owned script is created with `location: body` and an inline `body` that adds the existing `/embed/v1.js` loader with this workspace's `data-shopbonus-key` (Shop Scripts cannot set attributes on a `url` script, and the loader reads its key from its own tag). No target file is needed.

```sh
php artisan shopbonus:banner-native readiness lightspeed_c
php artisan shopbonus:banner-native install CONNECTION_ULID --actor=USER_ULID
php artisan shopbonus:banner-native reconcile CONNECTION_ULID --actor=USER_ULID
php artisan shopbonus:banner-native bind CONNECTION_ULID --actor=USER_ULID --remote-id=SCRIPT_ID
php artisan shopbonus:banner-native remove CONNECTION_ULID --actor=USER_ULID
```

The documented create response does not promise an ID. If none comes back the create stays uncertain: find the script in Lightspeed and `bind` its numeric ID; only a script whose `body`/`location` exactly match the frozen plan (and has no `url`) is adopted. Never repeat the install hoping for a second create. Plans, intents, observations and removal are `integration.banner_ls_*` audit events (`LightspeedScriptPublisher`). Removal deletes and then requires GET 404. Provider execution is **NOT RUN**; `storefront_delivery` stays `not_observed`.

## Remaining native installation paths

| Platform | Current backend readiness | Required real setup |
| --- | --- | --- |
| Lightspeed E-Series / Ecwid | `native_app_required`; automatic writes unsupported | Authorized native app installation with `customize_storefront` and developer-configured `customJsUrl`. An ordinary order API token does not authorize changing native app endpoints. |
| Squarespace | `manual_installation_required`; automatic writes unsupported | Merchant Code Injection on an eligible site/plan. Commerce/Orders credentials do not establish code-injection authority. |

Use `readiness lightspeed_e`, `readiness ecwid`, or `readiness squarespace` for explicit machine-readable states. These are setup requirements, not shipped native apps or verified installations. The complete automatic-publication scope remains **PARTIAL**. The backend will not invent remote write APIs or promote these paths to available.

## Evidence and acceptance

`NativeBannerPublisherTest` exercises actual domain services and durable audit intents with explicitly synthetic HTTP transport; it does not contact providers. See `docs/qa/B73_TEST_RESULTS.json`. `docs/qa/B73_NATIVE_BANNER_HTTP_CAPTURE.json` contains seven actual private Laravel TCP responses for catalogue/setup/detail and authorization/validation paths, reproduced by `python3 scripts/capture-native-banner.py`. No provider writes occur in that capture. API path shapes are unchanged.

Before release, independently run merchant authorization/content permissions, actual template/widget/placement install, browser visibility/consent/runtime loading, campaign publish/pause propagation, failure reconciliation and uninstall on the recorded deployed build. Record native IDs/request IDs, provider confirmation and browser evidence. All remain **NOT RUN**; no seven-gate Connected promotion or provider acceptance follows from fixtures.

## Primary contracts reviewed

- [BigCommerce Widgets overview](https://docs.bigcommerce.com/developer/docs/admin/widgets-and-scripts/guide/overview)
- [Create widget template](https://docs.bigcommerce.com/developer/api-reference/rest/admin/content/widgets/widget-template/create-widget-template)
- [Create widget](https://docs.bigcommerce.com/developer/api-reference/rest/admin/content/widgets/widget/create-widget)
- [Create placement](https://docs.bigcommerce.com/developer/api-reference/rest/admin/content/widgets/placement/create-placement)
- [Read theme regions](https://docs.bigcommerce.com/developer/api-reference/rest/admin/content/widgets/regions/get-content-regions)
- [Lightspeed C JavaScript installation](https://ecom-support.lightspeedhq.com/hc/en-us/articles/220661887-Adding-JavaScript)
- [Ecwid app settings](https://docs.ecwid.com/develop-apps/app-settings)
- [Squarespace Code Injection](https://support.squarespace.com/hc/en-us/articles/205815908-Customize-parts-of-your-site-with-code-injection)

## B92 — Legacy BigCommerce cart-reader upgrades

An owned, fully reconciled legacy installation can be upgraded with `shopbonus:banner-native upgrade CONNECTION --actor=USER`, or by re-running install with exactly the same target. The existing default-off BigCommerce write gate and active merchant/Super Admin, verified domain/store, credential generation and public-key guards remain required. The reviewed operation is `PUT /v3/content/widget-templates/{uuid}` with `create_new_version: false`: BigCommerce documents this as updating the current template and forcing existing widgets onto that update. Existing remote UUIDs/placements are preserved.

Before writing, all three owned resources must match their exact frozen configuration. The original audit plan is never edited. A new append-only `cart_upgrade_intent` freezes the ordered cart-reader/renderer template before the one PUT. Exact subsequent GETs reconcile the template, widget and placement and append `cart_upgrade_observed`. Losing a response never triggers another PUT: repeat upgrade/reconcile reads only. If the provider still reports the old or a foreign template, the operation remains unresolved and requires operator investigation; no success or safe retry is invented. Removal verifies the upgraded expectation and still deletes in dependency order.

No Laravel route, migration or API contract changes. Local synthetic transport checks do not establish installed-store propagation, CSP/theme compatibility or actual shopper delivery. Genuine provider acceptance is NOT RUN. Source reviewed2026-11-07: https://docs.bigcommerce.com/developer/docs/admin/widgets-and-scripts/guide/versioning and https://raw.githubusercontent.com/bigcommerce/api-specs/main/reference/widgets.v3.yml .
