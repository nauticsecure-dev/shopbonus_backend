# Seven-gate readiness and authenticated single-order recovery — B23d

`Connected` now requires all seven gates below. A complete signed webhook remains `Limited` until native verification succeeds. Generic signed bridges cannot qualify as native adapters. Passive `test-api` observations remain read-only: they do not ingest, persist readiness or promote status. Registry live-verification and individual coupon/banner capabilities remain separate; passing application tests does not certify a live provider.

| Gate returned by `POST .../connections/{connection}/verify` | Required current evidence |
| --- | --- |
| `authorization_verified` | Latest recovery for this credential generation succeeded through an authenticated native read and canonical processing. |
| `store_identity_matches` | That recovery's verified workspace domain and exact provider store reference still match; connector performs its provider-specific host/shop/scope checks. |
| `genuine_order_observed` | The authenticated native order was actually ingested by the canonical order service, not merely present in a probe. |
| `mandatory_fields_mapped` | That native event's own required mapping is complete; historical retained customer values do not hide a denied/missing field. |
| `payment_verified` | Its native normalized state is paid/refunded/partially refunded with actual payment-time evidence. Authorization/order creation alone fails. |
| `duplicate_processing_prevented` | An identical native snapshot was observed again and reused the original event, without another canonical history/contact effect. |
| `changes_refunds_recoverable` | A native read for the same order recovered a refund (refunded amount increased, or payment status entered partially refunded/refunded) with a strictly newer provider timestamp into the existing canonical order (`native_refund_recovered`). Any other financial or order-state change (`native_change_recovered`), such as a payment, does not pass it. A platform that cannot report refunds (capability `refunds` LIMITED, e.g. Lightspeed C-Series) therefore stays Limited. |

Evidence is generation/domain/store-bound and uses the latest requested native observation. An unfinished or failed latest request cannot borrow a prior success to qualify. A native refund/change snapshot must itself be replayed to demonstrate dedupe before all gates pass. Gate booleans describe available bound proof, not a diagnosis that a missing proof necessarily means invalid credentials. `missing_gates` lists the checks still needed. Complete webhook mapping does not manufacture any native gate.

WooCommerce and Shopify native readers currently support this flow. Shopify protected customer denial stays incomplete. Woo uses gross total and separate native refunds; Shopify uses total before returns plus separate refund total and actual successful transaction times. Changing a store reference after canonical orders exist is rejected with409 `provider_store_change_requires_review`; disconnecting/recreating a connection does not permit silently merging a different store into the same order namespace. Store-reference comparisons are byte-exact on SQLite and MySQL, including case-sensitive installation paths; SQL default collation cannot merge `/Shop` with `/shop`. Ordinary key rotation for the same store remains supported. Unknown historical references cannot qualify as native proof.

Other adapters, bulk missed-order discovery/cursors, detailed product mapping and OAuth remain later #31 boundaries.

## Operator commands

Server console access is privileged. No public recovery mutation endpoint or new auth stack is introduced. Configure approved native read credentials through the existing encrypted connection workflow, verify workspace domain ownership and run:

```bash
php artisan shopbonus:recover-integration-order <CONNECTION_ULID> <NUMERIC_ORDER_ID> --request=<STABLE_REQUEST_UUID>
```

The request UUID binds permanently to the connection generation and order ID. Reusing it returns the original operation; another order with that UUID returns `recovery_request_conflict`. Omit it to create and print a new UUID. A failed request remains failed; after investigating/configuring the cause, use a new request UUID for another actual observation. Never send credentials as command arguments or in chat.

The command enqueues a fixed native read. It **can update canonical orders, voucher matching and lawful contact planning through the existing pipeline**; it is not the passive test-api probe. Existing payment/consent/idempotence/dispatch rules remain authoritative. No arbitrary wallet balancing, affiliate policy change or provider write is added.

For a controlled test order, recover a paid snapshot, repeat with a new request UUID to prove native dedupe, then recover an actual later change/refund that was not already applied to the canonical order and repeat that snapshot. The API reports actual evidence; do not fabricate state changes or pass gate booleans from a merchant client. Production acceptance must use genuine authorized provider data, not the fixtures used by the test suite.

```bash
php artisan shopbonus:recover-integration-order --drain --limit=25
```

