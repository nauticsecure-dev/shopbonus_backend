# Cockpit backend contract and handoff — 2026-11-03

Scope: Laravel API only, Trello#21. No frontend files changed. [Actual HTTP archive](../qa/B54_B55_ADMIN_BACKEND_HTTP_CAPTURE.json) contains41 exchanges with synthetic business fixtures, plus database assertions proving token revocation and attributed denial auditing. No live-provider/browser acceptance is implied.

## Request-time impersonation

Every support-token request checks the initiating administrator against current database records: active account, verified email, unrevoked Super Admin role and confirmed two-factor authentication when the admin MFA gate requires it. Previously loaded user/role relations cannot authorize. Lost authority closes the session with `ended_reason=admin_authority_lost`, deletes its token and returns401 `support_session_ended`. Invalid token/session target binding is also rejected and revoked. Ordinary bearer tokens are unaffected; financial/security/admin restrictions during impersonation remain. The ownership-transfer write route family is now explicitly blocked too, including cancellation.

The denied request retains the target actor and initiating real administrator in the audit. Reinstating the administrator cannot resurrect a revoked token. A deterministic regression also covers authority loss after token resolution but before final authorization, ensuring both session closure and audit. Start also re-reads/locks the administrator and target before issuing a token. This guards against callers that bypass normal lifecycle cleanup or reuse stale model objects. Persisted lifecycle reporting remains separate from live authorization: invalid authority is closed/audited on token use; timer-based list status is not authorization proof.

No schema migration or new endpoint. Existing expired/ended session behavior, support-context cleanup and scoped user permissions remain. Server validation occurs at request authorization; this does not promise cancellation of a request already executing when authority changes concurrently.

## Existing detailed overview and Today

`GET /api/v1/admin/cockpit?period=today` already returns the current business-calendar day in `data.timezone` (configured Europe/Amsterdam), not the browser's timezone. Other periods remain7d/30d/90d/custom with from/to. `data.cards` contains six headlines; **full business metrics are already in `data.kpis`**:

| Requirement | Response field | Definition |
| --- | --- | --- |
| Orders received/paid | `orders.received_in_period`, `orders.confirmed_paid_in_period` | Created-in-period records / records with recorded paid_at in the period; not net revenue or inferred cash settlement |
| Answer24 requests/results | `answer24.requests_in_period`, `answer24.by_status` | Opportunities created in period grouped by currently recorded status; not invented contact outcomes |
| Inventory | `vouchers.available_codes`, `vouchers.outstanding` | Available inventory status / currently usable vouchers, as defined by the server |
| Points issued/spent | `wallets.points.issued_in_period`, `wallets.points.spent_in_period` | Consumer points ledger credits / absolute debits in period |
| Points outstanding | `wallets.points.outstanding`, `available`, `reserved`, `pending` | Available+reserved; pending reported separately |
| EUR credit | `wallets.eur_credit.*` | Separate unit, never converted to points |
| Disputes | `disputes.open` | Current open/under_review/waiting_for_merchant cases |

Snapshot inventory/disputes are current counts, not invented historical comparisons. Read `definitions`, `sources`, `warnings`, `generated_at` and `cache_seconds`; degraded data is not zero or healthy. A unit may be absent when there are no records; do not conflate this with source failure. Do not relabel six headline cards as the complete metric inventory. The capture proves nonzero paid-order, Answer24, inventory, points issuance/spend and dispute fields with synthetic records; real provider outcomes still need their own evidence.

## Pagination and webshop drill-down

| Endpoint | Paging | Webshop filter |
| --- | --- | --- |
| `/api/v1/admin/users` | `page`, `per_page`; `meta` | Existing canonical `webshop_id` |
| `/api/v1/admin/webshops` | `page`, `per_page`; `links`, `meta`; links retain search/status query | Existing list search/status filters |
| `/api/v1/admin/vouchers` | `page`, `per_page`; `meta` | New canonical `webshop_id` or alias `webshop` |
| `/api/v1/admin/activity` | `page`, `per_page`; `meta` | Existing `webshop_id`, new alias `webshop` |

`page` must be a positive integer. `per_page` is1–100, default25. Page2 and later are accessible; activity is not capped to its first50 records by the backend. Vouchers/activity use exact public webshop ULIDs before pagination. Case-normalized matching aliases are allowed; differing aliases or malformed ULIDs return422. A valid unknown webshop returns200 with an empty list, never an unfiltered fallback. Existing voucher status/date/network/usable and activity category/outcome/action filters remain additive. These endpoints require global Super Admin authority and configured MFA; ordinary merchants receive403 before list data is exposed.

Frontend work still belongs to Hassan: expose Today, render the remaining `kpis`, add pagination controls and forward the selected browser `webshop` value into each API request (prefer canonical `webshop_id`). Backend support for an alias cannot read browser state that the client never sends. No claim is made that the current screens now show later records or retain a selected webshop.
