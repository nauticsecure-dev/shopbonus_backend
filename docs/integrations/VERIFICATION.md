# Provider verification checklist

B77: [Candidate Adobe asynchronous couriers and scoped SaaS REST](ADOBE_ASYNC.md) now ship as PaaS module/private App Builder source plus exact-tenant IMS/Store-header reads. Real Adobe registration/installation/paid/refund/cleanup acceptance remains NOT RUN. Earlier “no module/SaaS implementation” statements are superseded by this source boundary.

B74: [Explicit stored provider evidence review](PROMOTION_EVIDENCE_REVIEW.md) normalizes selected conditions/exclusions into reviewed offer versions; permission remains separate and real provider acceptance is NOT RUN.

B73: [native banner backend publication](../banners/NATIVE_PUBLICATION.md) adds opt-in BigCommerce Widgets install/read reconciliation/verified binding/removal with durable uncertain-effect guards. Defaults OFF. Lightspeed C/E-Series and Squarespace retain explicit native app/manual requirements; automatic writes remain unsupported. Actual provider/storefront acceptance remains NOT RUN.


B72: [CCV native reads / Mijnwebwinkel partner-bridge boundary](REMAINING_CONNECTORS.md) supersedes earlier “no connector” statements. CCV paid/settlement/refund-time evidence remains limited; discovery is by creation window. Mijnwebwinkel is a ShopBonus signed bridge only, not native partner API integration. Real provider/bridge acceptance remains NOT RUN.


B71: [automatic native coupon configuration checks](COUPON_VERIFICATION.md) now cover Shopify, BigCommerce legacy v2, Ecwid and Lightspeed E-Series alongside WooCommerce. Unknown restrictions/caps remain manual. This implements authenticated reads only; provider-backed checkout/creation/redemption acceptance remains NOT RUN and coupon-write/banner readiness is unchanged.


B65 review follow-up (2026-11-03): see [feature evidence and monetary definitions](FEATURE_PROVENANCE.md). Per-feature provenance is now exposed, without promoting documentation dates or connection labels into live proof. Shopware/Magento order-bound line items and breakdowns already exist; Ecwid reported refunds remain distinct from settlement. Ecwid reported current tax/shipping/discount, Lightspeed C gross breakdown and Shopify distinct current shipping are mapped. Unsupported cash/timestamp details remain null. Candidate PrestaShop9/OpenCart4.1 packages and Shopify extension source are present; genuine installation/publication/order/refund/uninstall acceptance is NOT RUN. Adobe async/SaaS source boundary is documented under B77; genuine deployment remains NOT RUN.

2026-11-03 App Store follow-up: [local changes, executed evidence and remaining engineering/acceptance gaps](APP_STORE_FINDINGS_2026-11-03.md). [Exact server environment and workspace credential keys](ENVIRONMENT.md). Pause/resume has local evidence only; no provider row below is promoted.

Every platform in `config/integrations.php` stays `verification: unverified` until someone with real store or sandbox access runs this checklist and records the evidence below. Adapters were written against the providers' published documentation and are tested with realistic fixtures. That proves the logic, **not** the live provider behaviour. Never mark a platform verified from fixtures (Trello #1 / #31).

Full API/webhook/mapping contracts: [PLATFORM_API_SPECS.md](PLATFORM_API_SPECS.md).

## Per platform

1. Create a connection in a staging workspace (`POST /api/v1/workspaces/{w}/connections`) and configure the webhook in the real store using the returned instructions.
2. **Ping / setup event**: note whether the store sends one and whether it is signed. Expected: the connection moves to `testing`.
3. **Genuine paid order** (real payment method in test mode): run native verification recovery so quality gates can advance. Record the event's `result` (`GET .../events`), with no PII in the record.
4. **Signature**: alter one byte of a captured body and resend it. It must get 401. Resend the original unchanged: 200 `duplicate: true`.
5. **Retries**: make the endpoint fail once (e.g. stop the worker) and confirm the provider retries with the same delivery/event ID.
6. **Payment states**: create pending, failed and cancelled orders. Confirm the mapped `payment_status` never reads `paid` for them.
7. **Refund**: full and partial. Confirm the topics received and the mapped status.
8. **Field availability**: which of reference, first name, email, amount, currency, phone are present, and which are redacted (Shopify protected customer data).
9. **Rate and timing**: note the provider's timeout and retry schedule against our 202 response time.
10. Record the results in the table below and set `verification` in `config/integrations.php` to `verified:<date>` (or list the limitations) in the same commit. Set `profile.last_live_verified_at`.

## Evidence log

