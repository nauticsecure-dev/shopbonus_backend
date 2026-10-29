# Credential-bound event lifecycle — B23c / #31

> Current status policy is documented in [READINESS_AND_RECOVERY.md](READINESS_AND_RECOVERY.md): all seven native gates are required for Connected. Earlier checkpoint limitations below are historical.

This boundary adds credential-version binding to the existing connection, webhook receiver, event processor and merchant/admin retry paths. It is the foundation for durable seven-gate readiness. It does not implement the full gate policy, OAuth or native reconciliation. Existing complete-current-webhook status behavior remains compatible until that next boundary; `connected` alone is not proof of all #31 gates.

Each connection has an internal positive `credential_generation` (initially1). Every newly accepted event stores the generation that verified its signature. These internal fields are hidden from resource/model JSON. The existing connection-wide provider dedupe key remains unchanged across rotation, preventing an old delivery from becoming a fresh event simply because credentials changed.

Credential replacement locks and reloads the current connection, validates it is active, writes encrypted credentials and advances the generation. It resets status to `setup_required`, current limitations, connected/event/order timestamps and current errors. Historical encrypted events, orders and source histories remain unchanged. Disconnect locks the same row, advances generation, removes credentials and clears readiness timestamps. A stale credential writer cannot resurrect a disconnected connection.

Signature verification happens before payload storage. Intake then locks/reloads the connection and checks both generation and encrypted credential snapshot before storing anything or recording current receipt. Rotation during verification returns409 `connection_changed`; disconnect returns404. Signature rejection metadata is also generation-bound so an old request cannot overwrite the new connection's error state. The per-minute rejection throttle includes generation.

Processing, intake and both retry paths lock the connection before the event. A queued event whose generation is null/stale, whose workspace differs, or whose connection is disconnected is marked `ignored` / `credential_generation_stale` before order/refund dispatch or contact effects. Its encrypted payload remains retained under the normal retention policy; `integration.event_stale` uses the existing audit trail. Already processed events remain idempotent and are not relabelled. Merchant and admin retries return409 `event_not_retryable` for stale/unbound proof, even if payloads are retained. The admin path still requires Super Admin, fresh password, emailed OTP, MFA and normal CSRF/support guards.

Verification reloads/locks the connection and considers only current-generation accepted events and current-generation processed order summaries. Old events cannot satisfy current event/order/mandatory-field checks. Native API probes also reject a generation change before returning success; they still do not persist readiness or promote status.

## Migration and deployment

`2026_10_28_150000_bind_webhooks_to_credential_generation.php` adds two columns, no package or financial changes. Existing connections get initial generation1. Existing event generation stays null: there is no reliable historical credential binding, so the migration deliberately does not invent one. Existing historical status/timestamps remain historical; this migration does not certify them against seven gates. Verification cannot use those unbound events.

Quiesce webhook intake and old queue workers during deployment; deploy code and migration together before resuming workers/intake. Mixed old receivers omit generation and old workers lack the new checks. Legacy pending/unbound or rotated events require fresh provider evidence through subsequent authenticated native reconciliation or a new signed delivery; retries must not manufacture a credential binding. Permanent dedupe means reusing an old delivery ID is not a recovery mechanism. Old events and canonical orders are retained for investigation.

Rollback drops binding evidence. Preserve it and quiesce processing/intake before any approved rollback; reverting to old processing code reintroduces the historical lifecycle risk. No production rollback was performed. [Isolated SQLite/MySQL proof](B23_CREDENTIAL_MIGRATION_PROOF.json) applies the full schema, executes exact target down/up and verifies old event bindings become null while encrypted payload survives. Full suites separately cover normal migration from empty databases. Main local application is recorded in the handoff; no production deployment is claimed.

## Evidence and remaining work

`CredentialLifecycleTest` reproduces queued-old-event rejection, readiness reset, stale writer after disconnect, legacy/old merchant and admin retries, rotation between real signature verification and persistence, and permanent dedupe across rotation with fresh-delivery processing. The intake race is a deterministic interleaving, not a claimed multi-process lock-contention test. Existing order/webhook/admin/native tests cover compatibility and security constraints.

[16 actual running-server HTTP captures](B23_CREDENTIAL_HTTP_CAPTURE.json) show before/after verification, rotation, ignored history, merchant/admin stale retries, intake409 and disconnected404. Isolated SQLite/database cache/file sessions, normal login+CSRF+password confirmation and synthetic seeded OTP; MFA disabled only in the capture fixture, mail array, no live provider. Queue uses the isolated database; the fixture worker invokes the actual processor. The intake-race hook is explicitly synthetic. No real credential/financial/mail/provider setting was changed.

Next: durable, credential-bound seven-gate evidence and safe status promotion; actual native store/order/payment identity and tested change/refund recovery; OAuth lifecycle; detailed line-item mappings and remaining native providers. Real provider/install/storefront acceptance remains NOT RUN. D-089 spendability policy remains unchanged.
