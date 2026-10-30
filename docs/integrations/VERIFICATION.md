# Provider verification checklist

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
| Shopify | — | — | NOT RUN | Protected customer data / theme extension pending | — | — |
| WooCommerce | — | — | NOT RUN | Native WP banner publish pending | — | — |
| Wix | — | — | NOT RUN | No search backfill; paid_at null | — | — |
| BigCommerce | — | — | NOT RUN | Widgets/catalog not implemented | — | — |
| Ecwid | — | — | NOT RUN | Currency/settlement time unavailable | — | — |
| Lightspeed C-Series | — | — | NOT RUN | Plan-gated API; refund cash unmapped; paid_at null | — | — |
| PrestaShop | — | — | NOT RUN (module ZIP not shipped) | Module install/uninstall external | — | — |
| OpenCart | — | — | NOT RUN (extension not shipped) | Bridge-only; no native order read | — | — |
| Shopware 6 | — | — | NOT RUN | Native app lifecycle separate from HMAC bridge | — | — |
| Adobe Commerce / Magento | — | — | NOT RUN | Prefer async I/O Events; SaaS unverified | — | — |
| Shopbonus format v1 (module) | — | — | NOT RUN (no module built yet) | — | — | — |

## Platforms without a connector

Lightspeed E-Series, Squarespace, Mijnwebwinkel and CCV: see `blocked_reason` in `config/integrations.php`. Each needs its authentication mechanism verified before an adapter is written. **Do not** substitute Retail/E-Series for C-Series.

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