These gates report observed evidence, not continuous provider uptime; bulk sync-age/provider monitoring is still later work.

The every-minute scheduler drains only existing pending/staged/expired-reading requests, at most25 by default (hard100). It never discovers stores or creates unsolicited provider requests. A queue worker and scheduler are required. The queue job has a55-second timeout, a60-second read lease and three maximum claimed attempts for interrupted reads; deployment queue retry-after must exceed the lease (existing default90seconds). Native requests use existing bounded pinned TLS transport and provider cooldown. A live lease excludes another worker; an expired/changed lease cannot stage a late result. HTTP/provider failures persist static error codes and require investigation/new request rather than pretending success.

## Durable ingestion and security

`integration_order_recoveries` stores operation identity, generation/domain/exact provider-store snapshot, bounded lease/attempts, terminal error and event reference; no customer payload or credential. Native payloads use the existing encrypted event table, explicit `source_method=native_api` and stable generation+canonical-payload-hash dedupe. Signed intake writes `source_method=webhook`; older unknown provenance remains null. Provider webhook delivery dedupe remains unchanged across rotations.

All network reads happen outside database row locks. Claim/stage/processing serialize by locking connection before operation/event. Before staging, generation, encrypted credential snapshot, verified domain, exact store reference and lease owner/expiry must still match. Stale work becomes superseded or remains uncommitted for the current lease owner; it cannot affect orders or readiness. Processing rechecks domain/generation, reuses `OrderEventVerified` and the existing canonical ingestion/listeners atomically, and marks the durable recovery succeeded only with processing. It does not create another order/ledger stack.

A staged request survives lost dispatch and drains again without a second API read. Processed native snapshots are reused without another order event. Invalid/pruned/failed staged events terminate explicitly; the processor's final-failure hook closes associated staged requests, preventing endless recovery requeue. Original event hashes/safe results and append-only canonical order histories remain source references; normal encrypted-payload retention still applies.

The existing manage/view permissions, tenant scoping, CSRF, support-session guards, throttle and audit remain on existing APIs. Operator requests emit `integration.order_recovery_requested`; failures emit `integration.order_recovery_failed` with fixed code only. Connection status transitions list missing gates in the chained audit. Passive probe success continues to return `state_changed:false` and `production_verified:false`.

## Migration and evidence

Migrations `2026_10_28_160000_create_integration_order_recoveries.php` and `2026_10_28_170000_bind_recoveries_to_provider_store.php` add source provenance, the durable request table and an exact provider-store snapshot. The first downgrades historical `Connected` rows to `Limited`, clears their connected timestamp and preserves other limitations while adding `connection_quality_gates_pending`. Historical status does not establish native proof. No legacy source/store-reference backfill is invented. Down first downgrades current Connected rows and clears their connected timestamp before removing readiness evidence; existing limitations survive. Neither rollback nor reapply restores an unproved Connected state.

Quiesce old intake/queue workers and preserve outstanding native requests/events before rollout or rollback. Deploy code and schema together; old processors use the superseded promotion rule. No production deployment/rollback is claimed. [Isolated SQLite/MySQL proof](B23_READINESS_MIGRATION_PROOF.json) applies full schemas, executes both exact targets down/up twice, preserves original encrypted event payload/unknown source and existing limitations, and confirms downgrade/rollback behavior. Local main application is recorded in handoff.

[Actual HTTP captures](B23_READINESS_HTTP_CAPTURE.json) include full progression, unchanged passive probe, failure downgrade, rotation reset,401 and500. They use a real PHP HTTP server with isolated SQLite/database cache/file sessions, normal login/CSRF and actual operator command/canonical worker, with synthetic upstream HTTP/DNS and an isolated table-rename fault. They are application contract evidence, never live-provider acceptance. `OrderRecoveryTest` covers Woo and Shopify positive paths, replay/request conflict, credentials/domain invalidation, active/expired lease and late result, unpaid/missing fields, authentication failure, staged recovery and processor failure. Existing webhook tests now assert Limited from complete webhooks, matching #31's stronger requirement.

Remaining #31 work: OAuth install/refresh, durable bulk native synchronization/missed-new-order discovery, other native providers and detailed line-item/catalog mappings; genuine platform installation/protected-data/storefront/banner/coupon acceptance. D-089 spendability remains unchanged.