| Platform | Date | Store/sandbox | Steps passed | Limitations found | Verified by | Commit |
| --- | --- | --- | --- | --- | --- | --- |
| Shopify | — | — | NOT RUN | Theme extension source shipped; authorized app deploy/activation and protected customer data acceptance pending | — | — |
| WooCommerce | — | — | NOT RUN | Candidate WP banner plugin shipped; native installation/theme/editor acceptance pending | — | — |
| Wix | — | — | NOT RUN | Backfill via orders/search; paid_at null; transaction createdDate is not settlement evidence | — | — |
| BigCommerce | — | — | NOT RUN | Native catalogue reads and opt-in Widgets lifecycle implemented locally; actual provider import, installation, storefront delivery and uninstall remain NOT RUN | — | — |
| Ecwid | — | — | NOT RUN | Total/refundedAmount use observed authenticated shop currency; historical currency/cash settlement/time unverified | — | — |
| Lightspeed C-Series | — | — | NOT RUN | Plan-gated API; refund cash unmapped; paid_at null | — | — |
| Lightspeed E-Series | — | — | NOT RUN | Shared authenticated Ecwid API contract; paid_at/refund cash unavailable | — | — |
| Squarespace | — | — | NOT RUN | API-key polling implemented; no settlement timestamp, donation/POS/test-mode import or native banner publisher | — | — |
| PrestaShop | — | — | NOT RUN | PS9 candidate ZIP shipped; native read credentials/CLI worker required; install/uninstall/theme acceptance pending | — | — |
| OpenCart | — | — | NOT RUN | OC4.1 default-store candidate extension shipped; payment Unknown, no native order API/refund proof | — | — |
| Shopware 6 | — | — | NOT RUN | Native app lifecycle separate from HMAC bridge | — | — |
| Adobe Commerce / Magento | — | — | NOT RUN | Prefer async I/O Events; SaaS unverified | — | — |
| Shopbonus format v1 (module) | — | — | NOT RUN | Candidate package signer round-trip is synthetic contract evidence only | — | — |

## Platforms without a connector

Mijnwebwinkel and CCV remain without a connector; see `blocked_reason` in `config/integrations.php`. Mijnwebwinkel requires an approved partner contract/schema; its [published partner guidance](https://help.mijnwebwinkel.nl/en/articles/856322) is not a complete order/payment API specification. CCV's [native REST contract](https://demo.ccvshop.nl/API/Docs/) is now identified (public/secret key, HMAC-SHA512); adapter implementation, store binding and authoritative update/refund handling remain outstanding engineering work. No fictitious generic bridge was registered for either platform.

Local implementation/evidence, package links and remaining acceptance work: [2026-11-03 review](ADAPTER_REVIEW_2026-11-03.md). Candidate packages do not satisfy genuine provider acceptance and no platform verification flag was promoted.

## Affiliate networks (B10)

Neither adapter has been run against a live account. Before enabling production sync, verify each item with a real publisher account and record the date, evidence and SHA here.

| Check | Daisycon (`DaisyconAdapter`) | TradeTracker (`TradeTrackerAdapter`) |
| --- | --- | --- |
| Auth works; bad credentials → `reauthentication_required` | Fixture-tested (401 → reauthentication_required); NOT RUN live. OAuth 2.1 + PKCE required by Daisycon; adapter stores bearer `access_token` (+ optional `refresh_token`) | Fixture SOAP transport tested; live NOT RUN; disabled by default (`TRADETRACKER_API_ENABLED`) |
| Program list paths/fields: `id`, `name`, `url`, `commission.type/value/restricted`, `cashback_allowed`, `tracking_url` | Mapped to `GET /publisher/{id}/programs` (FAQ 2025–2026); NOT VERIFIED live | Pinned-WSDL/export fields `ID`, `URL`, `info.commission.saleCommissionVariable/Fixed`, `info.policyCashbackStatus`, `info.trackingURL`: NOT VERIFIED |
| Transaction pull | `GET /publisher/{id}/transactions?start&end&page&per_page`; NOT VERIFIED live | Registration + assessment date polling; NOT VERIFIED live |
| Sub-ID parameter on the tracking link (`ws` / `r`) reaches conversions as `parts[].subid` / `reference` | NOT VERIFIED | NOT VERIFIED |
| Transaction statuses mapped (`approved/pending/open/disapproved` → approved/pending/rejected; TradeTracker `accepted/pending/rejected/cancelled`) | NOT VERIFIED | NOT VERIFIED |
| Cashback/sub-ID use permitted by each program's terms | Check per program | Check per program |
| Postback format and source IPs | NOT VERIFIED | NOT VERIFIED |
| Sandbox | `https://services-sandbox.daisycon.com` (15 req/min/IP) | Account-issued SOAP endpoint |

B10b implementation/configuration handoff: [TRADETRACKER.md](TRADETRACKER.md). Owner explicitly assigned credentials to the senior developer. Main transport is fixture-tested; live verification remains NOT RUN and is not a prerequisite for writing the adapter code.

## Review delivery and acceptance boundary (2026-11-03)

See [review acceptance alignment](../qa/REVIEW_ACCEPTANCE_ALIGNMENT.md) for the explicit open installation/native-connector deliverables and responsibility split. No internal fixture pass promotes a provider row to live-verified. PrestaShop/OpenCart artifacts and pending native storefront deliverables remain OPEN; the four unsupported connectors remain unsupported. This is separate from the owner-approved affiliate timing and audited workspace concealment policy.

Release gating (2026-11-03): [independent acceptance register](../release/INDEPENDENT_ACCEPTANCE.md) separates missing native artifacts, original-PDF mapping and actual install/order/refund subruns. All NOT RUN rows above remain unchanged. Green backend CI or a regenerated route inventory does not promote provider verification.
