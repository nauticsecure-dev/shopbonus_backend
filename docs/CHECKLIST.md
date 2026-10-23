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

- [x] `audit_events` migration: append-only, request ID, actor and real actor, webshop, action, resource, outcome, HTTP status, hashed IP, redacted metadata (`088fb20`)
- [x] DB-level guard against UPDATE on `audit_events` (MySQL and SQLite triggers) (`088fb20`)
- [x] `AuditEvent` model refuses update and delete (`088fb20`)
- [x] `AuditLogger` service (`record` throws; `recordSafely` never breaks the request) (`088fb20`)
- [x] 401/403 API responses audited automatically with request ID and route template (`088fb20`)
- [x] An audit write failure never breaks the API response (`088fb20`)
- [x] `application_errors` migration + `ApplicationError` model (`088fb20`)
- [x] Unexpected exceptions (5xx) recorded to `application_errors`, redacted; SQL bindings never stored (`088fb20`)
- [x] Intentional `ApiException`s and 4xx not recorded as application errors (`088fb20`)
- [x] Failed queue jobs recorded with job name, queue, connection and originating request ID (`088fb20`)
- [x] Alert hook event dispatched for recorded errors (`088fb20`)
- [x] Tests on SQLite and MySQL; Pint + PHPStan clean (`088fb20`)
- [x] Docs: PROGRESS, HANDOFF, TASKS, DECISIONS updated (`088fb20`)

## B02 Identity and consent (Trello #4; consent parts of #5 #10)

Auth mode decided by card #4: Fortify + Sanctum stateful SPA cookies (D-011).

- [x] Fortify headless (no views); only registration, password reset and password update enabled; 2FA/passkeys/profile-update disabled with reasons (B02 commit)
- [x] `users` extended: `first_name`, `public_id` (ULID), `status`, `onboarding_intent`, `locale`, `last_login_at`; email stored lower-case with a DB unique index (B02 commit)
- [x] Registration: account type, first name, email, password strength rules, required Terms + Privacy acceptance at the current version, optional marketing opt-in, locale; client-sent role/admin fields ignored (B02 commit)
- [x] Concurrent and case-variant duplicate signups create exactly one user (B02 commit)
- [x] `legal_acceptances`: immutable record of document type, version, time and minimal evidence (B02 commit)
- [x] `consent_records`: append-only marketing consent history, separate from mandatory account mail (B02 commit)
- [x] Email OTP: `email_verification_challenges`, HMAC-hashed 6-digit code, 10-minute expiry, 5 attempts, single use, atomic consumption, resend supersedes (B02 commit)
- [x] OTP mail queued, payload encrypted, NL/EN, sent on registration; code never logged (B02 commit)
- [x] `POST /api/v1/auth/email/verify-code` and `/resend-code` with per-user+IP throttles (429) (B02 commit)
- [x] Unverified accounts limited to me/verify/resend/logout; `verified` guard returns 403 `email_not_verified` (B02 commit)
- [x] Suspended/deleted accounts blocked on every API request and existing sessions; login refused with the generic error (B02 commit)
- [x] `GET /api/v1/me`: minimal identity, status, roles (empty until B03) and server-computed destination (B02 commit)
- [x] Login: active users only, generic failure, throttle per email+IP, session ID rotation, `last_login_at` (B02 commit)
- [x] Forgot password: identical response for known, unknown and throttled emails; reset link points to FRONTEND_URL (B02 commit)
- [x] Password reset works once; expired/reused token fails; other sessions and API tokens invalidated (B02 commit)
- [x] Logout invalidates the session; protected GET afterwards returns 401 (B02 commit)
- [x] Sanctum stateful API, cookie flags (Secure/HttpOnly/SameSite) via env, CSRF 419 tested with enforcement on; origin mismatch returns 403 `origin_not_allowed` (B02 commit)
- [x] Identity audit events (registered, login succeeded/failed, lockout, logout, code sent/failed, email verified, reset requested, password reset) (B02 commit)
- [x] Access registry: every route declares `x-access` in OpenAPI; test checks its middleware matches (default deny) (B02 commit)
- [x] Docs: every new endpoint (Fortify ones too) with real captured request/response (B02 commit)
- [x] Tests mapping card #4 E2E cases 1, 2, 4, 5, 6, 7, 8, 10. Case 3 (memberships) moves to B03; case 9 is frontend-owned (B02 commit)
- [x] Local timing of `GET /api/v1/me` and signup measured and recorded (staging p95 NOT RUN; see PROGRESS) (B02 commit)
- [x] Docs: PROGRESS, HANDOFF, TASKS, DECISIONS updated (B02 commit)
- [x] Found during capture and fixed: guest-only routes now 409 for signed-in JSON clients; Fortify session routes re-check account status; intentional 5xx never include debug data (B02 commit)
- [ ] Staging p95 for `/me` (<250 ms) and signup (<400 ms) (blocked: no staging environment)
- [ ] Super Admin/owner MFA (moved to B16 by design; Fortify 2FA re-enabled there)
- [ ] Secured email-change re-verification flow (moved to B08)

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
