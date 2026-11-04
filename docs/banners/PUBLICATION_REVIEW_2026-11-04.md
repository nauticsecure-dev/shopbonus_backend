# Storefront publication finding reconciliation — B76

Audited branch `frank/moneybird-experiment-followup` at `765bc80b703a00af2d7ccec7898b59acc0408d71` (2026-11-04). Backend scope only; no provider writes or frontend changes. The high finding is **PARTIAL**, not wholly unresolved and not fully complete.

| Finding | Current source evidence | Remaining requirement |
| --- | --- | --- |
| BigCommerce Widgets placement missing | Implemented in source `346b3bb`: `NativeBannerPublisher::install()` creates/read-verifies template, widget and placement; `bind()` recovers uncertain creates; `reconcile()` reads current configuration; `remove()` verifies ownership and removes in reverse dependency order. `shopbonus:banner-native` is the authorized operator entry point; defaults OFF. | Genuine provider installation, storefront delivery, failure recovery and uninstall acceptance remain NOT RUN. Do not list placement as an absent backend implementation. |
| Lightspeed C-Series automatic publication | Current connector is native order ingestion; publisher readiness explicitly requires manual theme/Web Extras installation. The profile's reference to an upstream Shop/scripts resource does not establish a reviewed write schema, ownership rule or cleanup contract. | Supported authoritative native write/delete contract and authorized installation remain unestablished. The current official developer shop endpoint returned403 during review; no guessed resource fields are implemented. |
| Lightspeed E-Series automatic publication | Ecwid native app settings require `customize_storefront` and a self-hosted `customJsUrl`; official custom-app guidance directs configuration updates through Ecwid admin/API Support. Native app installation remains open. | Authorized native app configuration/installation and storefront acceptance. Existing order-read credentials alone do not establish this authority. |
| Squarespace automatic publication | Official Code Injection is merchant-site configuration. The current Custom Code Specification describes stable HTML/CSS properties; it is not a remote publication/cleanup API contract. | Merchant installation or a documented authorized native publication interface; Commerce credentials do not prove it. |
| API inventory | Owner review reports470 runtime entries/471method-path operations resolved; current inventory regressions pass. | No inventory change required by this finding. |

Executed: `php artisan test tests/Feature/Banners/NativeBannerPublisherTest.php tests/Feature/Api/ReleaseInventoryTest.php` — **14PASS/838 assertions** (12native-publication tests +2inventory tests). Explicit synthetic transport exercises real backend domain services and state guards; it is not provider acceptance. The preceding full source-build CI41791620176 passed at765bc80: MySQL1450PASS/4skip; SQLite1425PASS/29skip; formatting/static/audit green. No new code, routes, migrations, credentials, packages or runtime settings were changed by B76.

Implementation and operator commands: [NATIVE_PUBLICATION.md](NATIVE_PUBLICATION.md). PrestaShop/OpenCart candidate installation packages remain present; this audit neither removes nor recertifies their live installation acceptance.

Primary sources rechecked:

- https://ecom-support.lightspeedhq.com/hc/en-us/articles/220661887-Adding-JavaScript
- https://developers.lightspeedhq.com/ecom/endpoints/shop/ (403 on this review)
- https://docs.ecwid.com/develop-apps/app-settings
- https://docs.ecwid.com/get-started/add-more-features-to-your-custom-app
- https://support.squarespace.com/hc/en-us/articles/205815908-Customize-parts-of-your-site-with-code-injection
- https://developers.squarespace.com/custom-code/about

Next engineering boundary: implement a remaining native publisher only against an established provider write/install/cleanup contract, preserving the current tenant/authority/idempotency checks. Real installed apps/storefront components remain outside this backend-only audit. No automatic path is marked implemented merely from manual setup instructions.

B85 recheck on source parent2e7da03: BigCommerce install/read placement/recovery/remove remains implemented, with the native publisher regressions passing. C-Series official JavaScript guidance still directs merchant Web Extras/theme installation; the shop endpoint did not expose a reviewed remote write/cleanup contract in this recheck. Ecwid/E-Series app configuration updates require native app scopes/endpoints and merchant/admin/API Support configuration; Commerce order credentials do not confer app-configuration control. Squarespace's Custom Code Specification is a markup/stability contract, not a code-injection write/delete API. These automatic publisher gaps remain OPEN. No undocumented endpoint, private-admin-session automation or frontend/native script implementation was added. Real installed storefront acceptance remains NOT RUN. B85 adds Adobe IMS renewal separately; it does not clear these publisher findings.
