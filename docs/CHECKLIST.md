# Implementation Checklist

Granular companion to `docs/TASKS.md`. **Rule (owner, 2026-10-23):** tick a box in the same commit that implements the item, and only once its tests have actually been run and passed. Put the commit SHA (or "this commit") after each ticked item. An unticked box means not done or not verified. Never tick from intent.

Tasks further down are listed at headline level. Expand them into concrete items when the task is started, before writing code.

## B00 Repository audit

- [x] Inventory of existing code, repos and runtime (`7cc47a8`; see PROGRESS.md)
- [x] Codebase decision recorded: greenfield, direct to main, MySQL (D-001..D-003)

## B01a Foundation: API skeleton

- [x] Laravel 13 + Sanctum scaffold, frontend tooling removed (`48520b7`)
- [x] `/api/v1` route group with configurable `throttle:api` (`a51a9b6`)
- [x] Standard error envelope 400–503 with `request_id`, no internal leakage (`a51a9b6`)
- [x] `ApiException` for domain errors (`a51a9b6`)
- [x] Request ID middleware: safe client reuse, UUIDv7 fallback, logged via Context (`a51a9b6`)
- [x] Force JSON on API, 401 instead of login redirect (`a51a9b6`)
- [x] Health liveness + readiness (DB/cache/Redis), detail gated by token (`a51a9b6`)
- [x] Log redaction on all channels (`a51a9b6`)
- [x] CORS allowlist from env (`a51a9b6`)
- [x] Pint, Larastan level 6, CI workflow file (`a51a9b6`)
- [ ] CI actually run green on GitHub (blocked: no git remote)
- [x] OpenAPI 3.1 + API reference with real captured examples; coverage test (`a51a9b6`)

## B01b Foundation: audit and error store

- [x] `audit_events` migration: append-only, request ID, actor and real actor, webshop, action, resource, outcome, HTTP status, hashed IP, redacted metadata (B01b commit)
- [x] DB-level guard against UPDATE on `audit_events` (MySQL and SQLite triggers) (B01b commit)
- [x] `AuditEvent` model refuses update and delete (B01b commit)
- [x] `AuditLogger` service (`record` throws; `recordSafely` never breaks the request) (B01b commit)
- [x] 401/403 API responses audited automatically with request ID and route template (B01b commit)
- [x] An audit write failure never breaks the API response (B01b commit)
- [x] `application_errors` migration + `ApplicationError` model (B01b commit)
- [x] Unexpected exceptions (5xx) recorded to `application_errors`, redacted; SQL bindings never stored (B01b commit)
- [x] Intentional `ApiException`s and 4xx not recorded as application errors (B01b commit)
- [x] Failed queue jobs recorded with job name, queue, connection and originating request ID (B01b commit)
- [x] Alert hook event dispatched for recorded errors (B01b commit)
- [x] Tests on SQLite and MySQL; Pint + PHPStan clean (B01b commit)
- [x] Docs: PROGRESS, HANDOFF, TASKS, DECISIONS updated (B01b commit)

## B02 Identity and consent (Trello #4 #5 #10)

- [ ] Expand checklist (decide Sanctum SPA cookie vs token vs both)

## B03 Workspaces and onboarding (Trello #2 #3 #16 #9)

- [ ] Expand checklist (includes roles/permissions pattern and workspace-resolution middleware moved from B01)

## B04–B19

- [ ] B04 Ecommerce provider contracts
- [ ] B05 Orders and state transitions
- [ ] B06 Offers and code inventory
- [ ] B07 Answer24 handoff/claim
- [ ] B08 Consumer profile/discovery
- [ ] B09 Wallet ledger core
- [ ] B10 Affiliate integrations (credit timing decision required)
- [ ] B11 Voucher checkout/Mollie
- [ ] B12 Merchant wallet/funding
- [ ] B13 Merchant analytics
- [ ] B14 Campaign/banner backend
- [ ] B15 CMS/legal/cookie API
- [ ] B16 Super Admin APIs
- [ ] B17 Customer support backend
- [ ] B18 E2E/security/load/release
- [ ] B19 Integration marketplace listing (BLOCKED: empty card)
