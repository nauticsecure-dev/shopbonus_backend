# Provider verification checklist

Every platform in `config/integrations.php` stays `verification: unverified` until someone with real store or sandbox access runs this checklist and records the evidence below. Adapters were written against the providers' published documentation and are tested with realistic fixtures (`tests/Unit/Integrations/ConnectorTest.php`, `tests/Feature/Integrations/ConnectionsAndWebhooksTest.php`). That proves the logic, **not** the live provider behaviour. Never mark a platform verified from fixtures (Trello #1, OPEN-PROVIDER-CAPABILITIES).

## Per platform

1. Create a connection in a staging workspace (`POST /api/v1/workspaces/{w}/connections`) and configure the webhook in the real store using the returned instructions.
2. **Ping / setup event**: note whether the store sends one and whether it is signed. Expected: the connection moves to `testing`.
3. **Genuine paid order** (real payment method in test mode): the connection must become `connected`. Record the event's `result` (`GET .../events`), with no PII in the record.
4. **Signature**: alter one byte of a captured body and resend it. It must get 401. Resend the original unchanged: 200 `duplicate: true`.
5. **Retries**: make the endpoint fail once (e.g. stop the worker) and confirm the provider retries with the same delivery/event ID.
6. **Payment states**: create pending, failed and cancelled orders. Confirm the mapped `payment_status` never reads `paid` for them.
7. **Refund**: full and partial. Confirm the topics received and the mapped status.
8. **Field availability**: which of reference, first name, email, amount, currency, phone are present, and which are redacted (Shopify protected customer data).
9. **Rate and timing**: note the provider's timeout and retry schedule against our 202 response time.
10. Record the results in the table below and set `verification` in `config/integrations.php` to `verified:<date>` (or list the limitations) in the same commit.

## Evidence log

| Platform | Date | Store/sandbox | Steps passed | Limitations found | Verified by | Commit |
| --- | --- | --- | --- | --- | --- | --- |
| WooCommerce | — | — | NOT RUN | — | — | — |
| Shopify | — | — | NOT RUN | — | — | — |
| Shopbonus format v1 (module) | — | — | NOT RUN (no module built yet) | — | — | — |

## Platforms without an adapter

Wix, BigCommerce, Ecwid, Lightspeed C/E, Squarespace, Mijnwebwinkel and CCV: see `blocked_reason` in `config/integrations.php`. Each needs its authentication mechanism verified (and usually API credentials) before an adapter is written.
