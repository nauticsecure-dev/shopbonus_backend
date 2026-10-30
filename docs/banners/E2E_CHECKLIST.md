# Live Banner Studio — E2E checklist (Trello #25)

Exactly **10** end-to-end scenarios. Backend Feature suite: `tests/Feature/Banners/LiveBannerStudioE2ETest.php` (+ existing `BannerTest`, `BannerAiTest`). Frontend smoke: `e2e/banners-studio.spec.ts`.

| # | Scenario | Proof |
|---|----------|--------|
| 1 | Actual storefront — only ShopBonus widgets selectable (Shadow DOM) | `test_e2e01_storefront_embed_isolates_widgets_in_shadow_dom` + `/embed/v1.js` |
| 2 | Studio + overview — top bar, tools, tabs, searchable list, results | `test_e2e02_…` + `/dashboard/banners` + `/dashboard/banners/[id]/studio` |
| 3 | Draft privacy — merchant draft vs published visitor config | `test_e2e03_…` |
| 4 | Lifecycle — schedule / publish / pause / rollback + audit | `test_e2e04_…` |
| 5 | Trigger library — Fashion `/collections/*` + 20s + scroll + once/session | `test_e2e05_…` |
| 6 | Cart integrity — €94/€100 progress; static fallback; simulated label | `test_e2e06_…` |
| 7 | Results + branch costs — 70 impr, 10% CTR; €10→€13 / €4→€5.20 | `test_e2e07_…` |
| 8 | Security — cross-tenant, visitor, forged origin, pairing | `test_e2e08_…` |
| 9 | AI optimization — proposals + human review; no auto-publish | `test_e2e09_…` + `BannerAiTest` |
| 10 | Installation / ops — snippet health; Woo/Wix/BC/Shopify embed flags | `test_e2e10_…` |

## Run

```bash
cd shopbonus_backend && php artisan test --filter=LiveBannerStudioE2ETest
cd shopbonus_frontend && npx playwright test e2e/banners-studio.spec.ts
```

## Merchant path

1. Dashboard → Banners → create draft (schema-aligned config).
2. **Edit on website** → Live Editor (top bar, left tools, Design / Triggers / AI Insights).
3. Save draft → visitors still see last published version.
4. Preview / Publish / Schedule / Pause / Rollback.
5. Installation panel: snippet + origins + health (`not_seen` / `installed` / `stale`).
