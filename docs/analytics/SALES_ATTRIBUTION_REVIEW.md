# Sales attribution review follow-up — 2026-10-31

Implementation is local and uncommitted. Existing unrelated changes in both checkouts are preserved. No migration, commit, push, payment or deployment is part of this change.

## Reporting and evidence

The merchant analytics page reads the existing workspace overview and attribution-order endpoints. Date selection queries 7/30/90 business-calendar days. Daily paid order counts and net sales are zero-filled, grouped by currency and DST-safe. Voucher figures are period activity, not a cohort conversion rate. Order attribution categories open paginated, permission-scoped order evidence. Voucher details and banner/experiment configuration are real navigation. Loading, errors, retry, empty results and report generation time replace sample figures and simulated notifications.

Voucher attribution uses `VoucherCode::hashFor(webshop_id, code)` against `code_hash`, with both voucher and code scoped to the receiving shop. The first attribution link freezes the evidence method/source. Unattributed orders can gain their first evidence; later replays cannot switch that evidence. An order-row lock serializes resolution and the label, click reference, link and journey are committed together. Financial amounts and refund events remain updateable. This policy also repairs a previously divergent label from its existing immutable link on replay; historical rows require replay before that repair is visible.

Network journeys retain evidence snapshots; reporting joins current orders for net revenue, including full refunds, without requiring attribution replay. Shop contribution, platform GMV, affiliate commissions and top-shop network results separate currencies. Legacy scalar money fields are null for mixed currencies. The deprecated all-wallet reserved minor total is null; `wallet_liabilities` separates `kind` and `unit`, available/reserved/pending, and outstanding = available + reserved. Pending remains separate and points have no assumed EUR conversion. Direct settled fees explicitly use EUR. These are components, not a claim of platform profit.

## CMP and storefront contract

The host must expose `window.ShopBonusConsent.getConsentId()` returning the opaque ID created by the existing versioned `/api/v1/public/cookie-consents` API, or null. The first-party cookie helper now supplies this adapter. A boolean/global flag is insufficient. The embed verifies the latest record through origin-checked banner config before touching optional browser storage, assigning experiments or measuring events. Both analytics and marketing choices must be enabled under a current policy, or a policy that does not require renewal. No policy, missing/withdrawn decision, required re-consent or validation outage fails closed.

On CMP changes dispatch `window.dispatchEvent(new CustomEvent('shopbonus:consent-changed'))`. The embed immediately stops collection and revalidates; it also checks on focus and every 55 seconds, with a 60-second local permission lease. Assignment and consented event requests carry `consent_id`; the backend verifies it again. Revocation discards pending events, removes controlled optional storage, clears cart metadata and calls the existing experiment-revoke endpoint with the former visitor UUID. In-flight requests can already have been accepted before withdrawal; their UUIDs remain deduplicated server-side. Other host CMPs must implement the same adapter; no automatic consent is inferred from their presence.

Registered storefront origins receive credential-free embed CORS, including JSON preflight. Actual requests still validate the specific installation key and origin. The SPA credential allowlist is unchanged.

The embed calls the existing experiment assignment API before requesting personalized config and carries assignment tokens on every event. Holdout assignments also reach the cart bridge, even though they render no banner. `window.ShopBonusCart.setAttribution({ click_id, assignment_token })` must persist these values into the provider's cart/order metadata; calling it with `{}` must clear them. A `shopbonus:attribution` event and `ShopBonusEmbed.getAttribution()` expose the same values for host adapters. If multiple experiments are active, the first server-ordered assignment is the primary order assignment because the existing order schema supports one assignment token. Do not interpret this as multi-experiment causal measurement.

Provider mappings already read Shopify `shopbonus_click_id` / `shopbonus_assignment_token`, WooCommerce `_shopbonus_click_id` / `_shopbonus_assignment_token`, and generic signed orders `attribution.click_id` / `attribution.assignment_token`. A live provider/storefront bridge must actually write those fields. The bridge contract and local holdout tests are not evidence of an installed provider integration. Missing propagation remains unattributed; `causal_uplift` remains null.

## Delivery and viewability

Event UUIDs remain in a bounded memory queue until HTTP acknowledgement: 200 queued events maximum, 50 per batch, at most five attempts per event and two minutes of retry age, with capped backoff. IDs and occurrence times survive retries. Page-exit requests use keepalive; browser termination can still lose memory-only events. Withdrawal intentionally drops queued tracking. Consented click IDs derive from shop + event UUID via server HMAC so a retry after a lost response returns the same click ID. They are persisted only after consent, expire locally after at most 30 days, and remain subject to the server click expiry window.

Ingestion caches consent, campaign/experiment, assignment and version lookups only within one batch. Each daily statistics row is initialized once per batch; individual event inserts, rollup increments and click creation remain transactional, with the unique event UUID protecting concurrent/replayed deliveries. No consent or campaign state is cached between requests.

No IntersectionObserver means unknown viewability and no impression event. Supported impressions require at least half visibility for one continuous second while the document is visible and the element remains connected. CTA and dismissal stay separate events.

## External acceptance still open

Moneybird has no delivery/reconciliation client in this checkout. `FundingInvoice` and `prepareInvoices` produce internal drafts only. `invoice_reconciliation` explicitly reports `provider_not_verified`, internal draft/reference counts and no verified provider issuance. No external reference is promoted into proof of delivery or reconciliation. OPEN-FINANCIAL-LEGAL and real Moneybird account/configuration remain prerequisites for implementing and verifying provider invoice issuance, idempotent delivery, status polling and reconciliation. Existing draft creation and funding obligations are preserved.

Live storefront consent-to-paid-order propagation, production randomized holdout measurement, Moneybird delivery/reconciliation and the one-million-event staging load test are NOT VERIFIED by local fixtures. Acceptance remains PARTIAL until those are executed.

## Validation

See `tests/Feature/Analytics/SalesAttributionE2ETest.php` for encrypted voucher matching, immutable evidence replay, refund reporting before replay, mixed currencies/wallet units, consent withdrawal/renewal, click-response retries, CORS isolation and range bounds. Existing banner/experiment and analytics suites preserve voucher, wallet, payment and tenant behavior. `node --test tests/embed-lifecycle.test.cjs` executes the public embed in a controlled DOM/timer/network harness. Browser fixtures are in the frontend `e2e/merchant-analytics.spec.ts`; these must not be described as live provider evidence.

Actual local HTTP examples are captured in `docs/api/SALES_ATTRIBUTION_CAPTURE.json` using an isolated in-memory SQLite fixture per request, with a temporary loopback-only server. Its test actor bypass exists only in that temporary router and is removed after capture. Runtime validation outcomes are recorded in TASKS/PROGRESS/HANDOFF when checks finish.

Executed verification commands (from the corresponding checkout):

```text
php artisan test --compact tests/Feature/Analytics tests/Feature/Banners tests/Feature/Cms/CookieConsentTest.php tests/Feature/Api/OpenApiCoverageTest.php
php artisan test --compact tests/Feature/Analytics/SalesAttributionE2ETest.php tests/Feature/Banners tests/Feature/Api/OpenApiCoverageTest.php
node --test tests/embed-lifecycle.test.cjs
npm run typecheck
npx playwright test e2e/merchant-analytics.spec.ts --reporter=list
```

Targeted Pint and PHPStan cover the touched services/controllers/middleware. The existing 2,000-event ingestion test keeps its 60-second budget unchanged. Earlier runs missed that budget and drove the batch-local query/write reductions; no reduced sample size or increased threshold substitutes for passing it. These runs are not the required one-million-event staging load test.
