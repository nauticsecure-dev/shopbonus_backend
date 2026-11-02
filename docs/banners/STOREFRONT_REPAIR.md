# Live storefront review correction — 2026-10-31

Status: local implementation and focused tests complete; real merchant/provider acceptance remains PARTIAL. Backend HassanDev base 59916e9; frontend HassanDev base 3d0b0ad. Uncommitted alongside existing work; no migration, commit, push or deployment.

The studio center loads the installation's first allowed storefront origin in an iframe. Themes that deny framing can be opened in a separate window using Preview. Neither path substitutes a simulated product/theme/cart canvas. The mobile toggle resizes the actual storefront frame. Popup size/device verification is manual.

The public config supplies the trusted `editor_origin` from `FRONTEND_URL`. Configure that setting to the exact deployed SPA origin. Both listeners validate schema, key, nonce, origin and source. The embed accepts messages only from its parent or opener at that trusted origin. A single-use server exchange and authorized draft read precede the paired response; apply_config requires the active, campaign-bound, unexpired editor token. Tokens stay in embed memory. Reconnect and unmount remove editor listeners and timers; disconnect restores published content. Unsaved edits stream to the paired site. Save failure blocks Publish, and edits arriving during a save keep the draft dirty and block that publication attempt.

Published configuration is revalidated every 15 seconds while the storefront is visible, with no overlapping requests. Unchanged widgets retain their DOM and viewability measurement. Changed visible versions update without session-frequency suppression; pause removes them. Tracking consent revalidation remains separate. Outages preserve the currently loaded publication.

## Cart adapter contract

Only an installed, trusted storefront integration may provide `window.ShopBonusCart`. The embed does not read orders, infer prices from theme DOM, or accept legacy bare numeric totals as current cart evidence.

```js
window.ShopBonusCart = {
  getSnapshot() {
    return {
      schema: "shopbonus.cart.v1",
      total_minor: 9400,
      currency: "EUR",
      observed_at: Date.now(), // observation time of the actual current native cart
    };
  },
  subscribe(update) {
    // Call update after a native cart add/remove/quantity/currency change.
    // Return the adapter's unsubscribe function if supported.
    return nativeCartSubscription(update);
  },
  setAttribution(value) {
    // Existing consent-gated checkout attribution integration.
  },
};
```

The example documents a host integration contract; `nativeCartSubscription` is supplied by that integration, not by ShopBonus. The adapter must not renew observed_at without observing the native cart. A `shopbonus:cart-changed` window event also requests an update; the four-second evaluator expires stale display even without an event. The embed requires integer nonnegative safe minor units, ISO currency, and an observation no older than 30 seconds and not in the future. Progress uses the linked scoped offer's threshold and matching currency from the backend; missing/stale/mismatched evidence shows static terms. Currency formatting uses the currency's minor-unit precision. Native Shopify/Woo/etc adapters and signed paid-order journeys remain platform-specific acceptance work; no support claim follows from this generic bridge.

## Position and layers

Corner handles resize bounded width/minimum height, persisted in placement. Dragging emits one history entry at gesture completion (including authenticated parent-assisted completion when a pointer leaves a frame), with offsets limited to the server schema; proximity to stable corners snaps to that anchor. Inline placement uses a configured selector without mutating native HTML; missing or detached targets hide the widget and warn the editor. Selecting a replacement target or corner restores it through the normal config update. Geometry is reevaluated on the existing four-second tick; theme-specific interaction/performance still needs live acceptance.

Optional ordered text/image/CTA layers control visibility, alignment, font size and percentage width. Each has separate editor controls; text and CTA fields and merchant image uploads remain independently editable. AI now generates three layer layouts with strict bounded validation and unique layer types. Existing copy, URLs, uploaded imagery and financial terms are preserved; no generated image or unapproved offer promise is invented. Accept creates a private test draft, with a link to edit it. Human Publish remains required. Genuine model output, brand approval and merchant acceptance are NOT RUN.

Viewability without IntersectionObserver remains unknown and explicitly marks `data-viewability="unsupported"`; no impression is emitted. Verified measurements still require 50% continuous visible intersection for one second.

## Evidence

- `node --test tests/embed-lifecycle.test.cjs`: security, popup pairing, expiry, consent, cart subscriptions/freshness/currency and publication/pause/rollback regressions.
- `tests/Feature/Banners/BannerTest.php`: scoped linked-offer metadata, persisted dimensions/layers and rejection of invalid sizes/duplicate layers.
- `tests/Feature/Banners/BannerAiTest.php`: generated layers survive acceptance into a private draft; no automatic publication.
- Frontend `e2e/banner-live-repair.spec.ts`: controlled browser/API/storefront fixtures using the actual public embed; not genuine merchant/provider E2E.
- `docs/api/BANNER_STUDIO_HTTP_CAPTURE.json`: actual local HTTP responses from isolated SQLite fixtures; reproduction script in `scripts/capture-banner-studio.php`.

Final executed test results are recorded in HANDOFF/PROGRESS. Whole-card Done is not claimed. Next: verify deployed FRONTEND_URL, installation framing/opener policy, real cart adapter freshness/currency and actual merchant desktop/mobile themes; run genuine AI and human design acceptance.


Final local verification — 2026-11-01: 35 focused PHP tests, 33 passed and 2 existing database-specific skips, 5,452 assertions. Twelve embed lifecycle tests passed. All eight Chromium fixture regressions passed (1.0m): Save 409/500 failure gates, real iframe embed/live edits/publish and mobile frame, resized dimensions/layers including frame-exit completion, inline target recovery, forged origin/source rejection, native popup/opener pairing and stable corner snapping. Whole frontend TypeScript and targeted ESLint passed. Targeted PHPStan: zero errors; targeted Pint and diff whitespace checks passed. Five real HTTP captures include public config, authorized/unauthorized editor draft and completed AI show/index (explicitly synthetic provider; no live model claim). Capture database/server removed; dedicated frontend test server stopped. Whole-repository suites/build/MySQL/real storefront and provider acceptance NOT RUN. Uncommitted on HassanDev, backend base 59916e9/frontend base 3d0b0ad; no migration or deployment. Exact next action: review the isolated patch, deploy/configure FRONTEND_URL and verify actual merchant themes/opener policy, platform cart adapters and genuine model/human design acceptance.

Final cleanup recheck: frontend TypeScript passed after removing only this task's temporary Next.js type include entries. Final OpenAPI rerun passed (3 tests, 5,123 assertions; overlapping the focused suite). Final touched-file whitespace check passed. No banner capture SQLite database or capture log remains.
