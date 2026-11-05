# CCV Shop native reads and Mijnwebwinkel partner bridge (B72)

## Scope and acceptance

Backend code is implemented; genuine provider acceptance is **NOT RUN**. CCV Shop has a read-only RESTv1 adapter. Mijnwebwinkel has an explicit platform-specific **ShopBonus signed partner bridge**, not a native MyOnlineStore API client. A bridge implementation at the authorized partner is still required. Neither saving keys nor receiving bridge messages marks a connection Connected.

The primary Mijnwebwinkel [partner-access guidance](https://help.mijnwebwinkel.nl/en/articles/999938) requires partner approval and a test shop. Its [public Swagger shell](https://connect.myonlinestore.com/swagger-docs/) was located, but the underlying schema could not be retrieved; direct retrieval returned a Cloudflare challenge. The owner instructed continuation without supplying that specification. No native auth headers, API paths or order fields are invented, and no undocumented native client is silently enabled. Native partner lookup/backfill remains OPEN. The implemented alternative uses ShopBonus's own documented protocol and must be labelled as a bridge.

## CCV Shop

Create a connection through the existing workspace endpoint with `platform: ccv`, `store_url` (bare HTTPS origin), numeric-string `webshop_id`, `api_key` and `api_secret`. Keys remain encrypted and are not echoed. The current verified domain must match the API origin before egress. Native webshop identity, api_root, non-multishop/non-POS state and its SSL domain list are checked before order access. Returned links are not followed as request destinations.

Reads use the [CCV Shop REST documentation](https://demo.ccvshop.nl/API/Docs/), **not the CCV payment-service API**:

- `GET /api/rest/v1/webshops/{id}/` and `/webshops/{id}/domains/` establish the native store binding.
- `GET /api/rest/v1/orders/{id}/` requires the exact ID and a completed order with typed native paid/status fields.
- `GET /api/rest/v1/orders/` uses bounded start/size pagination, fixed ID ordering and creation-date filters. The maximum date deliberately covers the following day; returned UTC timestamps are filtered to the actual requested interval. Foreign/malformed next links and partial/invalid page metadata fail closed; only a validated offset is reused.

Every request signs the exact URI including its encoded query: HMAC-SHA512 over `public|GET|URI||UTC_timestamp`, using `x-public`, `x-date`, `x-hash`. The existing pinned HTTPS reader supplies TLS, SSRF/public-address checks, redirect/retry refusal, timeout/body limits, pause/disconnect guards and cooldown. The new optional server-only credential fingerprint keeps cooldown stable across changing date/signature headers; other readers retain their existing cache keys.

Mappings use native order ID/reference, billing first name, email/phone, exact decimal total and ISO currency, create_date, marked-as-paid boolean and cancellation status8. `paid_at`, cancellation/refund timestamps, refund cash totals, modification time, consent, line items and amount-breakdown facts stay null when not independently reported by this boundary. The paid checkbox is **not settlement proof**. Creation time is never substituted for payment/modification time. Existing ingestion preserves finite payment transitions and unknown values.

Creation-window discovery is explicitly LIMITED backfill: it does not find payment/refund changes to old orders. Those need explicit native order-ID recovery and a future authoritative changed-order/refund contract. No authenticated CCV webhook contract is implemented, so this adapter rejects webhook requests. Polling is opt-in through the existing integration sync configuration. Native payment/refund completeness gates remain unmet; read/configuration success does not bypass them.

## Mijnwebwinkel bridge

Create `platform: mijnwebwinkel` with exact verified `store_domain` and an opaque stable `merchant_reference`. The backend generates a signing secret, shown once, and uses the existing ShopBonus webhook v1 timestamp/body signature, event ID and topic rules. The bridge must include:

```json
{"bridge":{"store_domain":"shop.example.com","merchant_reference":"MWW-12"},"order":{"id":"MWW-4711","reference":"4711","currency":"EUR","total_minor":5995,"customer":{"first_name":"Synthetic","email":"synthetic@example.test"},"payment_status":"pending","created_at":"2026-11-01T12:00:00Z"}}
```

This is the **ShopBonus-owned bridge format**, not a claimed native Mijnwebwinkel response. Only the real authorized partner server should possess the secret. An invalid signature or a foreign merchant/domain binding is rejected before ingestion. The connector preserves reported order/status facts while dropping independent payment time, cash refund, coupon-redemption and contact-consent claims; no native recovery evidence can be manufactured by a bridge event. Stable event IDs make retries use the existing webhook dedupe path. Remove the bridge and revoke its upstream merchant token before disconnecting; the backend never puts partner tokens in storefront JavaScript.

Native MyOnlineStore lookup/discovery, actual partner installation and real financial/consent mapping remain unavailable until a reviewed provider contract and execution evidence exist. The catalogue declares the bridge honestly; its native API flag remains false and seven-gate Connected readiness remains unmet.

## Executed evidence

[Eight actual local HTTP captures](../qa/B72_REMAINING_CONNECTORS_HTTP_CAPTURE.json) cover catalogue, both connection creations/setup data, native connection detail and401/403/404/422 rejection. Reproduce with `python3 scripts/capture-remaining-connectors.py`; private temporary SQLite/storage/key/server are cleaned. Synthetic merchant input is used; no provider request is made. One-time generated signing secrets are redacted. The OpenAPI examples come from these running-server responses.

Tests exercise exact CCV URI signatures, store binding, bounded discovery, current-credential cooldown across changing signatures, paused/unverified connections, foreign order IDs and the bridge's signature/context/unknown-evidence rules. `docs/qa/B72_TEST_RESULTS.json` records results. Main database/shared Redis, real providers and frontend/native storefront files are untouched. No migration is needed.
